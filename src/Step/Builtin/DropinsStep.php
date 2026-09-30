<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Question;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\ContentPublisher;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\WordPress\DropinCatalog;
use Symfony\Component\Filesystem\Path;
use Throwable;

/** @internal */
final readonly class DropinsStep implements ConditionalStepInterface
{
    public function __construct(private PackageFinder $packages, private ContentPublisher $publisher, private UrlDownloader $downloader, private OverwritePolicy $overwrite, private ProjectBoundary $boundary, private Selection $selection, private Io $io)
    {
    }

    public function name(): string
    {
        return 'dropins';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return $config['dropins']->notEmpty() || $this->packages->findByType('wordpress-dropin') !== [];
    }

    public function run(Config $config, Paths $paths): int
    {
        $operation = $config['dropins-op']->unwrapOrFallback('auto');
        if ($config['compatibility-profile']->is('release-3.0.1')) {
            $operation = 'copy';
        }
        if ($operation === 'ask') {
            $answer = $this->io->ask(new Question(['How should dropins be published?'], ['a' => 'Auto', 's' => 'Symlink', 'c' => 'Copy', 'n' => 'Nothing'], 'a'));
            $operation = ['a' => 'auto', 's' => 'symlink', 'c' => 'copy'][$answer ?? 'n'] ?? 'none';
        }
        if (!is_string($operation) || $operation === 'none') {
            return self::NONE;
        }
        $result = 0;
        foreach ($this->packages->findByType('wordpress-dropin') as $package) {
            foreach (DropinCatalog::FILES as $file) {
                $source = $package->getInstallPath() . '/' . $file;
                if (!is_file($source)) {
                    continue;
                }
                $result |= $this->publisher->publish($source, $paths->wpContent($file), $operation);
            }
        }
        $configured = $config['dropins']->unwrapOrFallback([]);
        if (!is_array($configured)) {
            return $result | self::ERROR;
        }
        foreach ($configured as $name => $source) {
            if (!is_string($name) || !is_string($source) || !$this->accepted($name, $config)) {
                continue;
            }
            $target = $paths->wpContent($name);
            if (filter_var($source, FILTER_VALIDATE_URL) === false) {
                $result |= $this->publisher->publish(Path::makeAbsolute($source, $paths->root()), $target, $operation);
                continue;
            }
            $result |= $this->publishUrl($source, $target);
        }

        $result &= self::SUCCESS | self::ERROR;

        return $result ?: self::NONE;
    }

    private function publishUrl(string $source, string $target): int
    {
        $temporary = null;
        try {
            $this->boundary->assertWritablePath(dirname($target));
            if (is_link($target) && $this->selection->force) {
                $temporary = tempnam(sys_get_temp_dir(), 'sympress-dropin-');
                if ($temporary === false || !$this->downloader->save($source, $temporary)) {
                    return self::ERROR;
                }
                // URL sources are files; replace the link only after verifying the download.
                return $this->publisher->publish($temporary, $target, 'copy');
            }
            $this->boundary->assertWritablePath($target);
            if (!$this->overwrite->shouldOverwrite($target, $this->selection->force)) {
                return self::NONE;
            }

            return $this->downloader->save($source, $target) ? self::SUCCESS : self::ERROR;
        } catch (Throwable) {
            return self::ERROR;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function accepted(string $name, Config $config): bool
    {
        if (!$config['compatibility-profile']->is('release-3.0.1') || in_array($name, DropinCatalog::FILES, true) || $config['unknown-dropins']->is(true)) {
            return true;
        }

        return $config['unknown-dropins']->is('ask') && $this->io->askConfirm('Publish unknown dropin ' . $name . '?', false);
    }

    public function success(): string
    {
        return 'Dropins published; package sources retained.';
    }

    public function error(): string
    {
        return 'Some dropins could not be published.';
    }

    public function conditionsNotMet(): string
    {
        return 'No dropin packages or sources were configured.';
    }
}
