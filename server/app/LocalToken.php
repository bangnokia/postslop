<?php

declare(strict_types=1);

namespace App;

final readonly class LocalToken
{
    public function __construct(
        private string $path,
    ) {}

    public function read(): string
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException('Run php scripts/setup.php to create the local access token.');
        }
        $token = trim(file_get_contents($this->path));
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            throw new \RuntimeException('The local access token is invalid.');
        }
        return $token;
    }
}
