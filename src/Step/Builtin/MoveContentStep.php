<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\ContentPublisher;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\Step\OptionalStepInterface;

final readonly class MoveContentStep implements ConditionalStepInterface, OptionalStepInterface
{
    public function __construct(private Filesystem $files, private ContentPublisher $publisher, private ProjectBoundary $boundary)
    {
    }

    public function name(): string
    {
        return 'movecontent';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return $config['register-theme-folder']->is(false) && !$config['move-content']->is(false);
    }

    public function askConfirm(Config $config, Io $io): bool
    {
        return !$config['move-content']->is(self::ASK) || $io->askConfirm('Move the core wp-content directory into the configured content directory?', true);
    }

    public function run(Config $config, Paths $paths): int
    {
        $source = $paths->wp('wp-content');
        $target = $paths->wpContent();
        if ($source === $target || (realpath($source) !== false && realpath($source) === realpath($target))) {
            return self::NONE;
        }
        $this->boundary->assertWritablePath($source);
        if (!$this->publisher->canMerge($source, $target)) {
            return self::ERROR;
        }

        return $this->files->moveDir($source, $target) ? self::SUCCESS : self::ERROR;
    }

    public function success(): string
    {
        return 'Core content moved into the configured content directory.';
    }

    public function error(): string
    {
        return 'Core content could not be moved; check protected files, overlapping paths and permissions.';
    }

    public function conditionsNotMet(): string
    {
        return 'Moving core content requires move-content and register-theme-folder=false.';
    }

    public function skipped(): string
    {
        return 'Moving core content skipped.';
    }
}
