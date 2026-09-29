<?php

declare(strict_types=1);

namespace SymPress\Runtime;

use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Compatibility\ComposerConfiguration;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\Salter;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Process\PhpProcess;
use SymPress\Runtime\Process\SystemProcess;
use Symfony\Component\Process\ExecutableFinder;

final readonly class Services
{
    public function __construct(
        private Config $configuration,
        private Paths $projectPaths,
        private Io $consoleIo,
        private Filesystem $files,
        private FileContentBuilder $builder,
        private RunContext $context,
        private PackageFinder $packages,
        private SystemProcess $system,
        private PhpProcess $php,
        private ExecutableFinder $executables,
        private OverwritePolicy $overwrite,
        private Salter $salts,
        private WpConfigSectionEditor $sections,
        private ComposerConfiguration $composerSettings,
    ) {
    }

    public function config(): Config
    {
        return $this->configuration;
    }

    public function paths(): Paths
    {
        return $this->projectPaths;
    }

    public function io(): Io
    {
        return $this->consoleIo;
    }

    public function filesystem(): Filesystem
    {
        return $this->files;
    }

    public function fileContentBuilder(): FileContentBuilder
    {
        return $this->builder;
    }

    public function runContext(): RunContext
    {
        return $this->context;
    }

    public function packageFinder(): PackageFinder
    {
        return $this->packages;
    }

    public function systemProcess(): SystemProcess
    {
        return $this->system;
    }

    public function phpProcess(): PhpProcess
    {
        return $this->php;
    }

    public function executableFinder(): ExecutableFinder
    {
        return $this->executables;
    }

    public function overwriteHelper(): OverwritePolicy
    {
        return $this->overwrite;
    }

    public function salter(): Salter
    {
        return $this->salts;
    }

    public function wpConfigSectionEditor(): WpConfigSectionEditor
    {
        return $this->sections;
    }

    public function composerIo(): Io
    {
        return $this->consoleIo;
    }

    public function composerFilesystem(): Filesystem
    {
        return $this->files;
    }

    public function composerConfig(): ComposerConfiguration
    {
        return $this->composerSettings;
    }
}
