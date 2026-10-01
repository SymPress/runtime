<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Step\BlockingStepInterface;
use SymPress\Runtime\Step\PostProcessStepInterface;
use Symfony\Component\Filesystem\Path;

/** @internal */
final class CheckPathsStep implements BlockingStepInterface, PostProcessStepInterface
{
    private string $failure = '';
    private bool $themes = true;
    private bool $publicEnvironment = false;

    public function __construct(private readonly Filesystem $filesystem, private readonly ProjectBoundary $boundary)
    {
    }

    public function name(): string
    {
        return 'checkpaths';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function run(Config $config, Paths $paths): int
    {
        $environment = $config['env-dir']->unwrapOrFallback($paths->root());
        $this->publicEnvironment = is_string($environment) && ($environment === $paths->wpParent() || Path::isBasePath($paths->wpParent(), $environment));
        $this->boundary->assertWritablePath($paths->wpContent());
        $this->filesystem->createDir($paths->wpContent());
        if ($config['move-content']->not(true)) {
            $this->boundary->assertWritablePath($paths->wpContent('themes'));
            $this->boundary->assertWritablePath($paths->wpContent('plugins'));
            $this->themes = $this->filesystem->createDir($paths->wpContent('themes'));
            $this->filesystem->createDir($paths->wpContent('plugins'));
        }
        $errors = [];
        foreach (['Composer autoload' => $paths->vendor('autoload.php'), 'WordPress' => $paths->wp('wp-settings.php')] as $name => $file) {
            if (is_file($file) && is_readable($file)) {
                continue;
            }
            $errors[] = $name . ' file is missing or unreadable: ' . $file;
        }
        if (!is_dir($paths->wpContent())) {
            $errors[] = 'WordPress content directory is unavailable.';
        }
        $this->failure = implode("\n", $errors);

        return $errors === [] ? self::SUCCESS : self::ERROR;
    }

    public function error(): string
    {
        return $this->failure;
    }

    public function success(): string
    {
        return 'Required project paths are available.';
    }

    public function postProcess(Io $io): void
    {
        if ($this->publicEnvironment) {
            $io->writeCommentBlock('The environment directory is inside the webroot.', 'Configure the webserver to deny access or move it outside the webroot.');
        }
        if ($this->themes) {
            return;
        }

        $io->writeErrorBlock('The content themes directory could not be created.');
    }
}
