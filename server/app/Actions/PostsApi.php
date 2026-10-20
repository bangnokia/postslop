<?php

declare(strict_types=1);

namespace App\Actions;

use App\Core\Failure;
use App\Core\PostRequest;
use App\Core\Publishing;
use Crab\Http\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTTP door to the core: decode, call, return. Rules live in Publishing.
 */
// One method per endpoint keeps the API's whole surface in one place.
// @mago-expect lint:cyclomatic-complexity,too-many-methods
final readonly class PostsApi
{
    private const array STATUSES = [
        'draft',
        'scheduled',
        'queued',
        'publishing',
        'published',
        'partial',
        'failed',
        'unknown',
    ];

    public function __construct(
        private Publishing $publishing,
    ) {}

    public function accounts(ServerRequestInterface $request): array
    {
        return ($request->getQueryParams()['refresh'] ?? '') === '1'
            ? $this->publishing->refreshAccounts()
            : $this->publishing->accounts();
    }

    public function index(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        if (array_key_exists('from', $query) || array_key_exists('to', $query)) {
            return ['posts' => $this->publishing->between(
                self::date($query['from'] ?? null),
                self::date($query['to'] ?? null),
            )];
        }
        $statuses = array_values(array_filter(explode(',', (string) ($query['status'] ?? ''))));
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw Failure::invalid('Filter by ' . implode(', ', self::STATUSES) . '.');
        }
        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'max_range' => 100,
        ]]);
        if ($limit === false) {
            throw Failure::invalid('The limit must be between 1 and 100.');
        }
        $before = $query['before'] ?? null;
        if (
            $before !== null
            && (!is_string($before) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $before) !== 1)
        ) {
            throw Failure::invalid('Use the next value of a previous page as before.');
        }
        return $this->publishing->list($statuses, $limit, $before);
    }

    public function store(ServerRequestInterface $request): array
    {
        // The web client never waits on the sites: publishing now goes to the queue.
        return $this->publishing->createQueued(PostRequest::fromApi(self::body($request)));
    }

    public function show(string $id): array
    {
        return $this->publishing->find($id);
    }

    public function update(string $id, ServerRequestInterface $request): array
    {
        return $this->publishing->updateQueued($id, PostRequest::fromApi([
            ...self::body($request),
            'requestId' => $id,
        ]));
    }

    public function publish(string $id): array
    {
        return $this->publishing->queue($id);
    }

    public function retry(string $id): array
    {
        return $this->publishing->retry($id);
    }

    public function schedule(string $id, ServerRequestInterface $request): array
    {
        $at = self::body($request)['scheduledAt'] ?? null;
        if ($at !== null && !is_string($at)) {
            throw Failure::invalid('Give scheduledAt as a date and time, or null to unschedule.');
        }
        try {
            $time = $at === null ? null : new \DateTimeImmutable($at);
        } catch (\Exception) {
            throw Failure::invalid('The scheduled time is not a date and time.');
        }
        return $this->publishing->schedule($id, $time);
    }

    public function destroy(string $id): Response
    {
        $this->publishing->delete($id);
        return new Response(204);
    }

    public function media(string $id): Response
    {
        $media = $this->publishing->media($id);
        return new Response(
            headers: [
                'Content-Type' => $media['mimeType'],
                'Cache-Control' => 'private, max-age=86400',
            ],
            body: (string) file_get_contents($media['path']),
        );
    }

    private static function date(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw Failure::invalid('Give both from and to as dates and times.');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw Failure::invalid('from and to must be dates and times.');
        }
    }

    private static function body(ServerRequestInterface $request): array
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            throw Failure::invalid('Send a JSON object.');
        }
        return $data;
    }
}
