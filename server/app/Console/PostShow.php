<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class PostShow extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Show a post and its result on each destination
            Usage: postslop show ID [--json]
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        return self::show($input, $output, $this->publishing->find(self::id($input)));
    }
}
