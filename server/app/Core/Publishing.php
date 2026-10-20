<?php

declare(strict_types=1);

namespace App\Core;

use App\Jobs\PublishDelivery;
use App\PrivateFiles;
use App\Social\ImageAttachment;
use App\Social\Publisher;
use App\Social\SessionStore;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Queue\Queue;
use Crab\Database\DatabaseException;

/**
 * Posts and their deliveries. Every front door, the CLI and the HTTP API,
 * goes through here, so the rules against publishing twice live in one place.
 */
// Keep durable before/after side-effect writes together so retry behavior is visible.
// @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods
final readonly class Publishing
{
    private const string ACCOUNTS = 'postslop.accounts';
    private const array EDITABLE = ['draft', 'scheduled'];
    // A delivery waiting longer than this was never picked up, so it is failed: nothing was sent.
    private const int STALE_SECONDS = 900;
    private const array EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * @param array<string, Publisher> $publishers
     * @param array<string, string> $localData Folders an account keeps besides its session, such as X's browser
     *     profile, removed with it.
     */
    // The core is built once, from every service it uses.
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        private array $publishers,
        private Database $db,
        private Cache $cache,
        private Queue $queue,
        private string $lockFile,
        private string $mediaDirectory,
        private SessionStore $sessions,
        private array $localData = [],
    ) {}

    /**
     * Checks every session again instead of answering from the last 30 seconds.
     */
    public function refreshAccounts(): array
    {
        $this->cache->delete(self::ACCOUNTS);
        return $this->accounts();
    }

    public function accounts(): array
    {
        // A command line run is its own process, so the check is shared through the cache.
        return $this->cache->remember(self::ACCOUNTS, 30, function (): array {
            $platforms = [];
            foreach ($this->publishers as $id => $publisher) {
                $account = $this->sessions->summary($id);
                $extra = [
                    'enabled' => $account['enabled'],
                    'connected' => $account['connected'],
                    'importedAt' => $account['importedAt'],
                    'source' => $account['source'],
                    'maxImages' => Platforms::maxImages([$id]),
                    'textLimit' => Platforms::TEXT_LIMITS[$id] ?? null,
                ];
                // An account without a session, or turned off, is not checked: it is not used as it is.
                if (!$account['connected'] || !$account['enabled']) {
                    $platforms[] = [
                        'id' => $id,
                        'label' => Platforms::LABELS[$id] ?? $id,
                        'available' => false,
                        'handle' => $account['handle'],
                        'reason' => $account['connected']
                            ? 'Turned off in Postslop.'
                            : 'Not connected. Run: postslop setup ' . $id,
                        ...$extra,
                    ];
                    continue;
                }
                try {
                    $status = $publisher->status();
                } catch (\Throwable) {
                    $status = [
                        'id' => $id,
                        'label' => $id,
                        'available' => false,
                        'reason' => 'Could not verify this session. Reimport it and try again.',
                    ];
                }
                // A session that cannot sign in still shows the handle it was imported for.
                $status['handle'] = is_string($status['handle'] ?? null)
                    ? ltrim($status['handle'], '@')
                    : $account['handle'];
                $platforms[] = [...$status, ...$extra];
            }
            return [
                'platforms' => $platforms,
                'limits' => ['maxImages' => PostRequest::MAX_IMAGES, 'maxImageBytes' => PostRequest::MAX_IMAGE_BYTES],
                'publishingEnabled' => true,
            ];
        });
    }

    /**
     * The sites a post goes to when none are named: those with a saved session that are turned on. Nothing
     * remote is checked, so it is quick.
     *
     * @return list<string>
     */
    public function defaultPlatforms(): array
    {
        return array_values(array_filter(Platforms::IDS, function (string $platform): bool {
            $account = $this->sessions->summary($platform);
            return $account['connected'] && $account['enabled'];
        }));
    }

    /**
     * Turns an account on or off. A turned-off account keeps its session, is left out of the composer, and any post
     * still addressed to it fails for that site without sending anything.
     */
    public function setEnabled(string $platform, bool $enabled): array
    {
        self::platform($platform);
        $this->sessions->setEnabled($platform, $enabled);
        return $this->refreshAccounts();
    }

    /**
     * Forgets an account: its session and anything it kept on disk. Its posts stay in the history.
     */
    public function removeAccount(string $platform): array
    {
        self::platform($platform);
        $this->sessions->remove($platform);
        $folder = $this->localData[$platform] ?? null;
        if ($folder !== null) {
            self::removeTree($folder);
        }
        return $this->refreshAccounts();
    }

    /**
     * Saves one account's handle and browser session, as `postslop setup` imports it or as another machine sends
     * it, then checks it signs in as that handle.
     */
    public function importAccount(string $platform, mixed $handle, mixed $session, string $source): array
    {
        self::platform($platform);
        $handle = is_string($handle) ? ltrim(trim($handle), '@') : '';
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $handle) !== 1) {
            throw Failure::invalid('Give the account’s handle: letters, numbers, dots, underscores or hyphens.');
        }
        if (
            !is_array($session)
            || !array_is_list($session['cookies'] ?? null)
            || !array_is_list($session['origins'] ?? [])
        ) {
            throw Failure::invalid(
                'The session must hold the browser’s cookies and origins, as postslop setup reads them.',
            );
        }
        $userAgent = $session['userAgent'] ?? null;
        $this->sessions->import(
            [
                $platform => [
                    'handle' => $handle,
                    'session' => [
                        'cookies' => $session['cookies'],
                        'origins' => $session['origins'] ?? [],
                        // The browser's own User-Agent, for a site that checks a session comes from the same browser.
                        ...(
                            is_string($userAgent) && preg_match('/^[\x20-\x7E]{1,300}$/D', $userAgent) === 1
                                ? ['userAgent' => $userAgent]
                                : []
                        ),
                    ],
                ],
            ],
            $source,
        );
        return $this->refreshAccounts();
    }

    /**
     * Saves the post as a draft, schedules it, or publishes it now. Repeating a
     * request ID returns what was recorded and never sends it again.
     */
    public function create(PostRequest $request): array
    {
        $existing = $this->row($request->requestId);
        if ($existing !== null) {
            if (!hash_equals($existing['fingerprint'], $request->fingerprint)) {
                throw Failure::conflict('This requestId belongs to a different post.');
            }
            if ($request->mode === 'now' && in_array($existing['status'], self::EDITABLE, true)) {
                return $this->publish($request->requestId);
            }
            return $this->find($request->requestId);
        }
        try {
            $this->insert($request);
        } catch (DatabaseException $error) {
            // Another process created this request ID first; answer as for any repeat.
            if ($this->row($request->requestId) === null) {
                throw $error;
            }
            return $this->create($request);
        }
        return $request->mode === 'now' ? $this->publish($request->requestId) : $this->find($request->requestId);
    }

    private function insert(PostRequest $request): void
    {
        $this->db->transaction(function (Connection $tx) use ($request): void {
            $now = self::now();
            $tx->table('posts')->insert([
                'id' => $request->requestId,
                'text' => $request->text,
                'platforms' => json_encode($request->platforms, JSON_THROW_ON_ERROR),
                'fingerprint' => $request->fingerprint,
                'status' => $request->mode === 'schedule' ? 'scheduled' : 'draft',
                'scheduled_at' => $request->scheduledAt?->format('Y-m-d\TH:i:s\Z'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->storeImages($tx, $request->requestId, $request->images);
        });
    }

    /**
     * Replaces a draft's or scheduled post's content. Sent posts are history and stay as they were.
     */
    public function update(string $id, PostRequest $request): array
    {
        $this->editable($id);
        $this->db->transaction(function (Connection $tx) use ($id, $request): void {
            $tx
                ->table('posts')
                ->where('id', $id)
                ->update([
                    'text' => $request->text,
                    'platforms' => json_encode($request->platforms, JSON_THROW_ON_ERROR),
                    'fingerprint' => $request->fingerprint,
                    'status' => $request->mode === 'schedule' ? 'scheduled' : 'draft',
                    'scheduled_at' => $request->scheduledAt?->format('Y-m-d\TH:i:s\Z'),
                    'updated_at' => self::now(),
                ]);
            $this->removeImages($tx, $id);
            $this->storeImages($tx, $id, $request->images);
        });
        return $request->mode === 'now' ? $this->publish($id) : $this->find($id);
    }

    /**
     * As create(), except that a post to publish now is handed to the queue and answered at once as queued,
     * so a client never waits on the sites. Repeating the request still never sends it twice.
     */
    public function createQueued(PostRequest $request): array
    {
        return $request->mode === 'now'
            ? $this->queue($this->create($request->as('draft'))['id'])
            : $this->create($request);
    }

    /**
     * As update(), except that publishing now is handed to the queue, as in createQueued().
     */
    public function updateQueued(string $id, PostRequest $request): array
    {
        return $request->mode === 'now'
            ? $this->queue($this->update($id, $request->as('draft'))['id'])
            : $this->update($id, $request);
    }

    /**
     * Hands each destination of a draft or a scheduled post to its site's own queue, in one transaction, so every
     * job exists exactly when its delivery does. The sites then send at the same time, each one post after
     * another. A post already on its way is returned as it is.
     */
    public function queue(string $id): array
    {
        $this->db->transaction(function (Connection $tx) use ($id): void {
            foreach (self::claim($tx, $id) as $platform) {
                $this->queue->within($tx)->on($platform)->push(PublishDelivery::class, id: $id, platform: $platform);
            }
        });
        return $this->find($id);
    }

    /**
     * Schedules a draft, moves a scheduled post, or with null returns it to the drafts.
     */
    public function schedule(string $id, ?\DateTimeImmutable $at): array
    {
        $this->editable($id);
        if ($at !== null && $at->getTimestamp() < (time() - 60)) {
            throw Failure::invalid('The scheduled time has already passed.');
        }
        $this->db
            ->table('posts')
            ->where('id', $id)
            ->update([
                'status' => $at === null ? 'draft' : 'scheduled',
                'scheduled_at' => $at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => self::now(),
            ]);
        return $this->find($id);
    }

    /**
     * Deletes a draft or a scheduled post. Sent posts stay in the history.
     */
    public function delete(string $id): void
    {
        $this->editable($id);
        $this->db->transaction(function (Connection $tx) use ($id): void {
            $this->removeImages($tx, $id);
            $tx->table('posts')->where('id', $id)->delete();
        });
    }

    /**
     * Sends a draft or a scheduled post to each of its destinations, once, in this process: the command line's
     * --now. Each site is still sent one post at a time, beside the queue's workers.
     */
    public function publish(string $id): array
    {
        $platforms = $this->db->transaction(static fn(Connection $tx): array => self::claim($tx, $id));
        foreach ($platforms as $platform) {
            $this->deliver($id, $platform);
        }
        return $this->find($id);
    }

    /**
     * Sends again only to destinations that failed. An unknown result is never
     * retried: the post may already be live.
     */
    public function retry(string $id): array
    {
        $failed = $this->db->transaction(static function (Connection $tx) use ($id): array {
            $post = $tx->table('posts')->where('id', $id)->first() ?? throw Failure::notFound();
            if (in_array($post['status'], [...self::EDITABLE, 'queued', 'publishing'], true)) {
                throw Failure::invalid('This post has not finished publishing.');
            }
            $failed = $tx
                ->table('deliveries')
                ->where('post_id', $id)
                ->where('status', 'failed')
                ->orderBy('position')
                ->pluck('platform');
            if ($failed === []) {
                throw Failure::invalid(
                    'No destination failed. Unknown results are never retried; check the account instead.',
                );
            }
            $tx->table('posts')->where('id', $id)->update(['status' => 'publishing', 'updated_at' => self::now()]);
            foreach ($failed as $platform) {
                $tx
                    ->table('deliveries')
                    ->where('post_id', $id)
                    ->where('platform', $platform)
                    ->update([
                        'status' => 'queued',
                        'result' => self::encode(['platform' => $platform, 'status' => 'queued']),
                        'updated_at' => self::now(),
                    ]);
            }
            return $failed;
        });
        foreach ($failed as $platform) {
            $this->deliver($id, $platform);
        }
        return $this->find($id);
    }

    /**
     * Sends one destination of a post, if it is still waiting to be sent, while holding that site's lock: a
     * site gets one post at a time, and different sites send at the same time. Safe to run twice.
     */
    public function deliver(string $id, string $platform): void
    {
        $lock = $this->siteLock($platform);
        try {
            // Taking the delivery from "queued" to "sending" is one write, so only one sender ever gets it. It is
            // stored before any remote call: a crash leaves it unknown, never sent again.
            $taken = $this->db
                ->table('deliveries')
                ->where('post_id', $id)
                ->where('platform', $platform)
                ->where('status', 'queued')
                ->update([
                    'status' => 'sending',
                    'result' => self::encode([
                        'platform' => $platform,
                        'status' => 'sending',
                        'error' => 'Publishing started but no result was recorded. Check the account before sending again.',
                    ]),
                    'updated_at' => self::now(),
                ]);
            if ($taken === 0) {
                return;
            }
            $post = $this->row($id) ?? throw Failure::notFound();
            $result = $this->sessions->enabled($platform)
                ? $this->send($id, $platform, $post['text'])
                : [
                    'platform' => $platform,
                    'status' => 'failed',
                    'error' => 'This account is turned off in Postslop. Nothing was posted; turn it on and retry.',
                ];
            $this->record($id, $platform, $result);
        } finally {
            self::unlock($lock);
        }
        $this->cache->delete(self::ACCOUNTS);
        $this->settle($this->row($id) ?? throw Failure::notFound());
    }

    private function send(string $id, string $platform, string $text): array
    {
        try {
            return $this->publishers[$platform]->publish($id, $text, $this->images($id));
        } catch (\Throwable) {
            return [
                'platform' => $platform,
                'status' => 'unknown',
                'error' => 'Publishing was interrupted. Check the account before sending again.',
            ];
        }
    }

    /**
     * Publishes every scheduled post that is due, oldest first, in this process: the command line's run-due.
     *
     * @return list<array>
     */
    public function runDue(?\DateTimeImmutable $now = null): array
    {
        return array_map($this->publish(...), $this->due($now));
    }

    /**
     * Hands every scheduled post that is due to the queue, as the schedule does each minute, and any post still
     * marked queued by an earlier version.
     *
     * @return list<array>
     */
    public function queueDue(?\DateTimeImmutable $now = null): array
    {
        return array_map($this->queue(...), $this->due($now));
    }

    /** @return list<string> */
    private function due(?\DateTimeImmutable $now): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return [
            ...$this->db->table('posts')->where('status', 'queued')->orderBy('updated_at')->pluck('id'),
            ...$this->db
                ->table('posts')
                ->where('status', 'scheduled')
                ->where('scheduled_at', '<=', $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
                ->orderBy('scheduled_at')
                ->pluck('id'),
        ];
    }

    /**
     * Marks a draft or a scheduled post as publishing, with every destination waiting to be sent, and returns
     * them; none when the post is already on its way.
     *
     * @return list<string>
     */
    private static function claim(Connection $tx, string $id): array
    {
        $claimed = $tx
            ->table('posts')
            ->where('id', $id)
            ->whereIn('status', [...self::EDITABLE, 'queued'])
            ->update(['status' => 'publishing', 'updated_at' => self::now()]);
        if ($claimed === 0) {
            if ($tx->table('posts')->where('id', $id)->first() === null) {
                throw Failure::notFound();
            }
            return [];
        }
        $platforms = json_decode(
            (string) $tx->table('posts')->where('id', $id)->value('platforms'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($platforms as $position => $platform) {
            $tx->table('deliveries')->insert([
                'post_id' => $id,
                'position' => $position,
                'platform' => $platform,
                'status' => 'queued',
                'result' => self::encode(['platform' => $platform, 'status' => 'queued']),
                'updated_at' => self::now(),
            ]);
        }
        return $platforms;
    }

    public function find(string $id): array
    {
        $post = $this->row($id) ?? throw Failure::notFound();
        if (!in_array($post['status'], [...self::EDITABLE, 'queued'], true)) {
            $post = $this->settle($post);
        }
        return $this->present([$post])[0];
    }

    /**
     * @param list<string> $statuses
     * @return array{posts: list<array>, next: ?string}
     */
    public function list(array $statuses = [], int $limit = 20, ?string $before = null): array
    {
        $rows = $this->db
            ->table('posts')
            ->when($statuses !== [], static fn($query) => $query->whereIn('status', $statuses))
            ->when($before !== null, static fn($query) => $query->where('created_at', '<', $before))
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit + 1)
            ->get();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'posts' => $this->present($rows),
            'next' => $more ? $rows[$limit - 1]['created_at'] : null,
        ];
    }

    /**
     * Posts whose time on a calendar falls from $from up to $to, soonest first: when each was published, else when
     * it is scheduled, else when it was last tried. Drafts have no time and are left out.
     *
     * @return list<array>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');
        [$start, $end] = [
            $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ];
        if ($start >= $end) {
            throw Failure::invalid('The end of the range must come after its start.');
        }
        if (($to->getTimestamp() - $from->getTimestamp()) > (62 * 86_400)) {
            throw Failure::invalid('Ask for at most 62 days at a time.');
        }
        $rows = $this->db
            ->table('posts')
            ->where('status', '!=', 'draft')
            ->where(static fn($query) => $query
                ->where(
                    static fn($inner) => $inner
                        ->whereNotNull('published_at')
                        ->where('published_at', '>=', $start)
                        ->where('published_at', '<', $end),
                )
                ->orWhere(
                    static fn($inner) => $inner
                        ->whereNull('published_at')
                        ->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '>=', $start)
                        ->where('scheduled_at', '<', $end),
                )
                ->orWhere(
                    static fn($inner) => $inner
                        ->whereNull('published_at')
                        ->whereNull('scheduled_at')
                        ->where('updated_at', '>=', $start)
                        ->where('updated_at', '<', $end),
                ))
            ->limit(500)
            ->get();
        $time = static fn(array $row): string => $row['published_at'] ?? $row['scheduled_at'] ?? $row['updated_at'];
        usort($rows, static fn(array $a, array $b): int => $time($a) <=> $time($b));
        return $this->present($rows);
    }

    /**
     * @return array{mimeType: string, path: string}
     */
    public function media(string $id): array
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $id) !== 1) {
            throw Failure::notFound('No image has this ID.');
        }
        $row = $this->db->table('media')->where('id', $id)->first() ?? throw Failure::notFound('No image has this ID.');
        return ['mimeType' => $row['mime_type'], 'path' => $this->mediaPath($row)];
    }

    private function record(string $id, string $platform, array $result): void
    {
        $status = in_array($result['status'] ?? null, ['published', 'failed', 'unknown'], true)
            ? $result['status']
            : 'unknown';
        $this->db
            ->table('deliveries')
            ->where('post_id', $id)
            ->where('platform', $platform)
            ->update([
                'status' => $status,
                'result' => self::encode([...$result, 'platform' => $platform, 'status' => $status]),
                'updated_at' => self::now(),
            ]);
    }

    /**
     * Stores the outcome its deliveries add up to, once none is waiting or being sent. A delivery left "sending"
     * by a crash, its site's lock free, becomes unknown; one left waiting far longer than any queue takes failed,
     * since it was never sent.
     */
    private function settle(array $post): array
    {
        if ($post['status'] === 'publishing') {
            $this->recover($post['id']);
        }
        $states = $this->db->table('deliveries')->where('post_id', $post['id'])->pluck('status');
        if (array_intersect($states, ['queued', 'sending']) !== []) {
            return $post;
        }
        $published = count(array_keys($states, 'published', true));
        $status = match (true) {
            $published > 0 && $published === count($states) => 'published',
            $published > 0 => 'partial',
            $states === [] || in_array('unknown', $states, true) => 'unknown',
            default => 'failed',
        };
        if ($status === $post['status']) {
            return $post;
        }
        $changes = ['status' => $status, 'updated_at' => self::now()];
        if ($published > 0 && $post['published_at'] === null) {
            $changes['published_at'] = self::now();
        }
        $this->db->table('posts')->where('id', $post['id'])->update($changes);
        return [...$post, ...$changes];
    }

    private function recover(string $id): void
    {
        $stale = gmdate('Y-m-d\TH:i:s\Z', time() - self::STALE_SECONDS);
        foreach ($this->db->table('deliveries')->where('post_id', $id)->get() as $delivery) {
            if ($delivery['status'] === 'queued' && $delivery['updated_at'] < $stale) {
                $this->db
                    ->table('deliveries')
                    ->where('id', $delivery['id'])
                    ->where('status', 'queued')
                    ->update([
                        'status' => 'failed',
                        'result' => self::encode([
                            'platform' => $delivery['platform'],
                            'status' => 'failed',
                            'error' => 'Publishing did not start for this destination. No post was submitted.',
                        ]),
                        'updated_at' => self::now(),
                    ]);
            }
            if ($delivery['status'] !== 'sending') {
                continue;
            }
            $lock = $this->trySiteLock($delivery['platform']);
            if ($lock === null) {
                continue;
            }
            $this->db
                ->table('deliveries')
                ->where('id', $delivery['id'])
                ->where('status', 'sending')
                ->update([
                    'status' => 'unknown',
                    'result' => self::encode([
                        'platform' => $delivery['platform'],
                        'status' => 'unknown',
                        'error' => 'Publishing was interrupted. Check the account before sending again.',
                    ]),
                    'updated_at' => self::now(),
                ]);
            self::unlock($lock);
        }
    }

    /**
     * @param list<array> $rows
     * @return list<array>
     */
    private function present(array $rows): array
    {
        $ids = array_column($rows, 'id');
        $deliveries = [];
        $media = [];
        if ($ids !== []) {
            foreach ($this->db->table('deliveries')->whereIn('post_id', $ids)->orderBy('position')->get() as $row) {
                $deliveries[$row['post_id']][] = json_decode($row['result'], true, flags: JSON_THROW_ON_ERROR);
            }
            foreach ($this->db->table('media')->whereIn('post_id', $ids)->orderBy('position')->get() as $row) {
                $media[$row['post_id']][] = [
                    'id' => $row['id'],
                    'mimeType' => $row['mime_type'],
                    'width' => (int) $row['width'],
                    'height' => (int) $row['height'],
                    'alt' => $row['alt'],
                ];
            }
        }
        return array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'requestId' => $row['id'],
            'status' => $row['status'],
            'text' => $row['text'],
            'platforms' => json_decode($row['platforms'], true, flags: JSON_THROW_ON_ERROR),
            'images' => $media[$row['id']] ?? [],
            'results' => $deliveries[$row['id']] ?? [],
            'scheduledAt' => $row['scheduled_at'],
            'publishedAt' => $row['published_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ], $rows);
    }

    /**
     * @param list<ImageAttachment> $images
     */
    private function storeImages(Connection $tx, string $postId, array $images): void
    {
        foreach ($images as $position => $image) {
            $row = [
                'id' => PostRequest::uuid(),
                'post_id' => $postId,
                'position' => $position,
                'mime_type' => $image->mimeType,
                'width' => $image->width,
                'height' => $image->height,
                'alt' => $image->alt,
                'sha256' => hash('sha256', $image->bytes),
            ];
            PrivateFiles::write($this->mediaPath($row), $image->bytes);
            $tx->table('media')->insert($row);
        }
    }

    private function removeImages(Connection $tx, string $postId): void
    {
        foreach ($tx->table('media')->where('post_id', $postId)->get() as $row) {
            $path = $this->mediaPath($row);
            $tx->afterCommit(static function () use ($path): void {
                if (is_file($path)) {
                    unlink($path);
                }
            });
        }
        $tx->table('media')->where('post_id', $postId)->delete();
    }

    /**
     * @return list<ImageAttachment>
     */
    private function images(string $postId): array
    {
        $images = [];
        foreach ($this->db->table('media')->where('post_id', $postId)->orderBy('position')->get() as $row) {
            $path = $this->mediaPath($row);
            $bytes = is_file($path) ? file_get_contents($path) : false;
            if ($bytes === false || !hash_equals($row['sha256'], hash('sha256', $bytes))) {
                throw Failure::invalid('An attached image is missing or changed. Attach it again.');
            }
            $images[] = new ImageAttachment(
                'postslop-' . (count($images) + 1) . '.' . self::EXTENSIONS[$row['mime_type']],
                $row['mime_type'],
                $bytes,
                $row['alt'],
                (int) $row['width'],
                (int) $row['height'],
            );
        }
        return $images;
    }

    private function mediaPath(array $row): string
    {
        return $this->mediaDirectory . '/' . $row['id'] . '.' . self::EXTENSIONS[$row['mime_type']];
    }

    private function editable(string $id): array
    {
        $post = $this->row($id) ?? throw Failure::notFound();
        if (!in_array($post['status'], self::EDITABLE, true)) {
            throw Failure::conflict('This post was already sent. Its history cannot be changed.');
        }
        return $post;
    }

    private function row(string $id): ?array
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $id) !== 1) {
            return null;
        }
        return $this->db->table('posts')->where('id', $id)->first();
    }

    /**
     * @return resource
     */
    /** Waits for the site's lock: one post at a time per site. */
    private function siteLock(string $platform): mixed
    {
        $lock = $this->openSiteLock($platform);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('Could not take the publishing lock.');
        }
        return $lock;
    }

    /** The site's lock when no one is sending to it, else null. */
    private function trySiteLock(string $platform): mixed
    {
        $lock = $this->openSiteLock($platform);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    /** @return resource */
    private function openSiteLock(string $platform): mixed
    {
        PrivateFiles::directory(dirname($this->lockFile));
        // storage/publishing.lock is the pattern: storage/publishing-x.lock, storage/publishing-bluesky.lock, …
        $path = preg_replace('/\.lock$/D', '', $this->lockFile) . '-' . $platform . '.lock';
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the publishing lock.');
        }
        chmod($path, 0o600);
        return $lock;
    }

    private static function unlock(mixed $lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function platform(string $platform): void
    {
        if (!in_array($platform, Platforms::IDS, true)) {
            throw Failure::notFound(
                'There is no ' . $platform . ' account. Use ' . implode(', ', Platforms::IDS) . '.',
            );
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $names = scandir($path);
        if ($names === false) {
            return;
        }
        foreach (array_diff($names, ['.', '..']) as $name) {
            self::removeTree($path . '/' . $name);
        }
        rmdir($path);
    }
}
