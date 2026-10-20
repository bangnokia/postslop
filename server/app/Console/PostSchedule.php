<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class PostSchedule extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Schedule a draft, move a scheduled post, or return it to the drafts
            Usage: postslop schedule ID --at=TIME | --clear [--json]
            Options:
              --at=TIME  "2026-10-21 09:00", "tomorrow 9am" or "+2 hours", local time
              --clear    Unschedule: keep it as a draft
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $at = self::value($input, 'at');
        if (($at === null) === !$input->flag('clear')) {
            throw Failure::invalid('Give --at=TIME, or --clear to unschedule.');
        }
        $post = $this->publishing->schedule(self::id($input), $at === null ? null : self::time($at));
        return self::show($input, $output, $post);
    }
}
