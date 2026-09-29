<?php

declare(strict_types=1);

namespace SymPress\Runtime\Filesystem;

use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Filesystem\Path;
use Throwable;

final readonly class Filesystem
{
    public const string OP_AUTO = 'auto';
    public const string OP_COPY = 'copy';
    public const string OP_SYMLINK = 'symlink';
    public const string OP_NONE = 'none';
    public const array OPERATIONS = [self::OP_AUTO, self::OP_COPY, self::OP_SYMLINK, self::OP_NONE];

    public function __construct(private SymfonyFilesystem $filesystem = new SymfonyFilesystem())
    {
    }

    public function save(string $content, string $targetPath): bool
    {
        return $this->writeContent($content, $targetPath);
    }

    public function writeContent(string $content, string $targetPath): bool
    {
        if (is_dir($targetPath) || is_link($targetPath)) {
            return false;
        }

        return $this->attempt(fn () => $this->filesystem->dumpFile($targetPath, $content));
    }

    public function copyFile(string $sourcePath, string $targetPath): bool
    {
        if (!is_file($sourcePath) || is_dir($targetPath) || is_link($targetPath)) {
            return false;
        }
        if (realpath($sourcePath) === realpath($targetPath)) {
            return true;
        }

        return $this->attempt(fn () => $this->filesystem->copy($sourcePath, $targetPath, true));
    }

    public function moveFile(string $sourcePath, string $targetPath): bool
    {
        if (realpath($sourcePath) !== false && realpath($sourcePath) === realpath($targetPath)) {
            return true;
        }

        return $this->copyFile($sourcePath, $targetPath) && $this->unlinkOrRemove($sourcePath);
    }

    public function copyDir(string $sourcePath, string $targetPath): bool
    {
        if (!is_dir($sourcePath) || is_link($targetPath) || is_file($targetPath)) {
            return false;
        }
        $source = Path::canonicalize($sourcePath);
        $target = Path::canonicalize($targetPath);
        if ($source === $target) {
            return true;
        }
        if (str_starts_with($target . '/', rtrim($source, '/') . '/')) {
            return false;
        }

        return $this->attempt(fn () => $this->filesystem->mirror($sourcePath, $targetPath, options: ['override' => true, 'copy_on_windows' => true]));
    }

    public function moveDir(string $sourcePath, string $targetPath): bool
    {
        if (realpath($sourcePath) !== false && realpath($sourcePath) === realpath($targetPath)) {
            return true;
        }

        return $this->copyDir($sourcePath, $targetPath) && $this->removeRealDir($sourcePath);
    }

    public function createDir(string $targetPath): bool
    {
        return $this->attempt(fn () => $this->filesystem->mkdir($targetPath));
    }

    public function isLink(string $path): bool
    {
        return is_link($path);
    }

    public function removeRealDir(string $directory): bool
    {
        return is_dir($directory) && !is_link($directory) && $this->attempt(fn () => $this->filesystem->remove($directory));
    }

    public function unlinkOrRemove(string $path): bool
    {
        return $this->attempt(fn () => $this->filesystem->remove($path));
    }

    public function symlink(string $targetPath, string $linkPath): bool
    {
        if (is_link($linkPath) && realpath($linkPath) !== false && realpath($linkPath) === realpath($targetPath)) {
            return true;
        }
        if (!file_exists($targetPath) || file_exists($linkPath) || is_link($linkPath)) {
            return false;
        }
        $relative = Path::makeRelative($targetPath, dirname($linkPath));

        return $this->attempt(fn () => $this->filesystem->symlink($relative, $linkPath));
    }

    public function symlinkOrCopy(string $sourcePath, string $targetPath): bool
    {
        return $this->symlink($sourcePath, $targetPath)
            || (is_dir($sourcePath) ? $this->copyDir($sourcePath, $targetPath) : $this->copyFile($sourcePath, $targetPath));
    }

    public function symlinkOrCopyOperation(string $source, string $target, string $operation): bool
    {
        return match ($operation) {
            self::OP_AUTO => $this->symlinkOrCopy($source, $target),
            self::OP_SYMLINK => $this->symlink($source, $target),
            self::OP_COPY => is_dir($source) ? $this->copyDir($source, $target) : $this->copyFile($source, $target),
            default => false,
        };
    }

    public function findShortestPath(string $from, string $to, bool $directories = false, bool $preferRelative = false): string
    {
        if (!Path::isAbsolute($from) || !Path::isAbsolute($to)) {
            throw new InvalidArgumentException('Shortest-path arguments must be absolute.');
        }
        $from = Path::canonicalize($from);
        $to = Path::canonicalize($to);
        $base = $directories ? $from : Path::getDirectory($from);
        if ($base === Path::getDirectory($to)) {
            return './' . basename($to);
        }
        if (Path::getRoot($from) !== Path::getRoot($to)) {
            return $to;
        }
        if (!$preferRelative && Path::getLongestCommonBasePath($from, $to) === '/' && substr_count(trim($base, '/'), '/') >= 1) {
            return $to;
        }
        $relative = Path::makeRelative($to, $base);

        return $relative === '' ? './' : $relative;
    }

    private function attempt(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
