<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class AccountList extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Show which accounts are signed in and ready to publish
            Usage: postslop accounts [--refresh] [--json]
            Options:
              --refresh  Check every session again instead of using the last 30 seconds' result
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $accounts = $input->flag('refresh') ? $this->publishing->refreshAccounts() : $this->publishing->accounts();
        if ($input->flag('json')) {
            $output->line(self::json($accounts));
            return self::OK;
        }
        $rows = [];
        foreach ($accounts['platforms'] as $account) {
            $rows[] = [
                $account['label'],
                ($account['handle'] ?? null) !== null ? '@' . $account['handle'] : '',
                $account['available'] ? 'ready' : $account['reason'] ?? 'unavailable',
            ];
        }
        $output->table($rows);
        return self::OK;
    }
}
