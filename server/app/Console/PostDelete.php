<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class PostDelete extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Delete a draft or a scheduled post; sent posts stay in the history
            Usage: postslop delete ID [--json]
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $id = self::id($input);
        $this->publishing->delete($id);
        $output->line($input->flag('json') ? self::json(['deleted' => $id]) : 'Deleted ' . $id . '.');
        return self::OK;
    }
}
