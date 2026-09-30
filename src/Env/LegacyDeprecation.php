<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

/** Reports names only, without exposing values or contaminating response bodies. */
final class LegacyDeprecation
{
    public static function report(string $name, string $replacement): void
    {
        /** @var array<string, true> $reported */
        static $reported = [];
        if (isset($reported[$name])) {
            return;
        }
        $reported[$name] = true;
        if (function_exists('sympress_runtime_deprecated')) {
            sympress_runtime_deprecated($name, $replacement);
            return;
        }
        // Never put deprecations into HTTP or machine-readable command output.
        error_log($name . ' is deprecated; use ' . $replacement . '.');
    }
}
