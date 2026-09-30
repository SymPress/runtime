<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use RuntimeException;
use SymPress\Runtime\Application\RunContext;

/** @internal */
final class ContextFactory
{
    /** @param list<array{name: string, version: string}> $updatedPackages */
    public function create(Composer $composer, IOInterface $io, string $mode, bool $dev, array $updatedPackages = []): RunContext
    {
        $root = getcwd();
        if ($root === false) {
            throw new RuntimeException('Cannot locate the Composer project root.');
        }
        $vendor = $composer->getConfig()->get('vendor-dir');
        $bin = $composer->getConfig()->get('bin-dir');
        if (!is_string($vendor) || !is_string($bin)) {
            throw new RuntimeException('Invalid Composer vendor/bin paths.');
        }
        $verbosity = match (true) {
            $io->isDebug() => 256,
            $io->isVeryVerbose() => 128,
            $io->isVerbose() => 64,
            getenv('SHELL_VERBOSITY') === '-1' => 16,
            default => 32,
        };

        return new RunContext($root, $vendor, $bin, $mode, $dev, $io->isInteractive(), $io->isDecorated(), $verbosity, $updatedPackages, Factory::getComposerFile());
    }
}
