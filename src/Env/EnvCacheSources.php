<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

/** @internal */
final class EnvCacheSources
{
    public static function matches(mixed $data): bool
    {
        if (!is_array($data) || !is_array($data['sources'] ?? null) || $data['sources'] === []) {
            return false;
        }
        foreach ($data['sources'] as $path => $signature) {
            if (!is_string($path) || self::signature($path) !== $signature) {
                return false;
            }
        }

        return true;
    }

    /** @return array{int, int}|null */
    public static function signature(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? null : [$stat['size'], $stat['mtime']];
    }
}
