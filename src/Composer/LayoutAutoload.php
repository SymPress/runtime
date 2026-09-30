<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Autoload\ClassLoader;

/**
 * A preparation process must never execute project or WordPress autoload files.
 *
 * @internal
 */
final class LayoutAutoload
{
    public static function register(string $vendor): void
    {
        require_once $vendor . '/composer/ClassLoader.php';
        $loader = new ClassLoader();
        /** @var array<string, list<string>> $psr4 */
        $psr4 = require $vendor . '/composer/autoload_psr4.php';
        foreach ($psr4 as $prefix => $paths) {
            $loader->setPsr4($prefix, $paths);
        }
        /** @var array<string, list<string>> $namespaces */
        $namespaces = require $vendor . '/composer/autoload_namespaces.php';
        foreach ($namespaces as $prefix => $paths) {
            $loader->set($prefix, $paths);
        }
        /** @var array<string, string> $classmap */
        $classmap = require $vendor . '/composer/autoload_classmap.php';
        $loader->addClassMap($classmap);
        $loader->register();
        $allowed = self::dependencyFiles($vendor);
        /** @var array<string, string> $files */
        $files = is_file($vendor . '/composer/autoload_files.php') ? require $vendor . '/composer/autoload_files.php' : [];
        foreach ($files as $file) {
            if (!in_array(realpath($file), $allowed, true)) {
                continue;
            }

            require_once $file;
        }
    }

    /** @return list<string> */
    private static function dependencyFiles(string $vendor): array
    {
        /** @var array{packages: list<array{name: string, require?: array<string, string>, autoload?: array{files?: list<string>}, 'install-path'?: string}>} $installed */
        $installed = json_decode((string) file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_column($installed['packages'], null, 'name');
        $pending = ['composer/composer'];
        $seen = [];
        $files = [];
        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($seen[$name]) || !isset($packages[$name])) {
                continue;
            }
            $seen[$name] = true;
            $package = $packages[$name];
            array_push($pending, ...array_keys($package['require'] ?? []));
            $directory = $vendor . '/composer/' . ($package['install-path'] ?? '../' . $name);
            foreach ($package['autoload']['files'] ?? [] as $file) {
                $path = realpath($directory . '/' . $file);
                if ($path === false) {
                    continue;
                }

                $files[] = $path;
            }
        }

        return $files;
    }
}
