<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Paths;

/** @api */
interface PhpTool
{
    /** @api */
    public function niceName(): string;

    /** @api */
    public function packageName(): string;

    /** @api */
    public function pharUrl(): string;

    /** @api */
    public function pharTarget(Paths $paths): string;

    /** @api */
    public function filesystemBootstrap(string $packageVendorPath): string;

    /** @api */
    public function minVersion(): string;

    /** @api */
    public function checkPhar(string $pharPath, Io $io): bool;

    /** @api */
    public function prepareCommand(string $command, Paths $paths, Io $io): string;
}
