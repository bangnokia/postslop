<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A refusal the caller can act on. Each front door translates the kind:
 * the API into an HTTP status, the CLI into an exit code.
 */
final class Failure extends \RuntimeException
{
    public const string INVALID = 'invalid';
    public const string NOT_FOUND = 'not_found';
    public const string CONFLICT = 'conflict';
    public const string BUSY = 'busy';

    private function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message);
    }

    public static function notFound(string $message = 'No post has this ID.'): self
    {
        return new self(self::NOT_FOUND, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(self::CONFLICT, $message);
    }

    public static function busy(string $message = 'Another post is being published. Keep this draft and try again shortly.'): self
    {
        return new self(self::BUSY, $message);
    }
}
