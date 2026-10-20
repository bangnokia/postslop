<?php

declare(strict_types=1);

namespace App;

use App\Http\PostInput;
use App\Social\DevNullPublisher;
use App\Social\Publisher;
use Crab\Http\HttpException;

// Keep durable before/after side-effect writes together so retry behavior is visible.
// @mago-expect lint:cyclomatic-complexity,kan-defect
final class Publishing
{
    private ?array $cachedStatus = null;
    private float $checkedAt = 0;

    /**
     * @param array<string, Publisher> $publishers
     */
    public function __construct(
        private readonly array $publishers,
        private readonly string $directory,
    ) {}

    public function status(): array
    {
        if ($this->cachedStatus !== null && (microtime(true) - $this->checkedAt) < 30) {
            return $this->cachedStatus;
        }
        $platforms = [];
        foreach ($this->publishers as $id => $publisher) {
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
            if (is_string($status['handle'] ?? null)) {
                $status['handle'] = ltrim($status['handle'], '@');
            }
            $platforms[] = [...$status, 'maxImages' => $id === 'dev-null' ? 3 : 4];
        }
        $this->checkedAt = microtime(true);
        $this->cachedStatus = [
            'platforms' => $platforms,
            'limits' => ['maxImages' => PostInput::MAX_IMAGES, 'maxImageBytes' => PostInput::MAX_IMAGE_BYTES],
            'publishingEnabled' => true,
        ];
        return $this->cachedStatus;
    }

    public function find(string $requestId): array
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $requestId) !== 1) {
            throw new HttpException(404, 'No publication has this ID.');
        }
        $path = $this->directory . '/' . $requestId . '.json';
        if (!is_file($path)) {
            throw new HttpException(404, 'No publication has this ID.');
        }
        $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $record = $this->reviewPending($path, $record);
        return [
            'requestId' => $requestId,
            'status' => $this->outcome($record['results']),
            'results' => $record['results'],
        ];
    }

    public function publish(PostInput $input): array
    {
        PrivateFiles::directory($this->directory);
        $lock = fopen($this->directory . '/publishing.lock', 'c');
        if ($lock === false) {
            throw new HttpException(503, 'Could not open the publishing lock.');
        }
        chmod($this->directory . '/publishing.lock', 0o600);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new HttpException(409, 'Another post is being published. Keep this draft and try again shortly.');
            }
            $path = $this->directory . '/' . $input->requestId . '.json';
            if (is_file($path)) {
                $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (!hash_equals($record['fingerprint'], $input->fingerprint)) {
                    throw new HttpException(409, 'This requestId belongs to a different post.');
                }
                return $this->find($input->requestId);
            }
            $record = [
                'requestId' => $input->requestId,
                'fingerprint' => $input->fingerprint,
                'status' => 'failed',
                'results' => array_map(static fn(string $platform): array => [
                    'platform' => $platform,
                    'status' => 'failed',
                    'error' => 'Publishing did not start for this destination. No post was submitted.',
                ], $input->platforms),
            ];
            PrivateFiles::json($path, $record);
            foreach ($input->platforms as $index => $platform) {
                // Persist uncertainty before any remote side effect, so a crash or
                // repeated Enter can never silently publish the same request twice.
                $record['results'][$index] = [
                    'platform' => $platform,
                    'status' => 'unknown',
                    'error' => 'Publishing started but no result was recorded. Check the account before sending again.',
                ];
                PrivateFiles::json($path, $record);
                try {
                    $result = $this->publishers[$platform]->publish($input->requestId, $input->text, $input->images);
                } catch (\Throwable) {
                    $result = [
                        'platform' => $platform,
                        'status' => 'unknown',
                        'error' => 'Publishing was interrupted. Check the account before sending again.',
                    ];
                }
                $record['results'][$index] = $result;
                PrivateFiles::json($path, $record);
            }
            $record['status'] = $this->outcome($record['results']);
            PrivateFiles::json($path, $record);
            $this->cachedStatus = null;
            return $this->find($input->requestId);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function reviewPending(string $path, array $record): array
    {
        $publisher = $this->publishers['dev-null'] ?? null;
        $pending = array_filter(
            $record['results'],
            static fn(array $result): bool => (
                ($result['platform'] ?? '') === 'dev-null'
                && ($result['pendingReview'] ?? false) === true
            ),
        );
        if ($pending === [] || !$publisher instanceof DevNullPublisher) {
            return $record;
        }
        $lock = fopen($this->directory . '/publishing.lock', 'c');
        if ($lock === false) {
            return $record;
        }
        chmod($this->directory . '/publishing.lock', 0o600);
        try {
            // A live publication or another check owns the receipt until its atomic write completes.
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $record;
            }
            $current = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $updated = $current;
            foreach ($current['results'] as $index => $result) {
                if (($result['platform'] ?? '') !== 'dev-null' || ($result['pendingReview'] ?? false) !== true) {
                    continue;
                }
                $updated['results'][$index] = $publisher->verifySaved($result);
            }
            if ($updated !== $current) {
                $updated['status'] = $this->outcome($updated['results']);
                PrivateFiles::json($path, $updated);
            }
            return $updated;
        } catch (\Throwable) {
            return $record;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function outcome(array $results): string
    {
        $states = array_column($results, 'status');
        $published = count(array_filter($states, static fn(string $state): bool => $state === 'published'));
        return match (true) {
            $published > 0 && $published === count($states) => 'published',
            $published > 0 => 'partial',
            $states === [] || in_array('unknown', $states, true) => 'unknown',
            default => 'failed',
        };
    }
}
