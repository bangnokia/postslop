<?php

declare(strict_types=1);

namespace App;

/**
 * The password for the web client and the HTTP API: setup writes a random one, and you may replace it with your own.
 */
final readonly class AccessToken
{
    public function __construct(
        private string $path,
    ) {}

    public function read(): string
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException('Run php scripts/setup.php to create the access token.');
        }
        $token = trim(file_get_contents($this->path));
        if ($token === '') {
            throw new \RuntimeException('The access token is empty.');
        }
        return $token;
    }
}
