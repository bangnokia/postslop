<?php

declare(strict_types=1);

namespace App\Core;

use App\Jobs\PublishPost;
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
        $this->sessions->import([
            $platform => [
                'handle' => $handle,
                'session' => [
                    'cookies' => $session['cookies'],
                    'origins' => $session['origins'] ?? [],
                ],
            ],
        ], $source);
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
     * Marks a draft or a scheduled post as queued and pushes the job that publishes it, in one transaction, so
     * the job exists exactly when the queued post does. A post already on its way is returned as it is.
     */
    public function queue(string $id): array
    {
        $this->db->transaction(function (Connection $tx) use ($id): void {
            $queued = $tx
                ->table('posts')
                ->where('id', $id)
                ->whereIn('status', self::EDITABLE)
                ->update(['status' => 'queued', 'updated_at' => self::now()]);
            if ($queued > 0) {
                $this->queue->within($tx)->push(PublishPost::class, id: $id);
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
     * Sends a draft or a scheduled post to each of its destinations, once.
     */
    public function publish(string $id): array
    {
        $lock = $this->lock();
        try {
            $post = $this->row($id) ?? throw Failure::notFound();
            if (!in_array($post['status'], [...self::EDITABLE, 'queued'], true)) {
                return $this->find($id);
            }
            $platforms = json_decode($post['platforms'], true, flags: JSON_THROW_ON_ERROR);
            $this->db->transaction(static function (Connection $tx) use ($id, $platforms): void {
                $tx->table('posts')->where('id', $id)->update(['status' => 'publishing', 'updated_at' => self::now()]);
                foreach ($platforms as $position => $platform) {
                    $tx->table('deliveries')->insert([
                        'post_id' => $id,
                        'position' => $position,
                        'platform' => $platform,
                        'status' => 'failed',
                        'result' => self::encode([
                            'platform' => $platform,
                            'status' => 'failed',
                            'error' => 'Publishing did not start for this destination. No post was submitted.',
                        ]),
                        'updated_at' => self::now(),
                    ]);
                }
            });
            $this->send($post, $platforms);
            return $this->find($id);
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Sends again only to destinations that failed. An unknown result is never
     * retried: the post may already be live.
     */
    public function retry(string $id): array
    {
        $lock = $this->lock();
        try {
            $post = $this->row($id) ?? throw Failure::notFound();
            if (in_array($post['status'], [...self::EDITABLE, 'queued', 'publishing'], true)) {
                throw Failure::invalid('This post has not finished publishing.');
            }
            $failed = $this->db
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
            $this->db
                ->table('posts')
                ->where('id', $id)
                ->update(['status' => 'publishing', 'updated_at' => self::now()]);
            $this->send($post, $failed);
            return $this->find($id);
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Publishes every scheduled post that is due, oldest first, and any queued post whose job could not send it,
     * such as one that found another publication holding the lock.
     *
     * @return list<array>
     */
    public function runDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $due = [
            ...$this->db->table('posts')->where('status', 'queued')->orderBy('updated_at')->pluck('id'),
            ...$this->db
                ->table('posts')
                ->where('status', 'scheduled')
                ->where('scheduled_at', '<=', $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
                ->orderBy('scheduled_at')
                ->pluck('id'),
        ];
        $published = [];
        foreach ($due as $id) {
            try {
                $published[] = $this->publish($id);
            } catch (Failure $failure) {
                if ($failure->kind === Failure::BUSY) {
                    // Another publication holds the lock; the next run picks these up.
                    break;
                }
                throw $failure;
            }
        }
        return $published;
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

    private function send(array $post, array $platforms): void
    {
        $images = $this->images($post['id']);
        foreach ($platforms as $platform) {
            // Persist uncertainty before any remote side effect, so a crash or
            // repeated Enter can never silently publish the same request twice.
            $this->record($post['id'], $platform, [
                'platform' => $platform,
                'status' => 'unknown',
                'error' => 'Publishing started but no result was recorded. Check the account before sending again.',
            ]);
            if (!$this->sessions->enabled($platform)) {
                $this->record($post['id'], $platform, [
                    'platform' => $platform,
                    'status' => 'failed',
                    'error' => 'This account is turned off in Postslop. Nothing was posted; turn it on and retry.',
                ]);
                continue;
            }
            try {
                $result = $this->publishers[$platform]->publish($post['id'], $post['text'], $images);
            } catch (\Throwable) {
                $result = [
                    'platform' => $platform,
                    'status' => 'unknown',
                    'error' => 'Publishing was interrupted. Check the account before sending again.',
                ];
            }
            $this->record($post['id'], $platform, $result);
        }
        $this->settle($this->row($post['id']), force: true);
        $this->cache->delete(self::ACCOUNTS);
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
     * Stores the outcome its deliveries add up to. A post left "publishing" by a
     * crash settles once no publication holds the lock.
     */
    // A forced settle is the publisher's own final write, made while it holds the lock.
    // @mago-expect lint:no-boolean-flag-parameter
    private function settle(array $post, bool $force = false): array
    {
        if ($post['status'] === 'publishing' && !$force) {
            $lock = $this->tryLock();
            if ($lock === null) {
                return $post;
            }
            self::unlock($lock);
        }
        $states = $this->db->table('deliveries')->where('post_id', $post['id'])->pluck('status');
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
    private function lock(): mixed
    {
        return $this->tryLock() ?? throw Failure::busy();
    }

    /**
     * @return resource|null
     */
    private function tryLock(): mixed
    {
        PrivateFiles::directory(dirname($this->lockFile));
        $path = $this->lockFile;
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the publishing lock.');
        }
        chmod($path, 0o600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    /**
     * @param resource $lock
     */
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
