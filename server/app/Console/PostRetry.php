<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class PostRetry extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Send a post again to the destinations that failed; unknown results are never retried
            Usage: postslop retry ID [--json]
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        return self::show($input, $output, $this->publishing->retry(self::id($input)));
    }
}
