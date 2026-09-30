<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

use RuntimeException;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Download\PharInstaller;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\PackageFinder;

final readonly class PhpToolProcessFactory
{
    public function __construct(private Paths $paths, private Io $io, private PharInstaller $installer, private PackageFinder $packages, private PhpProcess $php)
    {
    }

    public function create(PhpTool $tool, ?string $phpPath = null): PhpToolProcess
    {
        $phpPath ??= PHP_BINARY;
        if (!is_file($phpPath) || !is_executable($phpPath)) {
            throw new RuntimeException('PHP executable is not available.');
        }
        $path = $this->packageBootstrap($tool);
        if ($path === '') {
            $target = $tool->pharTarget($this->paths);
            $local = is_file($target) && is_readable($target);
            if ($local && $tool instanceof WpCliTool && !$tool->checkLocalPhar($target, $this->io)) {
                throw new RuntimeException('Local WP-CLI PHAR failed integrity validation.');
            }
            $path = $local ? $target : $this->installer->install($tool, $target);
        }
        if ($path === '') {
            throw new RuntimeException('PHP tool is unavailable; package, local phar and permitted download could not resolve it.');
        }

        return new PhpToolProcess($this->php->withExecutable($phpPath), $tool, $path, $this->paths, $this->io);
    }

    private function packageBootstrap(PhpTool $tool): string
    {
        $package = $this->packages->findByName($tool->packageName());
        if ($package === null) {
            return '';
        }
        if ($tool->minVersion() !== '' && version_compare($package->getVersion(), $tool->minVersion(), '<')) {
            $this->io->error('Installed PHP tool is below its minimum supported version.');

            return '';
        }
        $bootstrap = $tool->filesystemBootstrap($package->getInstallPath());

        return is_file($bootstrap) && is_readable($bootstrap) ? $bootstrap : '';
    }
}
