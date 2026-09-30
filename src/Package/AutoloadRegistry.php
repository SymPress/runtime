<?php

declare(strict_types=1);

namespace SymPress\Runtime\Package;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Throwable;

final class AutoloadRegistry
{
    /** @var list<callable(string): void> */
    private array $loaders = [];

    /** @return array{psr-4: array<string, list<string>>, files: list<string>} */
    public function metadata(Package $package, bool $compatibility = true): array
    {
        $empty = ['psr-4' => [], 'files' => []];
        if (!in_array($package->getType(), ['sympress-runtime-extension', 'wpstarter-extension'], true)) {
            return $empty;
        }
        $extra = $package->getExtra();
        if (!$compatibility && ($package->getType() === 'wpstarter-extension' || array_key_exists('wpstarter-autoload', $extra))) {
            throw new InvalidArgumentException('Legacy extension metadata requires compatibility: ' . $package->getName() . '. Migrate its type and autoload key.');
        }
        $native = array_key_exists('sympress-runtime-autoload', $extra);
        if (!$native && !$compatibility) {
            return $empty;
        }
        $metadata = $extra[$native ? 'sympress-runtime-autoload' : 'wpstarter-autoload'] ?? [];
        if (!is_array($metadata)) {
            if ($native) {
                throw new InvalidArgumentException('Invalid runtime autoload metadata in ' . $package->getName() . '.');
            }

            return $empty;
        }
        $prefixes = $metadata['psr-4'] ?? [];
        $files = $metadata['files'] ?? [];
        if ($native && (!is_array($prefixes) || !is_array($files))) {
            throw new InvalidArgumentException('Runtime autoload psr-4 and files must be maps/lists in ' . $package->getName() . '.');
        }
        $result = $empty;
        foreach (is_array($prefixes) ? $prefixes : [] as $namespace => $directories) {
            if (!is_string($namespace) || ($namespace !== '' && preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\\\\?)+$/D', $namespace) !== 1)) {
                if ($native) {
                    throw new InvalidArgumentException('Invalid runtime autoload namespace in ' . $package->getName() . '.');
                }
                continue;
            }
            $directories = is_string($directories) ? [$directories] : $directories;
            foreach ($this->paths($directories, $package, $native) as $directory) {
                $result['psr-4'][$namespace][] = $directory;
            }
        }
        $result['files'] = $this->paths($files, $package, $native);

        return $result;
    }

    /** @return list<callable> */
    public function load(Package $package, bool $compatibility = true): array
    {
        $metadata = $this->metadata($package, $compatibility);
        foreach ($metadata['psr-4'] as $namespace => $directories) {
            $loader = $this->loader($namespace, $directories);
            spl_autoload_register($loader, true, true);
            $this->loaders[] = $loader;
        }
        $configurators = [];
        foreach ($metadata['files'] as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            try {
                $result = require_once $file;
            } catch (Throwable) {
                throw new RuntimeException('Runtime autoload file failed in ' . $package->getName() . '.');
            }
            if (!is_callable($result)) {
                continue;
            }
            $configurators[] = $result;
        }

        return $configurators;
    }

    /**
     * @param list<string> $directories
     * @return callable(string): void
     */
    private function loader(string $namespace, array $directories): callable
    {
        $prefix = $namespace === '' ? '' : rtrim($namespace, '\\') . '\\';

        return static function (string $class) use ($prefix, $directories): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            foreach ($directories as $directory) {
                $file = $directory . '/' . $relative;
                if (!is_file($file) || !is_readable($file)) {
                    continue;
                }
                require_once $file;

                return;
            }
        };
    }

    /** @return list<string> */
    private function paths(mixed $entries, Package $package, bool $strict): array
    {
        if (!is_array($entries)) {
            if ($strict) {
                throw new InvalidArgumentException('Runtime autoload paths must be a list in ' . $package->getName() . '.');
            }

            return [];
        }
        $paths = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || str_contains($entry, "\0")) {
                if ($strict) {
                    throw new InvalidArgumentException('Runtime autoload path is invalid in ' . $package->getName() . '.');
                }
                continue;
            }
            $paths[] = Path::makeAbsolute($entry, $package->getInstallPath());
        }

        return $paths;
    }

    public function unregister(): void
    {
        foreach ($this->loaders as $loader) {
            spl_autoload_unregister($loader);
        }
        $this->loaders = [];
    }

    public function __destruct()
    {
        $this->unregister();
    }
}
