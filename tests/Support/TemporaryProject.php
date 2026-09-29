<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

abstract class TemporaryProject extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sympress-runtime-test-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    protected function write(string $path, string $contents): void
    {
        (new Filesystem())->dumpFile($this->root . '/' . $path, $contents);
    }
}
