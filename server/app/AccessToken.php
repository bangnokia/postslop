<?php

declare(strict_types=1);

namespace App;

/**
 * The password for the web client and the HTTP API: POSTSLOP_TOKEN in .env. Setup writes a random one, and you may
 * replace it with your own.
 */
final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        private string $token,
    ) {}

    public function read(): string
    {
        $token = trim($this->token);
        if ($token === '') {
            throw new \RuntimeException('Set POSTSLOP_TOKEN in .env, or run php scripts/setup.php to create one.');
        }
        return $token;
    }
}
