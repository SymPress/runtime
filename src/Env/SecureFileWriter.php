<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use InvalidArgumentException;

/**
 * Atomic writes which never send content to tempnam's fallback directory.
 *
 * @internal
 */
final class SecureFileWriter
{
    /** @var array<string, true> */
    private static array $unwritable = [];

    public static function canWrite(string $file): bool
    {
        $parent = dirname($file);
        if (isset(self::$unwritable[$parent])) {
            return false;
        }
        if (!is_dir($parent) || !is_writable($parent) || realpath($parent) === false) {
            self::$unwritable[$parent] = true;

            return false;
        }

        return !is_link($file) && !is_dir($file);
    }

    public static function write(string $file, string $content, int $mode = 0600, bool $invalidateOpcache = false): bool
    {
        if (!in_array($mode, [0600, 0640], true)) {
            throw new InvalidArgumentException('Generated file mode must be 0600 or 0640.');
        }
        if (!self::canWrite($file)) {
            return false;
        }
        $parent = realpath(dirname($file));
        if ($parent === false) {
            return false;
        }
        if (is_file($file) && file_get_contents($file) === $content) {
            return @chmod($file, $mode);
        }
        $temporary = @tempnam($parent, '.sympress-private-');
        if ($temporary === false) {
            self::$unwritable[dirname($file)] = true;

            return false;
        }
        try {
            // Validate before chmod or writing any potentially secret content.
            if (realpath(dirname($temporary)) !== $parent || realpath(dirname($file)) !== $parent) {
                self::$unwritable[dirname($file)] = true;

                return false;
            }

            $written = @chmod($temporary, $mode)
                && @file_put_contents($temporary, $content) === strlen($content)
                && @rename($temporary, $file);
            if ($written && $invalidateOpcache && function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }

            return $written;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
