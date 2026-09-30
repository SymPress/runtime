<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Closure;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Repository\InstalledFilesystemRepository;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Maintenance\MaintenanceLock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Throwable;

/** Offline recovery only; no install/update/download or plugin activation. */
final class PackageLayout
{
    public function __construct(private ?Closure $checkpoint = null)
    {
    }

    public function prepare(string $root, string $vendor, string $manifestFile): void
    {
        $maintenance = MaintenanceLock::acquire(new Paths($root, $vendor));
        $lockFile = $vendor . '/composer/.sympress-layout.lock';
        (new ProjectBoundary(new Paths($root, $vendor)))->assertWritablePath($lockFile);
        if (is_link($lockFile)) {
            throw new RuntimeException('Package layout lock must not be a symlink.');
        }
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the package layout lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another package layout preparation is running.');
            }
            (new LayoutJournal($root, $vendor, $this->checkpoint, $manifestFile))->recover();
            $this->prepareLocked($root, $vendor, $manifestFile);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $maintenance->release();
        }
    }

    private function prepareLocked(string $root, string $vendor, string $manifestFile): void
    {
        $loader = new ConfigLoader();
        $manifest = $loader->readObject($manifestFile);
        $extra = $manifest['extra'] ?? [];
        if (!is_array($extra) || in_array($manifest['type'] ?? '', ['sympress-runtime-extension', 'wpstarter-extension'], true)) {
            return;
        }
        $extra = array_filter($extra, is_string(...), ARRAY_FILTER_USE_KEY);
        $metadataFile = $vendor . '/composer/installed.json';
        $data = $loader->readObject($metadataFile);
        $rawPackages = $data['packages'] ?? [];
        if (!is_array($rawPackages)) {
            throw new RuntimeException('Installed packages must be an array.');
        }
        $metadata = [];
        foreach ($rawPackages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                continue;
            }

            $metadata[$package['name']] = $package;
        }
        $repository = new InstalledFilesystemRepository(new JsonFile($metadataFile));
        $targets = [];
        foreach ($repository->getCanonicalPackages() as $package) {
            $target = (new PackageDestination())->forPackage($package, $manifest, array_keys($metadata));
            if ($target === null) {
                continue;
            }

            $targets[$package->getName()] = Path::makeAbsolute($target, $root);
        }
        if ($targets === []) {
            return;
        }
        $wp = $extra['wordpress-install-dir'] ?? 'wordpress';
        $content = $extra['wordpress-content-dir'] ?? 'wp-content';
        if (!is_string($wp) || !is_string($content)) {
            throw new RuntimeException('WordPress directories must be strings.');
        }
        $paths = new Paths($root, $vendor, wp: $wp, content: $content);
        $boundary = new ProjectBoundary($paths);
        $boundary->assertWritablePath($vendor . '/composer');
        foreach (array_keys($this->generatedFiles($vendor)) as $file) {
            $boundary->assertWritablePath($file);
            if (is_link($file)) {
                throw new RuntimeException('Composer metadata must not be a symlink.');
            }
        }
        $loaded = $loader->load($root, $extra);
        $config = new Config($loaded->values, new Validator($paths, $loaded->profile), $loaded->profile);
        if ($config->errors() !== []) {
            throw new RuntimeException('Invalid runtime configuration; package layout was not changed.');
        }
        $stateFile = $root . '/var/runtime/package-layout.json';
        $boundary->assertWritablePath($stateFile);
        if (is_link($stateFile)) {
            throw new RuntimeException('Layout state must not be a symlink.');
        }
        $state = is_file($stateFile) ? $loader->readObject($stateFile) : [];
        $next = [];
        $moves = [];
        $installPaths = [];
        $affected = [];
        $changed = false;
        foreach ($metadata as $name => $package) {
            $installed = $package['install-path'] ?? null;
            $source = is_string($installed) ? Path::makeAbsolute($installed, dirname($metadataFile)) : null;
            $installPaths[$name] = $source;
            if (!isset($targets[$name])) {
                continue;
            }
            if ($source === null) {
                throw new RuntimeException('Installed package has no path: ' . $name);
            }
            $target = $targets[$name];
            // A regular core installer may already have installed into the project root.
            if ($target !== $root) {
                $this->assertLeaf($target, $root, $vendor, $boundary);
            }
            $identity = hash('sha256', json_encode([$package['version'] ?? null, $package['source'] ?? null, $package['dist'] ?? null], JSON_THROW_ON_ERROR));
            $previous = $state[$name] ?? null;
            $previousPath = is_array($previous) && is_string($previous['path'] ?? null) ? Path::makeAbsolute($previous['path'], $root) : null;
            if (is_array($previous) && ($previous['identity'] ?? null) === $identity && $previousPath !== null && $this->exists($previousPath)) {
                // A no-op --no-plugins install may have rewritten only Composer's metadata.
                $source = $previousPath;
            }
            if ($source !== $target) {
                $this->assertLeaf($target, $root, $vendor, $boundary);
                $this->assertLeaf($source, $root, $vendor, $boundary);
                if (!$this->exists($source)) {
                    throw new RuntimeException('Downloaded package is missing: ' . $name . '. Run composer install with its installers enabled.');
                }
                $backup = $this->destinationBackup($target, $previousPath, $root, $vendor, $boundary);
                if ($backup !== null) {
                    $moves[] = [$target, $backup];
                }
                $moves[] = [$source, $target];
            } elseif (!$this->exists($target)) {
                throw new RuntimeException('Installed package is missing: ' . $name);
            }
            $installPaths[$name] = $target;
            if (Path::makeAbsolute((string) $installed, dirname($metadataFile)) !== $target || $source !== $target) {
                $affected[$name] = [$source, Path::makeAbsolute((string) $installed, dirname($metadataFile)), $target];
            }
            $changed = $changed || Path::makeAbsolute((string) $installed, dirname($metadataFile)) !== $target;
            $next[$name] = ['identity' => $identity, 'path' => Path::makeRelative($target, $root)];
        }
        if ($changed || $moves !== []) {
            // These restrictions protect recovery; correctly installed trees are left intact.
            $this->assertRecoveryLayout(array_values($targets), $root, $vendor, $boundary);
        }
        ksort($next);
        $encoded = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (!$changed && $moves === [] && is_file($stateFile) && file_get_contents($stateFile) === $encoded) {
            return;
        }
        $filesystem = new Filesystem();
        $composerConfig = $manifest['config'] ?? [];
        $bin = is_array($composerConfig) ? ($composerConfig['bin-dir'] ?? $vendor . '/bin') : $vendor . '/bin';
        if (!is_string($bin)) {
            throw new RuntimeException('Composer bin-dir must be a string.');
        }
        $binaries = new LayoutBinaries(new NullIO(), Path::makeAbsolute($bin, $root), 'auto', vendorDir: $vendor);
        foreach ($repository->getCanonicalPackages() as $package) {
            if (!isset($affected[$package->getName()])) {
                continue;
            }

            $binaries->plan($package, $targets[$package->getName()], $affected[$package->getName()], $boundary);
        }
        $journal = new LayoutJournal($root, $vendor, $this->checkpoint, $manifestFile);
        $journal->begin($moves, [...$this->metadataFiles($vendor), $stateFile, ...$binaries->paths()]);
        try {
            $journal->moveAll();
            if ($changed || $moves !== []) {
                $this->regenerate($manifestFile, $vendor, $installPaths, ($data['dev'] ?? true) !== false);
                $journal->point('metadata');
                $binaries->write();
                $journal->point('binaries');
            }
            $filesystem->dumpFile($stateFile, $encoded);
            $filesystem->chmod($stateFile, 0600);
            $journal->point('state');
            $journal->commit();
        } catch (Throwable $error) {
            $journal->recover();
            throw $error;
        }
    }

    /** @return list<string> */
    private function metadataFiles(string $vendor): array
    {
        return [
        $vendor . '/autoload.php', ...array_map(static fn (string $name): string => $vendor . '/composer/' . $name, [
            'installed.json', 'installed.php', 'InstalledVersions.php', 'ClassLoader.php', 'LICENSE',
            'autoload_namespaces.php', 'autoload_psr4.php', 'autoload_classmap.php', 'autoload_files.php',
            'autoload_static.php', 'autoload_real.php', 'platform_check.php',
        ]),
        ];
    }

    /** @param list<string> $destinations */
    private function assertRecoveryLayout(array $destinations, string $root, string $vendor, ProjectBoundary $boundary): void
    {
        foreach ($destinations as $index => $destination) {
            $this->assertLeaf($destination, $root, $vendor, $boundary);
            foreach (array_slice($destinations, $index + 1) as $other) {
                if ($destination === $other || Path::isBasePath($destination, $other) || Path::isBasePath($other, $destination)) {
                    throw new RuntimeException('WordPress package destinations must not overlap.');
                }
            }
        }
    }

    private function assertLeaf(string $path, string $root, string $vendor, ProjectBoundary $boundary): void
    {
        if (!Path::isBasePath($root, $path) || $path === $vendor || Path::isBasePath($path, $vendor) || $path === $root . '/var' || Path::isBasePath($root . '/var/runtime', $path)) {
            throw new RuntimeException('Unsafe package installation path.');
        }
        // A path repository's leaf symlink may point outside; its parent must not.
        $boundary->assertWritablePath(dirname($path));
    }

    private function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function destinationBackup(string $target, ?string $previous, string $root, string $vendor, ProjectBoundary $boundary): ?string
    {
        if (!$this->exists($target)) {
            return null;
        }
        if ($previous !== $target) {
            throw new RuntimeException('Package destination already exists and is not managed: ' . $target);
        }
        $backup = $root . '/var/runtime/package-backups/' . bin2hex(random_bytes(12));
        $boundary->assertWritablePath($backup);
        (new LayoutJournal($root, $vendor))->protectBackups();

        return $backup;
    }

    /** @return array<string, string> */
    private function generatedFiles(string $vendor): array
    {
        $files = [];
        foreach ($this->metadataFiles($vendor) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('Cannot snapshot Composer metadata.');
            }
            $files[$file] = $contents;
        }

        return $files;
    }

    /** @param array<string, string|null> $paths */
    private function regenerate(string $manifest, string $vendor, array $paths, bool $dev): void
    {
        $io = new NullIO();
        $composer = Factory::create($io, $manifest, true, true);
        $composer->getConfig()->merge(['config' => ['vendor-dir' => $vendor]]);
        $installer = new LayoutInstaller($io, $composer);
        $installer->paths = $paths;
        $manager = $composer->getInstallationManager();
        $manager->addInstaller($installer);
        // Factory purges paths missing under vendor with plugins disabled. Reopen authoritative metadata.
        $repository = new InstalledFilesystemRepository(new JsonFile($vendor . '/composer/installed.json'), true, $composer->getPackage());
        $repository->write($dev, $manager);
        $generator = $composer->getAutoloadGenerator();
        $generator->setRunScripts(false);
        $generator->setDevMode($dev);
        $existing = is_file($vendor . '/composer/autoload_real.php') ? (string) file_get_contents($vendor . '/composer/autoload_real.php') : '';
        $generator->setClassMapAuthoritative((bool) $composer->getConfig()->get('classmap-authoritative') || str_contains($existing, '->setClassMapAuthoritative(true)'));
        $generator->setApcu((bool) $composer->getConfig()->get('apcu-autoloader'));
        $generator->dump($composer->getConfig(), $repository, $composer->getPackage(), $manager, 'composer', (bool) $composer->getConfig()->get('optimize-autoloader'), null, $composer->getLocker());
    }
}
