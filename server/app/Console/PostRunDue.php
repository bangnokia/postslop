<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class PostRunDue extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Publish the scheduled posts that are due now; the schedule in `serve` does this every minute
            Usage: postslop run-due [--json]
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $posts = $this->publishing->runDue();
        if ($input->flag('json')) {
            $output->line(self::json(['posts' => $posts]));
            return self::OK;
        }
        foreach ($posts as $post) {
            $output->line(gmdate('Y-m-d\TH:i:s\Z') . ' ' . $post['id'] . ' ' . $post['status']);
        }
        return self::OK;
    }
}
