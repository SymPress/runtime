<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Paths;

interface PhpTool
{
    public function niceName(): string;

    public function packageName(): string;

    public function pharUrl(): string;

    public function pharTarget(Paths $paths): string;

    public function filesystemBootstrap(string $packageVendorPath): string;

    public function minVersion(): string;

    public function checkPhar(string $pharPath, Io $io): bool;

    public function prepareCommand(string $command, Paths $paths, Io $io): string;
}
