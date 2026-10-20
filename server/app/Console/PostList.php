<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class PostList extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            List posts, newest first
            Usage: postslop posts [--status=LIST] [--limit=20] [--json]
            Options:
              --status=LIST  draft, scheduled, publishing, published, partial, failed or unknown, comma separated
              --limit=N      How many, from 1 to 100
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $statuses = array_values(array_filter(explode(',', self::value($input, 'status') ?? '')));
        $limit = filter_var(self::value($input, 'limit') ?? '20', FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'max_range' => 100,
        ]]);
        if ($limit === false) {
            throw Failure::invalid('--limit must be a whole number from 1 to 100.');
        }
        $page = $this->publishing->list($statuses, $limit);
        if ($input->flag('json')) {
            $output->line(self::json($page));
            return self::OK;
        }
        if ($page['posts'] === []) {
            $output->line('No posts yet.');
            return self::OK;
        }
        $rows = [['ID', 'Status', 'When', 'Text']];
        foreach ($page['posts'] as $post) {
            $when = $post['scheduledAt'] ?? $post['publishedAt'] ?? $post['createdAt'];
            $rows[] = [
                $post['id'],
                $post['status'],
                new \DateTimeImmutable($when)
                    ->setTimezone(self::localZone())
                    ->format('j M H:i'),
                mb_strimwidth(str_replace("\n", ' ', $post['text']), 0, 50, '…'),
            ];
        }
        $output->table($rows);
        return self::OK;
    }
}
