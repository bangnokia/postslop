<?php

declare(strict_types=1);

namespace App;

/**
 * The password for the web client and the HTTP API: POSTSLOP_TOKEN in .env. Without one, both are open to anyone
 * who can reach the server.
 */
final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        private string $token,
    ) {}

    public function required(): bool
    {
        return $this->read() !== '';
    }

    public function read(): string
    {
        return trim($this->token);
    }
}
