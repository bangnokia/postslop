<?php

declare(strict_types=1);

namespace Postslop\Client;

use RuntimeException;

/** Remote account commands only. Publication and all account rules remain in Postslop's core. */
// Keep argument guards and the two remote command flows together; JSON selects the documented CLI output format.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead,no-boolean-flag-parameter
final class Application
{
    public const VERSION = '0.1.0';
    private const VALUES = ['to', 'token', 'browser', 'profile', 'x', 'bluesky', 'dev-null', 'linkedin', 'threads'];
    private const FLAGS = ['help', 'version', 'json', 'check-only', 'refresh'];

    public function run(array $arguments): int
    {
        $json = in_array('--json', $arguments, true);
        try {
            [$words, $options] = self::parse($arguments);
            if (array_key_exists('version', $options)) {
                $this->output(['version' => self::VERSION], $json, 'Postslop client ' . self::VERSION);
                return 0;
            }
            $command = array_shift($words) ?? 'help';
            if (array_key_exists('help', $options) || $command === 'help') {
                echo self::help();
                return 0;
            }
            if (!in_array($command, ['setup', 'accounts'], true)) {
                throw new RuntimeException(
                    'This remote client supports setup and accounts. Publishing uses the web client, Raycast, or server/bin/postslop.',
                );
            }
            $allowed = $command === 'setup'
                ? [...self::VALUES, 'json', 'check-only']
                : ['to', 'token', 'json', 'refresh'];
            if (array_diff(array_keys($options), $allowed) !== []) {
                throw new RuntimeException('That option is not supported by ' . $command . '. See --help.');
            }
            if ($command === 'setup' && array_key_exists('check-only', $options)) {
                return $this->setup($words, $options, null, $json);
            }
            $url = $options['to'] ?? (string) getenv('POSTSLOP_SERVER_URL');
            if ($url === '') {
                throw new RuntimeException('Give --to=https://your-postslop-server or set POSTSLOP_SERVER_URL.');
            }
            $remote = new Remote($url, $options['token'] ?? (string) getenv('POSTSLOP_REMOTE_TOKEN'));
            return $command === 'setup'
                ? $this->setup($words, $options, $remote, $json)
                : $this->accounts($words, $options, $remote, $json);
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
            // Do not expose even an explicitly supplied token in error output.
            $token = $options['token'] ?? (string) getenv('POSTSLOP_REMOTE_TOKEN');
            if ($token !== '') {
                $message = str_replace($token, '[redacted]', $message);
            }
            if ($json) {
                $this->output(['error' => ['message' => $message]], true, '');
                return 2;
            }
            fwrite(STDERR, $message . PHP_EOL);
            return 2;
        }
    }

