<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
use App\Core\Publishing;
use App\Social\SessionStore;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\HttpClient\Response;
use Postslop\Client\BrowserSessions;
use Psr\Http\Client\ClientExceptionInterface;

// Each step of the import is a guard with its own message.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
final readonly class AccountSetup extends CliCommand
{
    private const array SITES = BrowserSessions::SITES;

    public function __construct(
        private Publishing $publishing,
        private SessionStore $sessions,
        private Client $http,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Import your signed-in X, Bluesky, dev-null.sh, LinkedIn and Threads sessions from Chrome or Firefox
            Usage: postslop setup [x] [bluesky] [dev-null] [linkedin] [threads] [--browser=chrome|firefox] [--x=HANDLE]
                                  [--bluesky=HANDLE] [--dev-null=HANDLE] [--linkedin=HANDLE] [--threads=HANDLE]
                                  [--profile=DIR] [--to=URL --token=TOKEN]
            Name sites to import only those; with none, each site's handle is asked for, and an empty
            answer skips that site. First check in the
            browser which account is signed in on each site. Postslop refuses a session that belongs
            to another account than the handle you give.
            Options:
              --browser=NAME chrome (default) or firefox. Chrome asks macOS once for
                             "Chrome Safe Storage" to decrypt its cookies: choose Allow
              --x=HANDLE, --bluesky=HANDLE, --dev-null=HANDLE, --linkedin=HANDLE, --threads=HANDLE
                             The account signed in on each site; asked for when left out. LinkedIn's
                             handle is the name in your profile address, linkedin.com/in/NAME
              --profile=DIR  The profile folder (default: the browser's last used or default profile)
              --check-only   Read the sessions without saving them
              --to=URL       Send the sessions to Postslop on another machine instead of saving them here,
                             such as --to=https://postslop.example.com. Needs https, except on localhost,
                             a private network or Tailscale
              --token=TOKEN  That server's POSTSLOP_TOKEN (default: the POSTSLOP_REMOTE_TOKEN variable)
            Without --to nothing leaves this machine. Sessions are saved in storage/sessions.json,
            readable only by you.
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $browser = self::value($input, 'browser') ?? 'chrome';
        if (!in_array($browser, ['chrome', 'firefox'], true)) {
            throw Failure::invalid('Choose --browser=chrome or --browser=firefox.');
        }
        $profile = self::value($input, 'profile');
        $output->line('Importing from ' . ($browser === 'chrome' ? 'Chrome' : 'Firefox') . '.');
        $sites = $input->arguments() === [] ? array_keys(self::SITES) : array_values(array_unique($input->arguments()));
        foreach ($sites as $site) {
            if (!array_key_exists($site, self::SITES)) {
                throw Failure::invalid(
                    'There is no ' . $site . ' site. Name x, bluesky, dev-null, linkedin or threads.',
                );
            }
        }
        $remote = self::remote($input);
        $handles = [];
        foreach (array_intersect_key(self::SITES, array_flip($sites)) as $site => $label) {
            $handle = self::value($input, $site) ?? self::ask('Signed-in ' . $label . ' handle: @');
            $handle = ltrim(trim($handle), '@');
            // Asked about every site, an empty answer leaves that one out.
            if ($handle === '' && $input->arguments() === [] && self::value($input, $site) === null) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9._-]+$/D', $handle) !== 1) {
                throw Failure::invalid('Give the ' . $label . ' handle, such as --' . $site . '=yourname.');
            }
            $handles[$site] = $handle;
        }
        if ($handles === []) {
            throw Failure::invalid('Give the handle of at least one site.');
        }
        try {
            $report = BrowserSessions::read($browser, $profile, $handles);
        } catch (\RuntimeException $error) {
            throw Failure::invalid($error->getMessage());
        }
        $output->line('Profile: ' . ($report['profile'] ?? $profile));
        foreach ($handles as $site => $handle) {
            $account = $report['accounts'][$site] ?? [];
            $label = self::SITES[$site];
            $output->line(sprintf('  %-12s @%s, %d cookies', $label, $handle, $account['cookieCount'] ?? 0));
        }
        if ($input->flag('check-only')) {
            $output->line('Checked only; nothing was saved.');
            return self::OK;
        }
        $imported = array_intersect_key($report['accounts'], $handles);
        $accounts = [];
        if ($remote !== null) {
            $output->line('Sending to ' . $remote['url'] . ' and checking each session signs in there…');
            foreach ($imported as $site => $account) {
                $accounts = $this->push($remote, $site, $account, $browser);
            }
        }
        if ($remote === null) {
            $this->sessions->import($imported, $browser);
            $output->line('Checking each session signs in as the account you named…');
            $accounts = $this->publishing->refreshAccounts();
        }
        $ready = true;
        foreach ($accounts['platforms'] ?? [] as $account) {
            if (!array_key_exists($account['id'], $handles)) {
                continue;
            }
            $ready = $ready && $account['available'];
            $output->line(sprintf(
                '  %-12s %s',
                $account['label'],
                $account['available'] ? 'ready as @' . $account['handle'] : $account['reason'] ?? 'unavailable',
            ));
        }
        $done = $remote !== null ? 'All set on ' . $remote['url'] . '.' : 'All set. Try: postslop post "Hello" --now';
        $output->line($ready ? $done : 'Fix the accounts above, then run postslop setup again.');
        return $ready ? self::OK : self::FAILED;
    }

    /** @return ?array{url: string, token: string} */
    private static function remote(Input $input): ?array
    {
        $url = self::value($input, 'to');
        if ($url === null) {
            return null;
        }
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '') {
            throw Failure::invalid('Give the server as --to=https://host[:port].');
        }
        // Sessions sign in as you, so they travel in the clear only inside a private network.
        if (($parts['scheme'] ?? '') === 'http' && !self::private($host)) {
            throw Failure::invalid(
                'Send sessions over https, or to a server on localhost, a private network or Tailscale.',
            );
        }
        $token = self::value($input, 'token') ?? (string) getenv('POSTSLOP_REMOTE_TOKEN');
        return ['url' => $url, 'token' => $token];
    }

    private static function private(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.ts.net')) {
            return true;
        }
        $address = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        // Tailscale addresses come from 100.64.0.0/10.
        $long = ip2long($address);
        return $long !== false && ($long & 0xFFC0_0000) === (ip2long('100.64.0.0') & 0xFFC0_0000);
    }

    /** @param array{url: string, token: string} $remote */
    private function push(array $remote, string $site, array $account, string $browser): array
    {
        try {
            $response = $this->http->timeout(120)->put(
                $remote['url'] . '/api/v1/accounts/' . $site,
                json: ['handle' => $account['handle'], 'session' => $account['session'], 'source' => $browser],
                headers: [
                    'Accept' => 'application/json',
                    ...($remote['token'] !== '' ? ['Authorization' => 'Bearer ' . $remote['token']] : []),
                ],
            );
        } catch (ClientExceptionInterface) {
            throw Failure::invalid(
                'Could not reach ' . $remote['url'] . '. Check the address and that Postslop runs there.',
            );
        }
        $status = $response->getStatusCode();
        if ($status === 401) {
            throw Failure::invalid('That server refused the token. Pass its POSTSLOP_TOKEN with --token=….');
        }
        $data = self::body($response);
        if (!$response->ok() || !is_array($data)) {
            $message = is_array($data) ? $data['error']['message'] ?? null : null;
            throw Failure::invalid(
                'The server answered HTTP ' . $status . (is_string($message) ? ': ' . $message : '.'),
            );
        }
        return $data;
    }

    private static function body(Response $response): mixed
    {
        try {
            return $response->json();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ask(string $question): string
    {
        if (!stream_isatty(STDIN)) {
            throw Failure::invalid(
                'Name the sites and their handles, such as: postslop setup linkedin --linkedin=NAME',
            );
        }
        fwrite(STDOUT, $question);
        return (string) fgets(STDIN);
    }
}
