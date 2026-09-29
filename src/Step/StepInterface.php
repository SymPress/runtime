<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;

interface StepInterface
{
    public const int ERROR = 1;
    public const int SUCCESS = 2;
    public const int NONE = 4;

    public function name(): string;

    public function success(): string;

    public function error(): string;

    public function allowed(Config $config, Paths $paths): bool;

    public function run(Config $config, Paths $paths): int;
}
