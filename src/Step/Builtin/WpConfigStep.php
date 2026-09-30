<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\WpConfigGenerator;
use SymPress\Runtime\Step\BlockingStepInterface;
use SymPress\Runtime\Step\FileCreationStepInterface;

/** @internal */
final readonly class WpConfigStep implements BlockingStepInterface, FileCreationStepInterface
{
    public function __construct(private WpConfigGenerator $generator, private Selection $selection)
    {
    }

    public function name(): string
    {
        return 'wpconfig';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function targetPath(Paths $paths): string
    {
        return $this->generator->target();
    }

    public function run(Config $config, Paths $paths): int
    {
        return $this->generator->generate($this->selection->force, primaryApproved: true) ? self::SUCCESS : self::ERROR;
    }

    public function success(): string
    {
        return 'WordPress configuration saved.';
    }

    public function error(): string
    {
        return 'Cannot save WordPress configuration.';
    }
}
