<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
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
            Show the accounts, check one, turn one on or off, or remove it
            Usage: postslop accounts [--refresh] [--json]
                   postslop accounts check|enable|disable|remove SITE [--json]
            SITE is x, bluesky, dev-null, linkedin or threads. A turned-off account keeps its session but is not offered
            or published to. Removing one deletes its session; its posts stay in the history.
            Add or refresh an account with: postslop setup SITE
            Check only one site's saved session with: postslop accounts check SITE
            Options:
              --refresh  Check every session again instead of using the last 30 seconds' result
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $action = $input->argument(0);
        $site = $input->argument(1);
        if ($action !== null && $site === null) {
            throw Failure::invalid(
                'Name the site: postslop accounts ' . $action . ' x|bluesky|dev-null|linkedin|threads',
            );
        }
        $accounts = match ($action) {
            null => $input->flag('refresh') ? $this->publishing->refreshAccounts() : $this->publishing->accounts(),
            'check' => ['platforms' => [$this->publishing->refreshAccount((string) $site)]],
            'enable', 'disable' => $this->publishing->setEnabled((string) $site, $action === 'enable'),
            'remove' => $this->publishing->removeAccount((string) $site),
            default => throw Failure::invalid('Use: postslop accounts [check|enable|disable|remove SITE]'),
        };
        if ($input->flag('json')) {
            $output->line(self::json($accounts));
            return self::OK;
        }
        $rows = [];
        foreach ($accounts['platforms'] as $account) {
            $rows[] = [
                $account['label'],
                ($account['handle'] ?? null) !== null ? '@' . ltrim($account['handle'], '@') : '',
                match (true) {
                    !$account['connected'] => 'not connected: postslop setup ' . $account['id'],
                    !$account['enabled'] => 'off',
                    $account['available'] => 'ready',
                    default => $account['reason'] ?? 'unavailable',
                },
            ];
        }
        $output->table($rows);
        return self::OK;
    }
}
