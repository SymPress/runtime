<?php

declare(strict_types=1);

namespace SymPress\Runtime\Package;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\ConfigLoader;
use Symfony\Component\Filesystem\Path;

final class PackageFinder
{
    /** @var list<Package>|null */
    private ?array $packages = null;

    public function __construct(private readonly RunContext $context)
    {
    }

    /** @return list<Package> */
    public function findByType(string $type): array
    {
        return array_values(array_filter($this->all(), static fn (Package $package): bool => $package->getType() === $type));
    }

    /** @return list<Package> */
    public function findByVendor(string $vendor): array
    {
        $vendor = strtolower(trim($vendor, '/'));

        return $vendor === '' ? [] : array_values(array_filter($this->all(), static fn (Package $package): bool => str_starts_with($package->getName(), $vendor . '/')));
    }

    public function findByName(string $name): ?Package
    {
        foreach ($this->all() as $package) {
            if ($package->getName() === strtolower($name)) {
                return $package;
            }
        }

        return null;
    }

    public function findPathOf(Package $package): string
    {
        return $package->getInstallPath();
    }

    /** @return list<Package> */
    public function search(string $name): array
    {
        if ($name === '') {
            return [];
        }
        if ($name === '*' || $name === '*/*') {
            return $this->all();
        }

        return array_values(array_filter($this->all(), static fn (Package $package): bool => fnmatch(strtolower($name), $package->getName(), FNM_PATHNAME | FNM_PERIOD)));
    }

    /** @return list<Package> */
    public function all(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }
        $metadataDir = $this->context->vendor . '/composer';
        $json = $metadataDir . '/installed.json';
        if (is_file($json)) {
            $raw = file_get_contents($json);
            $data = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $entries = is_array($data) ? ($data['packages'] ?? $data) : null;
            $devNames = is_array($data) && is_array($data['dev-package-names'] ?? null) ? $data['dev-package-names'] : [];
            if (!is_array($entries)) {
                throw new RuntimeException('Invalid Composer installed.json metadata.');
            }
            $packages = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new RuntimeException('Invalid Composer package descriptor.');
                }
                $entry['dev_requirement'] = in_array($entry['name'] ?? null, $devNames, true);
                $package = $this->package($entry, $metadataDir);
                if ($package === null) {
                    continue;
                }

                $packages[] = $package;
            }
            $this->packages = $packages;

            return $packages;
        }

        $this->packages = $this->fromPhp($metadataDir);

        return $this->packages;
    }

    /** @param array<array-key, mixed> $entry */
    private function package(array $entry, string $metadataDir): ?Package
    {
        $name = $entry['name'] ?? null;
        $path = $entry['install-path'] ?? null;
        $dev = ($entry['dev_requirement'] ?? false) === true;
        $type = $entry['type'] ?? 'library';
        $version = $entry['version'] ?? '';
        if (!is_string($name) || !is_string($type) || !is_string($version)) {
            throw new RuntimeException('Composer package metadata lacks name, type or version.');
        }
        if ($type === 'metapackage' || ($dev && !$this->context->dev)) {
            return null;
        }
        if (!is_string($path)) {
            throw new RuntimeException('Composer package metadata lacks install-path for ' . $name . '.');
        }
        $extra = $entry['extra'] ?? [];
        $namedExtra = [];
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $namedExtra[$key] = $value;
            }
        }

        $normalized = $entry['version_normalized'] ?? null;

        return new Package($name, $type, $version, Path::makeAbsolute($path, $metadataDir), $namedExtra, $dev, is_string($normalized) ? $normalized : null);
    }

    /** @return list<Package> */
    private function fromPhp(string $metadataDir): array
    {
        $file = $metadataDir . '/installed.php';
        if (!is_file($file)) {
            return [];
        }
        $metadata = (static fn (string $path): mixed => require $path)($file);
        $versions = is_array($metadata) ? ($metadata['versions'] ?? null) : null;
        if (!is_array($versions)) {
            throw new RuntimeException('Invalid Composer installed.php metadata.');
        }
        $packages = [];
        foreach ($versions as $name => $data) {
            if (!is_string($name) || !is_array($data) || !is_string($data['install_path'] ?? null)) {
                continue;
            }
            $path = $data['install_path'];
            if (Path::canonicalize($path) === Path::canonicalize($this->context->root)) {
                continue;
            }
            $manifest = is_file($path . '/composer.json') ? (new ConfigLoader())->readObject($path . '/composer.json') : [];
            $package = $this->package([
                'name' => $name,
                'type' => $data['type'] ?? 'library',
                'version' => $data['pretty_version'] ?? '',
                'version_normalized' => $data['version'] ?? null,
                'install-path' => $path,
                'dev_requirement' => $data['dev_requirement'] ?? false,
                'extra' => $manifest['extra'] ?? [],
            ], $metadataDir);
            if ($package === null) {
                continue;
            }

            $packages[] = $package;
        }

        return $packages;
    }
}
