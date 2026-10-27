<?php

declare(strict_types=1);

namespace Postslop\Client;

use RuntimeException;

/** A thin HTTP client: account verification and management stay in the server's core. */
// Keep destination, transport and response checks together at the credential boundary.
// @mago-expect lint:cyclomatic-complexity
final class Remote
{
    public readonly string $url;

    public function __construct(
        string $url,
        #[\SensitiveParameter]
        private readonly string $token,
    ) {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || $host === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ($parts['path'] ?? '') !== ''
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new RuntimeException(
                'Give the Postslop server as --to=https://host[:port], without a path, password or query.',
            );
        }
        if ($parts['scheme'] === 'http' && !self::privateHost($host)) {
            throw new RuntimeException('Use HTTPS, or HTTP on localhost or a private network.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $token) === 1) {
            throw new RuntimeException('The access token contains invalid characters.');
        }
        $this->url = $url;
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Enable the PHP curl extension to connect to Postslop.');
        }
        $curl = curl_init($this->url . '/api/v1' . $path);
        $headers = ['Accept: application/json', 'User-Agent: Postslop-Client/0.1'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            // Never forward credentials to a redirect target, or retry an ambiguous import.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        try {
            $raw = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
        if ($raw === false) {
            throw new RuntimeException(
                'Could not reach Postslop. Check the server URL, connection and HTTPS certificate.',
            );
        }
        if ($code === 401) {
            throw new RuntimeException(
                'Postslop refused the access token. Set POSTSLOP_REMOTE_TOKEN to the server’s POSTSLOP_TOKEN.',
            );
        }
        if ($code >= 300 && $code < 400) {
            throw new RuntimeException(
                'Postslop redirected the request. Use its final server URL; credentials were not forwarded.',
            );
        }
        if ($code < 200 || $code >= 300) {
            // Never echo a raw error page, which could contain request headers or session data.
            throw new RuntimeException('Postslop returned HTTP ' . $code . '. Check the server and try again.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('The server did not return a Postslop JSON response.');
        }
        return $data;
    }

    private static function privateHost(string $host): bool
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
        $long = ip2long($address);
        return $long !== false && ($long & 0xFFC0_0000) === (ip2long('100.64.0.0') & 0xFFC0_0000);
    }
}
