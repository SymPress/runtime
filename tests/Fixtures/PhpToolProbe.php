<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Fixtures;

use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Process\PhpTool;

final class PhpToolProbe implements PhpTool
{
    public string $url = 'https://example.test/tool.phar';
    public string $expected = 'valid tool';

    public function niceName(): string
    {
        return 'Fixture Tool';
    }

    public function packageName(): string
    {
        return 'fixture/tool';
    }

    public function pharUrl(): string
    {
        return $this->url;
    }

    public function pharTarget(Paths $paths): string
    {
        return $paths->root('fixture.phar');
    }

    public function filesystemBootstrap(string $packageVendorPath): string
    {
        return $packageVendorPath . '/boot.php';
    }

    public function minVersion(): string
    {
        return '1.0.0';
    }

    public function checkPhar(string $pharPath, Io $io): bool
    {
        return file_get_contents($pharPath) === $this->expected;
    }

    public function prepareCommand(string $command, Paths $paths, Io $io): string
    {
        return $command;
    }
}
