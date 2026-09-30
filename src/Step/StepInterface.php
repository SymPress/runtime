<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;

/** @api */
interface StepInterface
{
    public const int ERROR = 1;
    public const int SUCCESS = 2;
    public const int NONE = 4;

    /** @api */
    public function name(): string;

    /** @api */
    public function success(): string;

    /** @api */
    public function error(): string;

    /** @api */
    public function allowed(Config $config, Paths $paths): bool;

    /** @api */
    public function run(Config $config, Paths $paths): int;
}
