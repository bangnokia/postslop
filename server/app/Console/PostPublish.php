<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class PostPublish extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Publish a draft or a scheduled post now
            Usage: postslop publish ID [--json]
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        return self::show($input, $output, $this->publishing->publish(self::id($input)));
    }
}
