<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use FilesystemIterator;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Question;
use SymPress\Runtime\Filesystem\ContentPublisher;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\OptionalStepInterface;
use SymPress\Runtime\WordPress\DropinCatalog;
use Symfony\Component\Filesystem\Path;

final class PublishContentDevStep implements OptionalStepInterface
{
    private ?string $operation = null;

    public function __construct(private readonly ContentPublisher $publisher)
    {
    }

    public function name(): string
    {
        return 'publishcontentdev';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return $config['content-dev-dir']->notEmpty();
    }

    public function askConfirm(Config $config, Io $io): bool
    {
        if (!$config['content-dev-op']->is(self::ASK)) {
            return true;
        }
        $release = $config['compatibility-profile']->is('release-3.0.1');
        $answer = $io->ask(new Question(['How should development content be published?'], ['a' => 'Auto', 's' => 'Symlink', 'c' => 'Copy', 'n' => 'Nothing'], $release ? 's' : 'a'));
        $this->operation = ['a' => 'auto', 's' => 'symlink', 'c' => 'copy'][$answer ?? 'n'] ?? 'none';

        return $this->operation !== 'none';
    }

    public function run(Config $config, Paths $paths): int
    {
        $operation = $this->operation ?? $config['content-dev-op']->unwrapOrFallback('auto');
        if ($operation === self::ASK && $config['compatibility-profile']->is('native')) {
            $operation = 'auto';
        }
        if (!is_string($operation) || in_array($operation, ['none', self::ASK], true)) {
            return self::NONE;
        }
        $directory = $config['content-dev-dir']->unwrapOrFallback('');
        if (!is_string($directory) || $directory === '') {
            return self::NONE;
        }
        $source = Path::makeAbsolute($directory, $paths->root());
        $result = self::NONE;
        foreach (['plugins', 'themes', 'mu-plugins', 'languages'] as $type) {
            $base = $source . '/' . $type;
            if (!is_dir($base) || !is_readable($base)) {
                continue;
            }
            if ($operation === 'copy' && $config['compatibility-profile']->is('release-3.0.1')) {
                $result |= $this->publisher->publish($base, $paths->wpContent($type), $operation);
                continue;
            }
            /** @var \SplFileInfo $entry */
            foreach (new FilesystemIterator($base, FilesystemIterator::SKIP_DOTS) as $entry) {
                $name = $entry->getFilename();
                $vcs = !$config['compatibility-profile']->is('release-3.0.1') && in_array($name, ['CVS', '_svn', '_darcs', '{arch}'], true);
                if (str_starts_with($name, '.') || $vcs || !$entry->isReadable()) {
                    continue;
                }
                $result |= $this->publisher->publish($entry->getPathname(), $paths->wpContent($type . '/' . $name), $operation);
            }
        }
        foreach (DropinCatalog::FILES as $dropin) {
            if (!is_file($source . '/' . $dropin)) {
                continue;
            }
            $result |= $this->publisher->publish($source . '/' . $dropin, $paths->wpContent($dropin), $operation);
        }

        $result &= self::SUCCESS | self::ERROR;

        return $result ?: self::SUCCESS;
    }

    public function success(): string
    {
        return 'Development content published.';
    }

    public function error(): string
    {
        return 'Some development content could not be published; existing targets were preserved where protected.';
    }

    public function skipped(): string
    {
        return 'Development content publishing skipped.';
    }
}
