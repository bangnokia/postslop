<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

/**
 * The command line door to the core. With --json every command prints one
 * JSON document on standard output, errors included, and its exit code says
 * what happened, so scripts and AI agents never parse prose.
 */
// The shared door holds every output shape, so commands stay a few lines each.
// @mago-expect lint:cyclomatic-complexity
abstract readonly class CliCommand implements Command
{
    public const int OK = 0;
    public const int INVALID = 2;
    public const int PARTIAL = 3;
    public const int FAILED = 4;
    public const int UNKNOWN = 5;
    public const int BUSY = 6;
    public const int CONFLICT = 7;
    public const int NOT_FOUND = 8;

    private const array FAILURE_CODES = [
        Failure::INVALID => self::INVALID,
        Failure::NOT_FOUND => self::NOT_FOUND,
        Failure::CONFLICT => self::CONFLICT,
        Failure::BUSY => self::BUSY,
    ];

    abstract protected function run(Input $input, Output $output): int;

    public function handle(Input $input, Output $output): int
    {
        try {
            return $this->run($input, $output);
        } catch (Failure $failure) {
            $input->flag('json')
                ? $output->line(self::json(['error' => [
                    'code' => $failure->kind,
                    'message' => $failure->getMessage(),
                ]]))
                : $output->error($failure->getMessage());
            return self::FAILURE_CODES[$failure->kind];
        }
    }

    protected static function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    protected static function value(Input $input, string $name): ?string
    {
        $value = $input->option($name);
        if ($value === true) {
            throw Failure::invalid('--' . $name . ' needs a value; write --' . $name . '=…');
        }
        return $value === null || $value === false ? null : $value;
    }

    protected static function id(Input $input): string
    {
        $id = $input->argument(0);
        if ($id === null) {
            throw Failure::invalid('Give the post ID.');
        }
        return $id;
    }

    /**
     * Reads a time as the user means it: an ISO time with its offset, or a local
     * time such as "tomorrow 9:00" or "+2 hours" in this Mac's time zone.
     */
    protected static function time(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, self::localZone());
        } catch (\Exception) {
            throw Failure::invalid(
                'Cannot read the time "' . $value . '"; try --at="2026-10-21 09:00" or --at="+2 hours".',
            );
        }
    }

    protected static function localZone(): \DateTimeZone
    {
        $name = (string) getenv('TZ');
        if ($name === '' && is_link('/etc/localtime')) {
            $name = explode('zoneinfo/', (string) readlink('/etc/localtime'), 2)[1] ?? '';
        }
        try {
            return new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    protected static function exitFor(array $post): int
    {
        return match ($post['status']) {
            'partial' => self::PARTIAL,
            'failed' => self::FAILED,
            'unknown', 'queued', 'publishing' => self::UNKNOWN,
            default => self::OK,
        };
    }

    /**
     * Prints a post for a person, or as JSON, and returns its exit code.
     */
    protected static function show(Input $input, Output $output, array $post): int
    {
        if ($input->flag('json')) {
            $output->line(self::json($post));
            return self::exitFor($post);
        }
        $output->line(self::summary($post) . '  ' . $post['id']);
        if ($post['text'] !== '') {
            $output->line('  ' . str_replace("\n", "\n  ", mb_strimwidth($post['text'], 0, 200, '…')));
        }
        if ($post['images'] !== []) {
            $output->line('  ' . count($post['images']) . ' image' . (count($post['images']) === 1 ? '' : 's'));
        }
        $rows = [];
        foreach ($post['results'] as $result) {
            $rows[] = [
                '  ' . $result['platform'],
                $result['status'],
                $result['url'] ?? $result['error'] ?? '',
            ];
        }
        if ($rows !== []) {
            $output->table($rows);
        }
        $hint = match ($post['status']) {
            'draft' => 'Publish it with: postslop publish ' . $post['id'],
            'partial', 'failed' => in_array('failed', array_column($post['results'], 'status'), true)
                ? 'Retry the failed destinations with: postslop retry ' . $post['id']
                : null,
            'unknown' => 'Check the account before sending again. Recheck with: postslop show ' . $post['id'],
            default => null,
        };
        if ($hint !== null) {
            $output->line($hint);
        }
        return self::exitFor($post);
    }

    protected static function summary(array $post): string
    {
        $platforms = implode(', ', $post['platforms']);
        return match ($post['status']) {
            'draft' => 'Draft for ' . $platforms,
            'scheduled' => 'Scheduled for ' . new \DateTimeImmutable($post['scheduledAt'])
                ->setTimezone(self::localZone())
                ->format('D j M, H:i') . ' to ' . $platforms,
            'queued' => 'Queued for ' . $platforms,
            'publishing' => 'Publishing to ' . $platforms,
            'published' => 'Published to ' . $platforms,
            'partial' => 'Partly published',
            'failed' => 'Not published',
            default => 'Outcome unknown',
        };
    }
}
