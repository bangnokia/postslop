<?php

declare(strict_types=1);

namespace App\Social;

use Crab\Contracts\HttpClient\CookieJar as CookieJarContract;
use Crab\Http\Uri;
use Crab\HttpClient\CookieJar;
use Psr\Http\Message\UriInterface;

/** Keeps Laravel's renewed session and CSRF cookies in the imported browser-state format. */
// Cookie attributes are independent protocol conditions; keep their persistence in one place.
// @mago-expect lint:cyclomatic-complexity
final class DevNullCookieJar implements CookieJarContract
{
    private CookieJar $jar;

    public function __construct(
        private array $state,
        private readonly string $baseUrl,
    ) {
        $this->jar = new CookieJar();
        $host = parse_url($baseUrl, PHP_URL_HOST);
        foreach ($state['cookies'] ?? [] as $cookie) {
            if (ltrim((string) ($cookie['domain'] ?? ''), '.') !== $host) {
                continue;
            }
            $line = $cookie['name'] . '=' . $cookie['value'] . '; Path=' . ($cookie['path'] ?? '/');
            if (($cookie['expires'] ?? -1) > 0) {
                $line .= '; Expires=' . gmdate('D, d M Y H:i:s', (int) $cookie['expires']) . ' GMT';
            }
            if ($cookie['secure'] ?? false) {
                $line .= '; Secure';
            }
            $this->jar->store(new Uri($baseUrl), [$line]);
        }
    }

    public function store(UriInterface $from, array $setCookie): void
    {
        $this->jar->store($from, $setCookie);
        $values = $this->jar->for($this->baseUrl . '/');
        foreach ($setCookie as $line) {
            $parts = explode(';', $line);
            $name = trim(explode('=', array_shift($parts), 2)[0]);
            $attributes = [];
            foreach ($parts as $part) {
                [$key, $value] = [...explode('=', trim($part), 2), ''];
                $attributes[strtolower($key)] = $value;
            }
            // Only root cookies can carry this site's login or XSRF token.
            if (($attributes['path'] ?? '/') !== '/') {
                continue;
            }
            $this->state['cookies'] = array_values(array_filter(
                $this->state['cookies'] ?? [],
                static fn(array $cookie): bool => ($cookie['name'] ?? '') !== $name,
            ));
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $expires = -1;
            if (array_key_exists('expires', $attributes)) {
                $parsed = strtotime($attributes['expires']);
                $expires = $parsed !== false ? $parsed : -1;
            }
            if (array_key_exists('max-age', $attributes)) {
                $expires = time() + (int) $attributes['max-age'];
            }
            $this->state['cookies'][] = [
                'name' => $name,
                'value' => $values[$name],
                'domain' => $from->getHost(),
                'path' => '/',
                'expires' => $expires,
                'httpOnly' => array_key_exists('httponly', $attributes),
                'secure' => array_key_exists('secure', $attributes),
                'sameSite' => ucfirst(strtolower($attributes['samesite'] ?? 'Lax')),
            ];
        }
    }

    public function header(UriInterface $to): string
    {
        return $this->jar->header($to);
    }

    public function csrf(): string
    {
        return rawurldecode($this->jar->for($this->baseUrl . '/')['XSRF-TOKEN'] ?? '');
    }

    public function state(): array
    {
        return $this->state;
    }
}
