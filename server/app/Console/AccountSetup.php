<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
use App\Core\Publishing;
use App\Social\SessionStore;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

// Each step of the import is a guard with its own message.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
final readonly class AccountSetup extends CliCommand
{
    private const array SITES = ['x' => 'X', 'bluesky' => 'Bluesky', 'dev-null' => 'dev-null.sh'];

    public function __construct(
        private Publishing $publishing,
        private SessionStore $sessions,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Import your signed-in X, Bluesky and dev-null.sh sessions from Chrome or Firefox
            Usage: postslop setup [--browser=chrome|firefox] [--x=HANDLE] [--bluesky=HANDLE] [--dev-null=HANDLE] [--profile=DIR]
            First check in the browser which account is signed in on each site. Postslop
            refuses a session that belongs to another account than the handle you give.
            Options:
              --browser=NAME chrome (default) or firefox. Chrome asks macOS once for
                             "Chrome Safe Storage" to decrypt its cookies: choose Allow
              --x=HANDLE, --bluesky=HANDLE, --dev-null=HANDLE
                             The account signed in on each site; asked for when left out
              --profile=DIR  The profile folder (default: the browser's last used or default profile)
              --check-only   Read the sessions without saving them
            Nothing leaves this Mac. Sessions are saved in storage/sessions.json, readable only by you.
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $browser = self::value($input, 'browser') ?? 'chrome';
        if (!in_array($browser, ['chrome', 'firefox'], true)) {
            throw Failure::invalid('Choose --browser=chrome or --browser=firefox.');
        }
        // Chrome's importer finds its last used profile itself.
        $profile = self::value($input, 'profile') ?? ($browser === 'firefox' ? self::defaultProfile() : null);
        if ($browser === 'firefox' && !is_file($profile . '/cookies.sqlite')) {
            throw Failure::invalid('No Firefox profile with cookies at ' . $profile . '. Pass --profile=DIR.');
        }
        $output->line('Importing from ' . ($browser === 'chrome' ? 'Chrome' : 'Firefox') . '.');
        $handles = [];
        foreach (self::SITES as $site => $label) {
            $handle = self::value($input, $site) ?? self::ask('Signed-in ' . $label . ' handle: @');
            $handle = ltrim(trim($handle), '@');
            if (preg_match('/^[A-Za-z0-9._-]+$/D', $handle) !== 1) {
                throw Failure::invalid('Give the ' . $label . ' handle, such as --' . $site . '=yourname.');
            }
            $handles[$site] = $handle;
        }
        $command = [
            'python3',
            dirname(__DIR__, 2) . '/scripts/import_' . $browser . '_sessions.py',
            ...($profile !== null ? ['--profile', $profile] : []),
            '--x-handle',
            $handles['x'],
            '--bluesky-handle',
            $handles['bluesky'],
            '--dev-null-handle',
            $handles['dev-null'],
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw Failure::invalid('Could not run python3. Install Python 3 and try again.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw Failure::invalid($stderr !== '' ? $stderr : 'The session import failed.');
        }
        $report = json_decode($stdout, true);
        if (!is_array($report['accounts'] ?? null)) {
            throw Failure::invalid('The session import returned nothing usable.');
        }
        $output->line('Profile: ' . ($report['profile'] ?? $profile));
        foreach (self::SITES as $site => $label) {
            $account = $report['accounts'][$site] ?? [];
            $output->line(sprintf('  %-12s @%s, %d cookies', $label, $handles[$site], $account['cookieCount'] ?? 0));
        }
        if ($input->flag('check-only')) {
            $output->line('Checked only; nothing was saved.');
            return self::OK;
        }
        $this->sessions->import($report['accounts'], $browser);
        $output->line('Checking each session signs in as the account you named…');
        $ready = true;
        foreach ($this->publishing->refreshAccounts()['platforms'] as $account) {
            $ready = $ready && $account['available'];
            $output->line(sprintf(
                '  %-12s %s',
                $account['label'],
                $account['available'] ? 'ready as @' . $account['handle'] : $account['reason'] ?? 'unavailable',
            ));
        }
        $output->line(
            $ready
                ? 'All set. Try: postslop post "Hello" --now'
                : 'Fix the accounts above, then run postslop setup again.',
        );
        return $ready ? self::OK : self::FAILED;
    }

    private static function ask(string $question): string
    {
        if (!stream_isatty(STDIN)) {
            throw Failure::invalid('Give every handle: postslop setup --x=… --bluesky=… --dev-null=…');
        }
        fwrite(STDOUT, $question);
        return (string) fgets(STDIN);
    }

    private static function defaultProfile(): string
    {
        $home = (string) getenv('HOME');
        foreach ([$home . '/Library/Application Support/Firefox', $home . '/.mozilla/firefox'] as $base) {
            $ini = is_file($base . '/profiles.ini')
                ? parse_ini_file($base . '/profiles.ini', true, INI_SCANNER_RAW)
                : false;
            if (!is_array($ini)) {
                continue;
            }
            // The profile Firefox opens is the install's default, else the one marked default.
            $path = null;
            foreach ($ini as $section => $values) {
                if (!str_starts_with($section, 'Install')) {
                    continue;
                }
                $path ??= $values['Default'] ?? null;
            }
            foreach ($ini as $values) {
                if (($values['Default'] ?? '') !== '1') {
                    continue;
                }
                $path ??= $values['Path'] ?? null;
            }
            if ($path !== null) {
                return str_starts_with($path, '/') ? $path : $base . '/' . $path;
            }
        }
        throw Failure::invalid('Firefox was not found. Pass the profile folder with --profile=DIR.');
    }
}
