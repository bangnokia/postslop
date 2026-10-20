<?php

declare(strict_types=1);

namespace App;

final class PrivateFiles
{
    public static function directory(string $directory): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('Private storage must not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
            throw new \RuntimeException('Could not create private storage.');
        }
        chmod($directory, 0o700);
    }

    public static function json(string $path, array $data): void
    {
        self::write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function write(string $path, string $contents): void
    {
        self::directory(dirname($path));
        $temporary = tempnam(dirname($path), '.postslop-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not create a private state file.');
        }
        try {
            chmod($temporary, 0o600);
            if (file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $path)) {
                throw new \RuntimeException('Could not save private state.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
