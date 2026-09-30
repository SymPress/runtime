<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Fixtures;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\StepInterface;

final readonly class TailStep implements StepInterface
{
    public function __construct(private StepTrace $trace)
    {
    }

    public function name(): string
    {
        return 'tail';
    }

    public function success(): string
    {
        return 'tail succeeded';
    }

    public function error(): string
    {
        return 'tail failed';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function run(Config $config, Paths $paths): int
    {
        $this->trace->events[] = 'tail';

        return self::SUCCESS;
    }
}
