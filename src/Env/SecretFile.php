<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use RuntimeException;

/** @internal */
final class SecretFile
{
    public static function read(string $name, string $file): string
    {
        clearstatcache(true, $file);
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Cannot read secret file for ' . $name . '.');
        }
        $secret = @file_get_contents($file);
        if ($secret === false) {
            throw new RuntimeException('Cannot read secret file for ' . $name . '.');
        }

        return str_ends_with($secret, "\n") ? substr($secret, 0, -1) : $secret;
    }
}
