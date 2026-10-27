<?php

declare(strict_types=1);

namespace App\Social;

/** A useful, credential-free explanation that is safe to show in the composer. */
final class RemoteSessionFailure extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }
}
