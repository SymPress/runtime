<?php

declare(strict_types=1);

namespace SymPress\Runtime\Kernel;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Match Kernel's public directory boundary without executing or booting it.

/** Read-only counterpart of Kernel 1.1.5's cache selection. @internal */
final class CacheLocation
{
    /** @param list<string> $publicRoots */
    public static function resolve(string $project, string $environment, ?string $configured, array $publicRoots = []): string
    {
        $base = $configured === null ? $project . '/var/cache' : Path::makeAbsolute($configured, $project);
        $path = rtrim($base, '/') . '/' . $environment . '/kernel';
        $public = self::isPublic($path, $publicRoots);
        $unsafe = !is_link($path) && is_dir($path) && (fileperms($path) & 0022) !== 0;
        if ($configured !== null) {
            if ($public || $unsafe) {
                throw new RuntimeException('APP_CACHE_DIR must be outside the webroot and not group/world writable. Create a private directory as the PHP-FPM user and warm it as that same user.');
            }

            return $path;
        }
        if (!$public && !$unsafe && (self::hasMetadata($path) || self::writableAncestor($path))) {
            return $path;
        }
        $root = self::fallbackRoot($project, $publicRoots);
        if (self::isPublic($root, $publicRoots)) {
            throw new RuntimeException('No private kernel cache is available. Configure APP_CACHE_DIR outside the webroot, create it with mode 0700 as the PHP-FPM user, and run cache warmup as that user.');
        }
        self::assertDirectory($root);

        return $root . '/' . $environment . '/kernel';
    }

    // Mirror the installed kernel: 1.1.16 keeps meta_<bundle set>.json per bundle set and ignores meta.json.
    private static function hasMetadata(string $path): bool
    {
        return self::bundleSetMetadata()
            ? (glob($path . '/meta_*.json') ?: []) !== []
            : is_file($path . '/meta.json');
    }

    /** @param list<string> $publicRoots */
    public static function fallbackRoot(string $project, array $publicRoots = []): string
    {
        $user = (string) self::currentOwner();
        $root = $project . '/var/cache-private-' . $user;
        if (!self::isPublic($root, $publicRoots) && self::writableAncestor($root)) {
            return $root;
        }
        $temporary = self::canonical(sys_get_temp_dir());
        $permissions = fileperms($temporary);
        if ($permissions === false || (($permissions & 0022) !== 0 && ($permissions & 01000) === 0)) {
            throw new RuntimeException('A private kernel cache requires a trusted temporary parent or APP_CACHE_DIR; shared temporary directories must have the sticky bit.');
        }

        $root = rtrim($temporary, '/') . '/sympress-kernel-' . $user . '-' . substr(hash('sha256', self::canonical($project)), 0, 24);

        return self::kernelAtLeast('1.1.17') ? self::temporaryRoot($root) : $root;
    }

    public static function bundleSetMetadata(): bool
    {
        return self::kernelAtLeast('1.1.16');
    }

    public static function kernelAtLeast(string $version): bool
    {
        return InstalledVersions::isInstalled('sympress/kernel')
            && Semver::satisfies((string) InstalledVersions::getVersion('sympress/kernel'), '>=' . $version);
    }

    /**
     * Mirrors kernel 1.1.17: a predictable temporary root that another user took is never
     * adopted; the first private sibling is reused, or one is created atomically, so runtime
     * and the kernel select the same directory.
     */
    private static function temporaryRoot(string $root): string
    {
        $owner = self::currentOwner();
        if ((!file_exists($root) && !is_link($root)) || self::privateDirectory($root, $owner)) {
            return $root;
        }
        foreach (glob($root . '.*', GLOB_NOESCAPE) ?: [] as $candidate) {
            if (self::privateDirectory($candidate, $owner)) {
                return $candidate;
            }
        }
        $candidate = $root . '.' . bin2hex(random_bytes(8));
        if (!mkdir($candidate, 0700)) {
            throw new RuntimeException('Unable to create a private kernel cache next to ' . $root . '; configure APP_CACHE_DIR for the PHP-FPM identity.');
        }

        return $candidate;
    }

