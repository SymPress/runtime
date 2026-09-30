<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Installer\BinaryInstaller;
use Composer\Package\PackageInterface;
use RuntimeException;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Filesystem\Filesystem;

/** Regenerates owned Composer proxies without chmodding path-repository sources. */
final class LayoutBinaries extends BinaryInstaller
{
    /** @var array<string, array{contents: string|null, link: string|null, mode: int, binary: string, package: string}> */
    private array $changes = [];

    /** @param list<string> $sources */
    public function plan(PackageInterface $package, string $target, array $sources, ProjectBoundary $boundary): void
    {
        foreach ($package->getBinaries() as $binary) {
            $link = $this->binDir . '/' . basename($binary);
            $boundary->assertWritablePath(dirname($link));
            if (isset($this->changes[$link])) {
                throw new RuntimeException('Packages declare the same binary: ' . basename($binary));
            }
            $previousLink = is_link($link) ? readlink($link) : null;
            $contents = is_file($link) && !is_link($link) ? file_get_contents($link) : null;
            if ($previousLink === false || $contents === false) {
                throw new RuntimeException('Cannot read package binary proxy.');
            }
            if ((file_exists($link) || is_link($link)) && !$this->owns($link, $contents, $sources, $binary)) {
                throw new RuntimeException('Package binary destination is not an owned Composer proxy: ' . $link);
            }
            $this->changes[$link] = ['contents' => $contents, 'link' => $previousLink, 'mode' => is_file($link) ? ((int) fileperms($link) & 0777) : 0755, 'binary' => $target . '/' . $binary, 'package' => $target];
            $bat = $link . '.bat';
            if (!is_file($bat)) {
                continue;
            }

            $boundary->assertWritablePath($bat);
            $previous = (string) file_get_contents($bat);
            $owned = false;
            foreach ($sources as $source) {
                if (!is_file($source . '/' . $binary) || $previous !== $this->generateWindowsProxyCode($source . '/' . $binary, $bat)) {
                    continue;
                }

                $owned = true;
            }
            if (!$owned) {
                throw new RuntimeException('Windows package binary is not an owned Composer proxy: ' . $bat);
            }
            $this->changes[$bat] = ['contents' => $previous, 'link' => null, 'mode' => (int) fileperms($bat) & 0777, 'binary' => $target . '/' . $binary, 'package' => $target];
        }
    }

    /** @param list<string> $sources */
    private function owns(string $link, ?string $contents, array $sources, string $binary): bool
    {
        foreach ($sources as $source) {
            $file = $source . '/' . $binary;
            if (!is_file($file) || !self::isBinPathInsidePackage($source, $file)) {
                continue;
            }
            if (is_link($link) && realpath($link) === realpath($file)) {
                return true;
            }
            if ($contents !== null && $contents === $this->generateUnixyProxyCode($file, $link)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->changes);
    }

    public function write(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->changes as $link => $change) {
            if (!is_file($change['binary']) || !self::isBinPathInsidePackage($change['package'], $change['binary'])) {
                throw new RuntimeException('Package binary is missing or escapes its package.');
            }
            $contents = str_ends_with($link, '.bat') ? $this->generateWindowsProxyCode($change['binary'], $link) : $this->generateUnixyProxyCode($change['binary'], $link);
            if (is_link($link)) {
                $filesystem->remove($link);
            }
            $filesystem->dumpFile($link, $contents);
            $filesystem->chmod($link, $change['mode']);
        }
    }

    public function restore(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->changes as $link => $change) {
            if (is_link($link)) {
                $filesystem->remove($link);
            }
            if ($change['link'] !== null) {
                $filesystem->remove($link);
                $filesystem->symlink($change['link'], $link);
                continue;
            }
            if ($change['contents'] !== null) {
                $filesystem->dumpFile($link, $change['contents']);
                $filesystem->chmod($link, $change['mode']);
                continue;
            }
            $filesystem->remove($link);
        }
    }
}
