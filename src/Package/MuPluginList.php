<?php

declare(strict_types=1);

namespace SymPress\Runtime\Package;

use DirectoryIterator;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;
use UnexpectedValueException;

/** @internal */
final readonly class MuPluginList
{
    public function __construct(private PackageFinder $packages, private Paths $paths)
    {
    }

    /** @return array<string, string> */
    public function pluginsList(Config $config): array
    {
        $plugins = [];
        $visited = [];
        foreach ($this->packages->findByType('wordpress-muplugin') as $package) {
            $files = $this->pluginFiles($package->getInstallPath(), false);
            $this->append($plugins, $package->getName(), $files);
            foreach ($files as $file) {
                $visited[dirname($file)] = true;
            }
        }
        $dropins = [];
        $configured = $config['dropins']->unwrapOrFallback([]);
        if (is_array($configured)) {
            foreach ($configured as $file) {
                $path = is_string($file) && !filter_var($file, FILTER_VALIDATE_URL) ? realpath($file) : false;
                if ($path === false) {
                    continue;
                }
                $dropins[] = $path;
            }
        }
        foreach ($this->entries($this->paths->wpContent('mu-plugins'), true) as $directory) {
            $real = realpath($directory);
            if ($real === false || isset($visited[$real])) {
                continue;
            }
            $this->append($plugins, basename($directory), $this->pluginFiles($directory, true, $dropins));
        }

        if ($config['compatibility-profile']->is('native')) {
            asort($plugins, SORT_STRING);
        }

        return $plugins;
    }

    /**
     * @param list<string> $dropins
     * @return list<string>
     */
    private function pluginFiles(string $directory, bool $requireHeader, array $dropins = []): array
    {
        $files = $this->entries($directory, false);
        $single = count($files) === 1 && !$requireHeader;
        $plugins = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === false || !is_readable($file) || in_array($real, $dropins, true)) {
                continue;
            }
            if (!$single && !$this->hasHeader($file)) {
                continue;
            }
            $plugins[] = $real;
        }

        return $plugins;
    }

    /**
     * @param array<string, string> $plugins
     * @param list<string> $files
     */
    private function append(array &$plugins, string $name, array $files): void
    {
        foreach ($files as $file) {
            $key = count($files) > 1 ? $name . '_' . pathinfo($file, PATHINFO_FILENAME) : $name;
            $plugins[$key] = $file;
        }
    }

    /** @return list<string> */
    private function entries(string $directory, bool $directories): array
    {
        try {
            $iterator = new DirectoryIterator($directory);
        } catch (UnexpectedValueException) {
            return [];
        }
        $entries = [];
        foreach ($iterator as $entry) {
            $name = $entry->getFilename();
            if (str_starts_with($name, '.') || in_array($name, ['CVS', 'RCS', 'SCCS', '_darcs', '_svn'], true)) {
                continue;
            }
            $matches = $directories ? $entry->isDir() : ($entry->isFile() && str_ends_with($name, '.php'));
            if (!$matches) {
                continue;
            }
            $entries[] = $entry->getPathname();
        }
        sort($entries, SORT_STRING);

        return $entries;
    }

    private function hasHeader(string $file): bool
    {
        $header = @file_get_contents($file, false, null, 0, 8192);

        return is_string($header) && preg_match('/^[ \t\/*#@]*Plugin Name:(.*)$/mi', str_replace("\r", "\n", $header), $matches) && !empty($matches[1]);
    }
}
