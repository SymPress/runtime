<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;

/** Intentionally extensible: legacy extensions use this no-op base class. */
class NullStep implements StepInterface
{
    public function name(): string
    {
        return '';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return false;
    }

    public function run(Config $config, Paths $paths): int
    {
        return self::NONE;
    }

    public function success(): string
    {
        return '';
    }

    public function error(): string
    {
        return '';
    }
}
