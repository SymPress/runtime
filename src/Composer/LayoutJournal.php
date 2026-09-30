<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Closure;
use Composer\Package\Loader\ArrayLoader;
use RuntimeException;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Write-ahead transaction: persisted intent always precedes a filesystem mutation.
 *
 * @phpstan-type JournalMove array{from: string, to: string, dev: int, ino: int, link: string|null}
 * @phpstan-type JournalSnapshot array{contents: string|null, link: string|null, mode: int}
 * @phpstan-type JournalRecord array{format: int, root: string, vendor: string, manifest: string, phase: string, cursor: int, moves: list<JournalMove>, snapshots: array<string, JournalSnapshot>}
 * @internal
 */
final class LayoutJournal
{
    private string $file;
    private ProjectBoundary $boundary;
    private string $manifestFile;
    /** @var JournalRecord */
    private array $record;

    public function __construct(private string $root, private string $vendor, private ?Closure $checkpoint = null, ?string $manifestFile = null)
    {
        $this->root = Path::canonicalize($root);
        $this->vendor = Path::canonicalize($vendor);
        $this->manifestFile = Path::makeAbsolute($manifestFile ?? 'composer.json', $this->root);
        $this->file = $this->root . '/var/runtime/package-layout.pending.json';
        $this->boundary = new ProjectBoundary(new Paths($this->root, $this->vendor));
    }

    /**
     * @param list<array{string, string}> $moves
     * @param list<string> $files
     */
    public function begin(array $moves, array $files): void
    {
        $this->validateManifest();
        $snapshots = [];
        foreach (array_unique($files) as $file) {
            $this->safe($file);
            if (is_dir($file) && !is_link($file)) {
                throw new RuntimeException('Cannot snapshot a directory as metadata.');
            }
            $link = is_link($file) ? readlink($file) : null;
            if ($link === false) {
                throw new RuntimeException('Cannot snapshot binary link.');
            }
            $contents = is_file($file) && !is_link($file) ? file_get_contents($file) : null;
            $mode = is_file($file) ? fileperms($file) : 0600;
            if ($contents === false || $mode === false) {
                throw new RuntimeException('Cannot read original metadata snapshot.');
            }
            $snapshots[$file] = ['contents' => $contents === null ? null : base64_encode($contents), 'link' => $link, 'mode' => $mode & 0777];
        }
        $planned = [];
        foreach ($moves as [$from, $to]) {
            $this->safe($from);
            $this->safe($to);
            $stat = lstat($from);
            if ($stat === false) {
                throw new RuntimeException('Cannot identify package move source.');
            }
            $link = is_link($from) ? readlink($from) : null;
            if ($link === false) {
                throw new RuntimeException('Cannot snapshot package link.');
            }
            $planned[] = ['from' => $from, 'to' => $to, 'dev' => $stat['dev'], 'ino' => $stat['ino'], 'link' => $link];
        }
        $this->record = ['format' => 1, 'root' => $this->root, 'vendor' => $this->vendor, 'manifest' => $this->manifestFile, 'phase' => 'pending', 'cursor' => 0, 'moves' => $planned, 'snapshots' => $snapshots];
        $this->save();
        $this->point('prepared');
    }

    public function moveAll(): void
    {
        foreach ($this->record['moves'] as $index => $move) {
            $this->record['cursor'] = $index + 1;
            $this->save();
            $this->point('move-intent');
            $this->transfer($move, false);
            $this->point('moved');
        }
    }

    public function commit(): void
    {
        foreach (array_keys($this->record['snapshots']) as $file) {
            if (!is_file($file) || is_link($file)) {
                continue;
            }

            $handle = fopen($file, PHP_OS_FAMILY === 'Windows' ? 'r+b' : 'r');
            if ($handle === false) {
                throw new RuntimeException('Cannot synchronize generated metadata.');
            }
            try {
                if (!fsync($handle)) {
                    throw new RuntimeException('Cannot synchronize generated metadata.');
                }
            } finally {
                fclose($handle);
            }
            $this->syncParents(dirname($file));
        }
        $this->record['phase'] = 'committed';
        $this->save();
        $this->point('committed');
        $this->finish();
    }

