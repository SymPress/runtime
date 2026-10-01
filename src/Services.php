<?php

declare(strict_types=1);

namespace SymPress\Runtime;

use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Compatibility\ComposerConfiguration;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Download\PharInstaller;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\Salter;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Process\PhpProcess;
use SymPress\Runtime\Process\PhpToolProcess;
use SymPress\Runtime\Process\PhpToolProcessFactory;
use SymPress\Runtime\Process\SystemProcess;
use SymPress\Runtime\Process\WpCliTool;
use Symfony\Component\Process\ExecutableFinder;

/** @api */
final class Services
{
    private ?PhpToolProcess $wpCli = null;

    /** @internal */
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
        private MuPluginList $muPlugins,
        private UrlDownloader $downloads,
        private PharInstaller $phars,
        private PhpToolProcessFactory $tools,
        private WpCliTool $wpCliTool,
        private EnvReader $environment,
        private DbChecker $database,
    ) {
    }

    /** @api */
    public function config(): Config
    {
        return $this->configuration;
    }

    /** @api */
    public function paths(): Paths
    {
        return $this->projectPaths;
    }

    /** @api */
    public function io(): Io
    {
        return $this->consoleIo;
    }

    /** @api */
    public function filesystem(): Filesystem
    {
        return $this->files;
    }

    /** @internal */
    public function fileContentBuilder(): FileContentBuilder
    {
        return $this->builder;
    }

    /** @internal */
    public function runContext(): RunContext
    {
        return $this->context;
    }

    /** @internal */
    public function packageFinder(): PackageFinder
    {
        return $this->packages;
    }

    /** @internal */
    public function muPluginsList(): MuPluginList
    {
        return $this->muPlugins;
    }

    /** @api */
    public function urlDownloader(): UrlDownloader
    {
        return $this->downloads;
    }

    /** @internal */
    public function pharInstaller(): PharInstaller
    {
        return $this->phars;
    }

    /** @api */
    public function phpToolProcessFactory(): PhpToolProcessFactory
    {
        return $this->tools;
    }

    /** @api */
    public function wpCliProcess(): PhpToolProcess
    {
        return $this->wpCli ??= $this->tools->create($this->wpCliTool);
    }

    /** @api */
    public function env(): EnvReader
    {
        return $this->environment;
    }

    /** @api */
    public function dbChecker(): DbChecker
    {
        return $this->database;
    }

    /** @api */
    public function systemProcess(): SystemProcess
    {
        return $this->system;
    }

    /** @internal */
    public function phpProcess(): PhpProcess
    {
        return $this->php;
    }

    /** @internal */
    public function executableFinder(): ExecutableFinder
    {
        return $this->executables;
    }

    /** @internal */
    public function overwriteHelper(): OverwritePolicy
    {
        return $this->overwrite;
    }

    /** @internal */
    public function salter(): Salter
    {
        return $this->salts;
    }

    /** @api */
    public function wpConfigSectionEditor(): WpConfigSectionEditor
    {
        return $this->sections;
    }

    /** @internal */
    public function composerIo(): Io
    {
        return $this->consoleIo;
    }

    /** @internal */
    public function composerFilesystem(): Filesystem
    {
        return $this->files;
    }

    /** @internal */
    public function composerConfig(): ComposerConfiguration
    {
        return $this->composerSettings;
    }
}
