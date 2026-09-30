<?php

declare(strict_types=1);

namespace Example\Build;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\FileCreationStepInterface;

final class WriteBuildMarker implements FileCreationStepInterface
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    public function name(): string
    {
        return 'buildmarker';
    }

    public function success(): string
    {
        return 'Build marker written.';
    }

    public function error(): string
    {
        return 'Build marker could not be written.';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function targetPath(Paths $paths): string
    {
        return $paths->root('var/build-marker.txt');
    }

    public function run(Config $config, Paths $paths): int
    {
        return $this->files->writeContent("ready\n", $this->targetPath($paths))
            ? self::SUCCESS
            : self::ERROR;
    }
}