    private static function privateDirectory(string $path, int $owner): bool
    {
        return !is_link($path) && is_dir($path) && fileowner($path) === $owner && (fileperms($path) & 0022) === 0;
    }

    /**
     * Validate the configured root and existing descendants without creating them.
     *
     * @param list<string> $publicRoots
     */
    public static function assertTarget(string $path, string $root, bool $external, array $publicRoots = [], bool $implicit = false, ?int $readOnlyOwner = null): void
    {
        if (!Path::isBasePath($root, $path) || self::isPublic($path, $publicRoots)) {
            throw new RuntimeException('Kernel cache target must remain inside its private cache root and outside the webroot.');
        }
        $ancestor = $path;
        while (true) {
            self::assertDirectory($ancestor, $external ? null : $readOnlyOwner);
            if ($ancestor === $root) {
                break;
            }
            $ancestor = dirname($ancestor);
        }
        if (!$external) {
            return;
        }
        if (!$implicit && (!is_dir($root) || (fileperms($root) & 0777) !== 0700)) {
            throw new RuntimeException('An external APP_CACHE_DIR or APP_BUILD_DIR root must exist with mode 0700 and belong to the PHP-FPM user. Warm the cache as that same user.');
        }
        // Even an ancestor above the explicitly trusted root must not redirect it.
        for ($ancestor = dirname($root); dirname($ancestor) !== $ancestor; $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new RuntimeException('Refusing a symlink ancestor of an external kernel cache root.');
            }
            $mode = fileperms($ancestor);
            if ($mode === false || (($mode & 0022) !== 0 && ($mode & 01000) === 0)) {
                throw new RuntimeException('External kernel cache roots require protected ancestors; shared writable ancestors must have the sticky bit.');
            }
            if (!in_array(fileowner($ancestor), [0, self::currentOwner()], true)) {
                throw new RuntimeException('An external kernel cache ancestor belongs to another user. Configure a protected root for the PHP-FPM identity.');
            }
        }
    }

    private static function assertDirectory(string $directory, ?int $readOnlyOwner = null): void
    {
        if (is_link($directory)) {
            throw new RuntimeException('Refusing a symlinked kernel cache directory.');
        }
        if (!file_exists($directory)) {
            return;
        }
        if (!is_dir($directory) || (fileperms($directory) & 0022) !== 0) {
            throw new RuntimeException('Kernel cache directories must be private and not group/world writable; create them as the PHP-FPM user and warm the cache as that user.');
        }
        if (fileowner($directory) !== self::currentOwner() && !(fileowner($directory) === $readOnlyOwner && is_readable($directory) && !is_writable($directory))) {
            throw new RuntimeException('The kernel cache directory belongs to another user. Configure APP_CACHE_DIR for the PHP-FPM identity and warm it as that user.');
        }
    }

    /** @param list<string> $publicRoots */
    private static function isPublic(string $path, array $publicRoots = []): bool
    {
        foreach ([$_SERVER['DOCUMENT_ROOT'] ?? null, defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : null, ...$publicRoots] as $root) {
            if (is_string($root) && $root !== '' && Path::isBasePath(self::canonical($root), self::canonical($path))) {
                return true;
            }
        }

        return false;
    }

    private static function canonical(string $path): string
    {
        $ancestor = Path::canonicalize($path);
        $suffix = '';
        $real = realpath($ancestor);
        while ($real === false) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                return $path;
            }
            $suffix = '/' . basename($ancestor) . $suffix;
            $ancestor = $parent;
            $real = realpath($ancestor);
        }

        return rtrim($real, '/') . $suffix;
    }

    private static function currentOwner(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        $probe = tmpfile();
        if (!is_resource($probe)) {
            throw new RuntimeException('Unable to verify private cache ownership; configure APP_CACHE_DIR.');
        }
        try {
            $status = fstat($probe);
            if ($status === false) {
                throw new RuntimeException('Unable to verify private cache ownership; configure APP_CACHE_DIR.');
            }

            return $status['uid'];
        } finally {
            fclose($probe);
        }
    }

    private static function writableAncestor(string $path): bool
    {
        while (!file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }

        return is_dir($path) && is_writable($path);
    }
}