    private function setup(array $sites, array $options, ?Remote $remote, bool $json): int
    {
        $browser = $options['browser'] ?? 'chrome';
        $handles = [];
        foreach ($sites === [] ? array_keys(BrowserSessions::SITES) : array_unique($sites) as $site) {
            self::site($site);
            $handle = $options[$site] ?? self::ask(
                'Signed-in ' . BrowserSessions::SITES[$site] . ' handle (blank to skip): @',
            );
            $handle = ltrim(trim($handle), '@');
            if ($handle === '' && $sites === []) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $handle) !== 1) {
                throw new RuntimeException('Give the signed-in handle with --' . $site . '=HANDLE.');
            }
            $handles[$site] = $handle;
        }
        if ($handles === []) {
            throw new RuntimeException('Name at least one account to import.');
        }
        if ($remote !== null) {
            // Verify the destination and token before reading browser credentials.
            $probe = $remote->request('GET', '/posts?limit=1');
            if (!is_array($probe['posts'] ?? null)) {
                throw new RuntimeException(
                    'The destination did not answer as a Postslop server. Nothing was imported.',
                );
            }
        }
        if (!$json) {
            echo 'Importing from ' . $browser . '…' . PHP_EOL;
        }
        $report = BrowserSessions::read($browser, $options['profile'] ?? null, $handles);
        if ($remote === null) {
            $counts = [];
            foreach ($report['accounts'] as $site => $account) {
                $counts[] = ['id' => $site, 'handle' => $account['handle'], 'cookieCount' => $account['cookieCount']];
            }
            $this->output(
                ['accounts' => $counts, 'sent' => false],
                $json,
                'Browser sessions read successfully; nothing was sent or saved.',
            );
            return 0;
        }
        $checked = [];
        $ready = true;
        foreach ($report['accounts'] as $site => $account) {
            $reply = $remote->request('PUT', '/accounts/' . $site, [
                'handle' => $account['handle'],
                'session' => $account['session'],
                'source' => $browser,
            ]);
            $result = null;
            foreach ($reply['platforms'] ?? [] as $item) {
                if (($item['id'] ?? null) !== $site) {
                    continue;
                }
                $result = $item;
                break;
            }
            if (!is_array($result) || !is_bool($result['available'] ?? null)) {
                throw new RuntimeException(
                    'Postslop did not return the imported account status. Check the account on the server.',
                );
            }
            if (
                $result['available']
                && (
                    !is_string($result['handle'] ?? null)
                    || strcasecmp(ltrim($result['handle'], '@'), $account['handle']) !== 0
                )
            ) {
                throw new RuntimeException(
                    'Postslop returned a different account handle. Check the imported account on the server.',
                );
            }
            $checked[] = $result;
            $ready = $ready && $result['available'];
        }
        $this->showAccounts(['platforms' => $checked], $json);
        if (!$json) {
            echo
                ($ready ? 'All set on ' . $remote->url . '.' : 'Fix the accounts above, then run setup again.')
                    . PHP_EOL;
        }
        return $ready ? 0 : 4;
    }

    private function accounts(array $words, array $options, Remote $remote, bool $json): int
    {
        if ($words === []) {
            $reply = $remote->request('GET', '/accounts' . (array_key_exists('refresh', $options) ? '?refresh=1' : ''));
        } else {
            if (
                count($words) !== 2
                || !in_array($words[0], ['check', 'enable', 'disable', 'remove'], true)
                || array_key_exists('refresh', $options)
            ) {
                throw new RuntimeException('Use accounts [check|enable|disable|remove SITE], or accounts --refresh.');
            }
            [$action, $site] = $words;
            self::site($site);
            $reply = match ($action) {
                'check' => ['platforms' => [$remote->request('POST', '/accounts/' . $site . '/check', [])]],
                'enable', 'disable' => $remote->request('PATCH', '/accounts/' . $site, [
                    'enabled' => $action === 'enable',
                ]),
                'remove' => $remote->request('DELETE', '/accounts/' . $site),
            };
        }
        if (!is_array($reply['platforms'] ?? null)) {
            throw new RuntimeException('The server did not return Postslop accounts.');
        }
        $this->showAccounts($reply, $json);
        return 0;
    }

    private function showAccounts(array $reply, bool $json): void
    {
        if ($json) {
            $this->output($reply, true, '');
            return;
        }
        foreach ($reply['platforms'] as $account) {
            $state = $account['available'] ?? false ? 'ready' : $account['reason'] ?? 'unavailable';
            echo ($account['label'] ?? $account['id']) . '  @' . ($account['handle'] ?? '') . '  ' . $state . PHP_EOL;
            if (is_string($account['detail'] ?? null) && $account['detail'] !== '') {
                echo '  ' . $account['detail'] . PHP_EOL;
            }
        }
    }

    private function output(array $data, bool $json, string $text): void
    {
        echo
            ($json ? json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $text)
                . PHP_EOL;
    }

    private static function site(string $site): void
    {
        if (!array_key_exists($site, BrowserSessions::SITES)) {
            throw new RuntimeException('Choose x, bluesky, dev-null, linkedin or threads.');
        }
    }

    private static function ask(string $question): string
    {
        if (!stream_isatty(STDIN)) {
            throw new RuntimeException(
                'Name each site and handle explicitly, such as: setup threads --threads=YOUR_HANDLE.',
            );
        }
        fwrite(STDERR, $question);
        return (string) fgets(STDIN);
    }

    private static function parse(array $arguments): array
    {
        $words = [];
        $options = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '-h') {
                $argument = '--help';
            }
            if (!str_starts_with($argument, '-')) {
                $words[] = $argument;
                continue;
            }
            [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);
            if (array_key_exists($key, $options)) {
                throw new RuntimeException('Do not repeat the same option.');
            }
            if (in_array($key, self::FLAGS, true) && $value === null) {
                $options[$key] = true;
                continue;
            }
            if (in_array($key, self::VALUES, true)) {
                $value ??= $arguments[++$i] ?? null;
                if ($value === null || $value === '' || str_starts_with($value, '--')) {
                    throw new RuntimeException('An option is missing its value. See --help.');
                }
                $options[$key] = $value;
                continue;
            }
            throw new RuntimeException('Unknown option. See --help.');
        }
        return [$words, $options];
    }

    private static function help(): string
    {
        return <<<'HELP'
            Postslop remote account client

              cpx bangnokia/postslop setup [SITES] --to=https://YOUR_SERVER
              cpx bangnokia/postslop accounts [--refresh] --to=https://YOUR_SERVER
              cpx bangnokia/postslop accounts check|enable|disable|remove SITE --to=https://YOUR_SERVER

            Sites: x, bluesky, dev-null, linkedin, threads. Sign in in your browser first.
            Setup asks for each handle; blank skips a site when no sites were named.
              --threads=HANDLE (or --x, --bluesky, --dev-null, --linkedin) skips that prompt
              --browser=chrome|firefox  Chrome on macOS is the default; Firefox also works on Linux
              --profile=DIR            Choose a browser profile
              --check-only             Read browser sessions without sending or saving them
              --to=URL                 Your Postslop server (or POSTSLOP_SERVER_URL)
              --token=TOKEN            Access token (prefer POSTSLOP_REMOTE_TOKEN)
              --json                   Machine-readable output, never browser session values
              --version                Show the client version

            Requires PHP 8.2+ with curl, OpenSSL, PDO SQLite and iconv. No Python or Node required. Sessions go only to --to.
            This client stores no accounts, tokens or posts locally. Use the web client or Raycast to publish.
            The full local CLI and server remain in server/bin/postslop in the source repository.

            HELP;
    }
}
