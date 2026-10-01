<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

/** @internal */
final class EnvCacheValues
{
    /**
     * @param array<string, array{string, bool|int|float|string|null}> $cache
     * @param array<string, string> $raw
     * @param array<string, string|null> $types
     * @param callable(string): bool $external
     * @return array<string, array{string, bool|int|float|string|null}>
     */
    public static function fileOwned(array $cache, array $raw, array $types, callable $external): array
    {
        $values = array_intersect_key($cache, $raw);
        $filters = null;
        foreach (array_keys($values) as $name) {
            if (!$external($name)) {
                continue;
            }
            $type = $types[$name] ?? null;
            $value = $raw[$name];
            $values[$name] = [$value, $type === null ? $value : ($filters ??= new Filters())->filter($type, $value)];
        }

        return $values;
    }
}
