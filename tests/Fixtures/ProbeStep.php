<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Fixtures;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\AsRuntimeStep;
use SymPress\Runtime\Step\BlockingStepInterface;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\Step\FileCreationStepInterface;
use SymPress\Runtime\Step\OptionalStepInterface;
use SymPress\Runtime\Step\PostProcessStepInterface;

#[AsRuntimeStep('probe', 20)]
final readonly class ProbeStep implements BlockingStepInterface, ConditionalStepInterface, FileCreationStepInterface, OptionalStepInterface, PostProcessStepInterface
{
    public function __construct(private StepTrace $trace)
    {
    }

    public function name(): string
    {
        return 'probe';
    }

    public function success(): string
    {
        return 'probe succeeded';
    }

    public function error(): string
    {
        return 'probe failed';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        $this->trace->events[] = 'allowed';

        return $this->trace->allowed;
    }

    public function targetPath(Paths $paths): string
    {
        $this->trace->events[] = 'target';

        return $paths->root('probe.txt');
    }

    public function conditionsNotMet(): string
    {
        return 'condition not met';
    }

    public function askConfirm(Config $config, Io $io): bool
    {
        $this->trace->events[] = 'ask';

        return $this->trace->confirmed;
    }

    public function skipped(): string
    {
        return 'not confirmed';
    }

    public function run(Config $config, Paths $paths): int
    {
        $this->trace->events[] = 'run';

        return $this->trace->result;
    }

    public function postProcess(Io $io): void
    {
        $this->trace->events[] = 'post';
    }
}
