<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\ArtifactWriter;
use SymPress\Runtime\Kernel\KernelPaths;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Component\Filesystem\Filesystem;

final readonly class KernelCacheStep implements StepInterface
{
    public function __construct(private KernelPaths $kernel, private ProjectBoundary $boundary, private ArtifactWriter $writer, private Io $io)
    {
    }

    public function name(): string
    {
        return 'kernel-cache';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function run(Config $config, Paths $paths): int
    {
        $targets = $this->kernel->clearTargets();
        foreach ($targets as $target) {
            $this->boundary->assertWritablePath($target);
        }
        $id = $config['kernel-build-id']->unwrap();
        if (is_string($id)) {
            $this->boundary->assertWritablePath($this->kernel->buildIdFile());
            $data = json_encode(['environment' => $this->kernel->environment(), 'id' => $id], JSON_THROW_ON_ERROR) . "\n";
            if (!$this->writer->write($this->kernel->buildIdFile(), $data)) {
                throw new RuntimeException('Cannot save the kernel build ID.');
            }
        }
        (new Filesystem())->remove($targets);
        if (is_string($id)) {
            $this->io->write('SYMPRESS_KERNEL_BUILD_ID=' . $id);
        }

        return self::SUCCESS;
    }

    public function success(): string
    {
        return 'Selected environment kernel cache cleared.';
    }

    public function error(): string
    {
        return 'Cannot clear the selected environment kernel cache.';
    }
}