    public function recover(): void
    {
        $this->safe($this->file);
        if (!file_exists($this->file)) {
            return;
        }
        if (is_link($this->file) || !is_file($this->file)) {
            throw new RuntimeException('Unsafe package layout journal.');
        }
        $envelope = json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || !is_string($envelope['data'] ?? null) || !is_string($envelope['sha256'] ?? null) || !hash_equals(hash('sha256', $envelope['data']), $envelope['sha256'])) {
            throw new RuntimeException('Corrupt package layout journal; preserve the tree for manual inspection.');
        }
        $record = json_decode($envelope['data'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['format'] ?? null) !== 1 || ($record['root'] ?? null) !== $this->root || ($record['vendor'] ?? null) !== $this->vendor || ($record['manifest'] ?? null) !== $this->manifestFile || !in_array($record['phase'] ?? null, ['pending', 'committed'], true) || !is_int($record['cursor'] ?? null) || !is_array($record['moves'] ?? null) || !is_array($record['snapshots'] ?? null) || $record['cursor'] < 0 || $record['cursor'] > count($record['moves'])) {
            throw new RuntimeException('Invalid package layout journal schema.');
        }
        $this->validateManifest();
        /** @var JournalRecord $validated */
        $validated = $record;
        $record = $validated;
        $allowed = $this->allowedPaths($record);
        // Validate the entire record before changing any file.
        foreach ($record['moves'] as $move) {
            if (!is_array($move) || !is_string($move['from'] ?? null) || !is_string($move['to'] ?? null) || !is_int($move['dev'] ?? null) || !is_int($move['ino'] ?? null) || !array_key_exists('link', $move) || ($move['link'] !== null && !is_string($move['link']))) {
                throw new RuntimeException('Invalid package move in journal.');
            }
            foreach ([$move['from'], $move['to']] as $path) {
                $this->safe($path);
                if (!isset($allowed[$path]) && !preg_match('~^' . preg_quote($this->root, '~') . '/var/runtime/package-backups/[a-f0-9]{24}$~D', $path)) {
                    throw new RuntimeException('Journal move does not belong to an installed package.');
                }
                if ($path === $this->root || $path === $this->vendor || Path::isBasePath($path, $this->vendor) || Path::isBasePath($this->vendor . '/composer', $path) || (Path::isBasePath($this->root . '/var', $path) && !preg_match('~^' . preg_quote($this->root, '~') . '/var/runtime/package-backups/[a-f0-9]{24}$~D', $path))) {
                    throw new RuntimeException('Unsafe package move in journal.');
                }
            }
        }
        foreach ($record['snapshots'] as $path => $snapshot) {
            if (!is_string($path) || !is_array($snapshot) || !array_key_exists('contents', $snapshot) || !array_key_exists('link', $snapshot) || !is_int($snapshot['mode'] ?? null) || $snapshot['mode'] < 0 || $snapshot['mode'] > 0777 || ($snapshot['contents'] !== null && (!is_string($snapshot['contents']) || base64_decode($snapshot['contents'], true) === false)) || ($snapshot['link'] !== null && !is_string($snapshot['link']))) {
                throw new RuntimeException('Invalid metadata snapshot in journal.');
            }
            $this->safe($path);
            // Only Composer metadata, the layout record and declared binary proxies are eligible.
            if (!$this->metadataPath($path, $allowed)) {
                throw new RuntimeException('Unexpected metadata path in journal.');
            }
            if (is_dir($path) && !is_link($path)) {
                throw new RuntimeException('Metadata was replaced with a directory; recovery stopped.');
            }
        }
        $this->record = $record;
        if ($record['phase'] === 'committed') {
            $this->finish();
            return;
        }
        while ($this->record['cursor'] > 0) {
            $index = $this->record['cursor'] - 1;
            $this->transfer($this->record['moves'][$index], true);
            $this->point('rollback-moved');
            $this->record['cursor'] = $index;
            $this->save();
        }
        $filesystem = new Filesystem();
        foreach ($record['snapshots'] as $path => $snapshot) {
            if (is_link($path)) {
                unlink($path);
            }
            if ($snapshot['link'] !== null) {
                if (file_exists($path)) {
                    unlink($path);
                }
                $filesystem->symlink($snapshot['link'], $path);
            } elseif ($snapshot['contents'] !== null) {
                $this->durable($path, (string) base64_decode($snapshot['contents'], true), $snapshot['mode']);
            } elseif (is_file($path)) {
                unlink($path);
            }
            if (is_dir(dirname($path))) {
                $this->syncParents(dirname($path));
            }
            $this->point('rollback-metadata');
        }
        unlink($this->file);
        $this->syncParents(dirname($this->file));
    }

    /**
     * @param JournalRecord $record
     * @return array<string, true>
     */
    private function allowedPaths(array $record): array
    {
        $metadata = $record['snapshots'][$this->vendor . '/composer/installed.json']['contents'] ?? null;
        if (!is_string($metadata)) {
            throw new RuntimeException('Journal has no original Composer metadata.');
        }
        $data = json_decode((string) base64_decode($metadata, true), true, flags: JSON_THROW_ON_ERROR);
        $manifest = (new ConfigLoader())->readObject($this->manifestFile);
        if (!is_array($data) || !is_array($data['packages'] ?? null)) {
            throw new RuntimeException('Invalid original package metadata.');
        }
        $bin = $this->binPath($manifest);
        $allowed = [];
        $names = array_values(array_filter(array_column($data['packages'], 'name'), is_string(...)));
        foreach ($data['packages'] as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                throw new RuntimeException('Invalid original package entry.');
            }
            /** @var array<string, mixed> $packageData */
            $packageData = $package;
            $package = $packageData;
            $target = (new PackageDestination())->forPackage((new ArrayLoader())->load($package), $manifest, $names);
            if ($target === null) {
                continue;
            }
            $allowed[Path::makeAbsolute($target, $this->root)] = true;
            if (is_string($package['install-path'] ?? null)) {
                $allowed[Path::makeAbsolute($package['install-path'], $this->vendor . '/composer')] = true;
            }
            $binaries = $package['bin'] ?? [];
            if (!is_array($binaries)) {
                throw new RuntimeException('Invalid package binary metadata.');
            }
            foreach ($binaries as $binary) {
                if (!is_string($binary)) {
                    continue;
                }

                $allowed[$bin . '/' . basename($binary)] = true;
                $allowed[$bin . '/' . basename($binary) . '.bat'] = true;
            }
        }
        $state = $record['snapshots'][$this->root . '/var/runtime/package-layout.json']['contents'] ?? null;
        if (is_string($state)) {
            $previous = json_decode((string) base64_decode($state, true), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($previous)) {
                throw new RuntimeException('Invalid original layout state.');
            }
            foreach ($previous as $name => $entry) {
                if (!in_array($name, $names, true) || !is_array($entry) || !is_string($entry['path'] ?? null)) {
                    continue;
                }

                $allowed[Path::makeAbsolute($entry['path'], $this->root)] = true;
            }
        }
        return $allowed;
    }

    /** @param array<string, true> $allowed */
    private function metadataPath(string $path, array $allowed): bool
    {
        if ($path === $this->root . '/var/runtime/package-layout.json' || $path === $this->vendor . '/autoload.php' || (dirname($path) === $this->vendor . '/composer' && preg_match('/^(?:autoload_[a-z0-9_]+\.php|installed\.(?:json|php)|InstalledVersions\.php|ClassLoader\.php|platform_check\.php|LICENSE)$/D', basename($path)))) {
            return true;
        }
        $manifest = (new ConfigLoader())->readObject($this->manifestFile);
        $bin = $this->binPath($manifest);
        // Names are constrained to a single leaf of the declared Composer binary directory.
        return dirname($path) === $bin && isset($allowed[$path]);
    }

    /** @param array<string, mixed> $manifest */
    private function binPath(array $manifest): string
    {
        $config = $manifest['config'] ?? [];
        $bin = is_array($config) ? ($config['bin-dir'] ?? $this->vendor . '/bin') : $this->vendor . '/bin';
        if (!is_string($bin)) {
            throw new RuntimeException('Invalid configured binary directory.');
        }
        return Path::makeAbsolute($bin, $this->root);
    }

    public function protectBackups(): void
    {
        $directory = $this->root . '/var/runtime/package-backups';
        $this->safe($directory);
        if (is_link($directory)) {
            throw new RuntimeException('Package backup directory must not be a symlink.');
        }
        (new Filesystem())->mkdir($directory, 0700);
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Cannot protect the package backup directory.');
        }
        clearstatcache(true, $directory);
        $mode = fileperms($directory);
        if ($mode === false || ($mode & 0777) !== 0700) {
            throw new RuntimeException('Package backup directory is not private.');
        }
        $this->syncParents($directory);
    }

    /** @param JournalMove $move */
    private function transfer(array $move, bool $reverse): void
    {
        if (dirname($move['to']) === $this->root . '/var/runtime/package-backups') {
            $this->protectBackups();
        }
        $from = $reverse ? $move['to'] : $move['from'];
        $to = $reverse ? $move['from'] : $move['to'];
        $this->safe($from);
        $this->safe($to);
        $source = file_exists($from) || is_link($from);
        $target = file_exists($to) || is_link($to);
        if ($move['link'] !== null) {
            $original = $move['link'];
            $relocated = Path::makeRelative(Path::makeAbsolute($original, dirname($move['from'])), dirname($move['to']));
            $expectedFrom = $reverse ? $relocated : $original;
            $expectedTo = $reverse ? $original : $relocated;
            if (($source && (!is_link($from) || readlink($from) !== $expectedFrom)) || ($target && (!is_link($to) || readlink($to) !== $expectedTo)) || (!$source && !$target)) {
                throw new RuntimeException('Package link changed during interrupted repair.');
            }
            (new Filesystem())->mkdir(dirname($to));
            if (!$target) {
                symlink($expectedTo, $to);
                $this->syncParents(dirname($to));
                $this->point('link-created');
            }
            if ($source) {
                unlink($from);
                $this->syncParents(dirname($from));
            }
            return;
        }
        $location = $source ? $from : $to;
        $stat = $source || $target ? lstat($location) : false;
        if (($source && $target) || $stat === false || is_link($location) || $stat['dev'] !== $move['dev'] || $stat['ino'] !== $move['ino']) {
            throw new RuntimeException('Package tree changed during interrupted repair; recovery stopped.');
        }
        if (!$source) {
            return;
        }

        (new Filesystem())->mkdir(dirname($to));
        if (!rename($from, $to)) {
            throw new RuntimeException('Cannot move package tree.');
        }
        $this->syncParents(dirname($to));
        $this->syncParents(dirname($from));
    }

    private function validateManifest(): void
    {
        $this->safe($this->manifestFile);
        if (is_link($this->manifestFile) || !is_file($this->manifestFile)) {
            throw new RuntimeException('Journal manifest must be a regular project file.');
        }
    }

    private function safe(string $path): void
    {
        if (Path::canonicalize($path) !== $path || !Path::isBasePath($this->root, $path)) {
            throw new RuntimeException('Journal paths must stay inside the project.');
        }
        $this->boundary->assertWritablePath(dirname($path));
        for ($parent = dirname($path); $parent !== $this->root; $parent = dirname($parent)) {
            if (is_link($parent)) {
                throw new RuntimeException('Journal paths must not traverse symlinks.');
            }
        }
    }

    private function save(): void
    {
        $data = json_encode($this->record, JSON_THROW_ON_ERROR);
        $this->durable($this->file, json_encode(['data' => $data, 'sha256' => hash('sha256', $data)], JSON_THROW_ON_ERROR) . "\n", 0600);
    }

    private function durable(string $file, string $contents, int $mode): void
    {
        $this->safe($file);
        if (is_link($file)) {
            throw new RuntimeException('Cannot publish journal metadata through a symlink.');
        }
        (new Filesystem())->mkdir(dirname($file), 0700);
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create durable journal file.');
        }
        try {
            if (!chmod($temporary, $mode)) {
                throw new RuntimeException('Cannot protect journal metadata before writing.');
            }
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot flush package journal.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $file)) {
            throw new RuntimeException('Cannot publish package journal.');
        }
        $this->syncParents(dirname($file));
    }

    private function syncParents(string $directory): void
    {
        while (true) {
            $this->syncDirectory($directory);
            if ($directory === $this->root) {
                return;
            }
            $directory = dirname($directory);
        }
    }

    private function syncDirectory(string $directory): void
    {
        // PHP's Windows file wrapper cannot open directories for fsync.
        // File contents are still flushed; directory durability is OS-managed.
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $handle = fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot open journal directory for synchronization.');
        }
        try {
            if (!fsync($handle)) {
                throw new RuntimeException('Cannot synchronize journal directory.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function finish(): void
    {
        foreach ($this->record['moves'] as $move) {
            if (dirname($move['to']) !== $this->root . '/var/runtime/package-backups') {
                continue;
            }
            $this->protectBackups();
            $this->durable($move['to'] . '.json', json_encode(['format' => 1, 'path' => basename($move['to']), 'dev' => $move['dev'], 'ino' => $move['ino']], JSON_THROW_ON_ERROR), 0600);
        }
        unlink($this->file);
        $this->syncParents(dirname($this->file));
    }

    public function point(string $phase): void
    {
        if ($this->checkpoint === null) {
            return;
        }

        ($this->checkpoint)($phase);
    }
}
