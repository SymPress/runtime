<?php

declare(strict_types=1);

namespace SymPress\Runtime\Filesystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Filesystem\Path;
use Throwable;

/** @internal */
final readonly class ContentPublisher
{
    public function __construct(private Filesystem $files, private ProjectBoundary $boundary, private OverwritePolicy $overwrite, private Selection $selection)
    {
    }

    public function publish(string $source, string $target, string $operation): int
    {
        if ($operation === Filesystem::OP_NONE) {
            return StepInterface::NONE;
        }
        $temporary = null;
        try {
            $this->boundary->assertWritablePath(dirname($target));
            if (!is_readable($source) || (!is_file($source) && !is_dir($source))) {
                return StepInterface::ERROR;
            }
            if (realpath($source) === realpath($target)) {
                return StepInterface::SUCCESS;
            }
            if (is_link($target) && $this->selection->force) {
                return $this->replaceLink($source, $target, $operation);
            }
            $this->boundary->assertWritablePath($target);
            if (is_dir($source)) {
                if (!$this->canMerge($source, $target)) {
                    return StepInterface::ERROR;
                }
                if (!$this->files->createDir(dirname($target))) {
                    return StepInterface::ERROR;
                }

                return $this->files->symlinkOrCopyOperation($source, $target, $operation) ? StepInterface::SUCCESS : StepInterface::ERROR;
            }
            if (is_file($target) && !is_link($target) && hash_file('sha256', $source) === hash_file('sha256', $target)) {
                return StepInterface::SUCCESS;
            }
            if (!$this->overwrite->shouldOverwrite($target, $this->selection->force)) {
                return StepInterface::NONE;
            }
            if (!$this->files->createDir(dirname($target))) {
                return StepInterface::ERROR;
            }
            $temporary = dirname($target) . '/.runtime-publish-' . bin2hex(random_bytes(12));
            if (!$this->files->symlinkOrCopyOperation($source, $temporary, $operation)) {
                return StepInterface::ERROR;
            }
            // A sibling symlink keeps the same relative destination when renamed.
            (new SymfonyFilesystem())->rename($temporary, $target, true);

            return StepInterface::SUCCESS;
        } catch (Throwable) {
            return StepInterface::ERROR;
        } finally {
            if ($temporary !== null && (file_exists($temporary) || is_link($temporary))) {
                $this->files->unlinkOrRemove($temporary);
            }
        }
    }

    /** Prepare first; replace only the link leaf, never its referenced file/directory. */
    private function replaceLink(string $source, string $target, string $operation): int
    {
        $temporary = dirname($target) . '/.runtime-publish-' . bin2hex(random_bytes(12));
        $backup = $temporary . '-previous';
        $previous = readlink($target);
        try {
            // Use the physical parent, rather than resolving the destination link.
            $parent = realpath(dirname($target));
            $sourceReal = realpath($source);
            if ($parent === false || $sourceReal === false || (is_dir($source) && ($parent === $sourceReal || Path::isBasePath($sourceReal, $parent)))) {
                return StepInterface::ERROR;
            }
            if (!$this->files->symlinkOrCopyOperation($source, $temporary, $operation) || !is_link($target) || readlink($target) !== $previous) {
                return StepInterface::ERROR;
            }
            if (!rename($target, $backup)) {
                return StepInterface::ERROR;
            }
            if (!rename($temporary, $target)) {
                return StepInterface::ERROR;
            }
            $this->files->unlinkOrRemove($backup);

            return StepInterface::SUCCESS;
        } finally {
            if (is_link($backup) && !file_exists($target) && !is_link($target)) {
                rename($backup, $target);
            }
            if (file_exists($temporary) || is_link($temporary)) {
                $this->files->unlinkOrRemove($temporary);
            }
        }
    }

    /** Preflight every target before a directory copy or destructive source removal. */
    public function canMerge(string $source, string $target): bool
    {
        try {
            $this->boundary->assertWritablePath($target);
            if (!is_dir($source) || is_link($target) || is_file($target) || $this->overlaps($source, $target)) {
                return false;
            }
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            /** @var \SplFileInfo $entry */
            foreach ($entries as $entry) {
                $destination = $target . substr($entry->getPathname(), strlen($source));
                $this->boundary->assertWritablePath($destination);
                if ($entry->isLink()) {
                    if ((file_exists($destination) || is_link($destination)) && (!is_link($destination) || readlink($destination) !== $entry->getLinkTarget())) {
                        return false;
                    }
                } elseif ($entry->isDir()) {
                    if (is_link($destination) || (file_exists($destination) && !is_dir($destination))) {
                        return false;
                    }
                } elseif (file_exists($destination) || is_link($destination)) {
                    if (is_link($destination) || is_dir($destination)) {
                        return false;
                    }
                    if (hash_file('sha256', $entry->getPathname()) !== hash_file('sha256', $destination) && !$this->overwrite->shouldOverwrite($destination, $this->selection->force)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function overlaps(string $source, string $target): bool
    {
        $sourceReal = realpath($source);
        $ancestor = $target;
        $suffix = '';
        while (!file_exists($ancestor)) {
            $suffix = '/' . basename($ancestor) . $suffix;
            $ancestor = dirname($ancestor);
        }
        $targetReal = realpath($ancestor);
        if ($sourceReal === false || $targetReal === false) {
            return true;
        }
        $targetReal = Path::canonicalize($targetReal . $suffix);

        return Path::isBasePath($sourceReal, $targetReal) || Path::isBasePath($targetReal, $sourceReal);
    }
}
