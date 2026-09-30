<?php

declare(strict_types=1);

namespace SymPress\Runtime\Download;

use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Throwable;

/** Project-local trust on first use; raw URLs never enter the lock or diagnostics. */
final readonly class DownloadLock
{
    public function __construct(private Paths $paths, private ProjectBoundary $boundary)
    {
    }

    public function path(): string
    {
        return $this->paths->root('sympress-runtime.lock');
    }

    /** @return array<string, string> URL or logical artifact identity SHA256 => content SHA256 */
    public function entries(): array
    {
        $path = $this->path();
        $this->assertPath($path);
        if (!is_file($path)) {
            return [];
        }
        try {
            $contents = file_get_contents($path);
            $data = is_string($contents) ? json_decode($contents, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            throw new DownloadException('Download lock is unreadable or invalid.');
        }
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['downloads'] ?? null)) {
            throw new DownloadException('Download lock has an unsupported format.');
        }
        $entries = [];
        foreach ($data['downloads'] as $url => $digest) {
            if (!is_string($url) || !preg_match('/^[a-f0-9]{64}$/D', $url) || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                throw new DownloadException('Download lock contains an invalid entry.');
            }
            $entries[$url] = $digest;
        }

        return $entries;
    }

    public function artifactDigest(string $artifact): ?string
    {
        return $this->entries()[$this->artifactKey($artifact)] ?? null;
    }

    /** @param callable(): bool $publish */
    public function accept(string $url, string $digest, bool $update, callable $publish, ?string $artifact = null): void
    {
        $keys = [hash('sha256', $url)];
        if ($artifact !== null) {
            $keys[] = $this->artifactKey($artifact);
        }
        $this->acceptKeys($keys, $digest, $update, $publish);
    }

    public function acceptArtifact(string $artifact, string $digest, bool $update): void
    {
        $this->acceptKeys([$this->artifactKey($artifact)], $digest, $update, static fn (): bool => true);
    }

    private function artifactKey(string $artifact): string
    {
        return hash('sha256', 'sympress-runtime:artifact:' . $artifact);
    }

    /**
     * @param list<string> $keys
     * @param callable(): bool $publish
     */
    private function acceptKeys(array $keys, string $digest, bool $update, callable $publish): void
    {
        $guardPath = $this->paths->root('.sympress-runtime.lock.guard');
        $this->assertPath($guardPath);
        $previousMask = umask(0077);
        try {
            $guard = fopen($guardPath, 'c+b');
        } finally {
            umask($previousMask);
        }
        if ($guard === false) {
            throw new DownloadException('Cannot acquire the download lock.');
        }
        $temporary = null;
        try {
            if (!chmod($guardPath, 0600) || !flock($guard, LOCK_EX)) {
                throw new DownloadException('Cannot acquire the download lock.');
            }
            $entries = $this->entries();
            if (is_file($this->path()) && !chmod($this->path(), 0600)) {
                throw new DownloadException('Cannot protect the download lock.');
            }
            $changed = false;
            foreach ($keys as $key) {
                $expected = $entries[$key] ?? null;
                if ($expected !== null && !hash_equals($expected, $digest) && !$update) {
                    throw new DownloadException('Artifact content differs from sympress-runtime.lock; review the source and use --update-lock to accept a change.');
                }
                $changed = $changed || $expected !== $digest;
                $entries[$key] = $digest;
            }
            if ($changed) {
                ksort($entries);
                $json = json_encode(['version' => 1, 'downloads' => $entries], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                $temporary = tempnam($this->paths->root(), '.sympress-lock-');
                if ($temporary === false || realpath(dirname($temporary)) !== realpath($this->paths->root()) || !chmod($temporary, 0600) || file_put_contents($temporary, $json) !== strlen($json)) {
                    throw new DownloadException('Cannot stage the download lock.');
                }
            }
            if (!$publish()) {
                throw new DownloadException('Cannot save downloaded file.');
            }
            if (is_string($temporary)) {
                $this->assertPath($this->path());
                if (!rename($temporary, $this->path())) {
                    throw new DownloadException('Cannot publish the download lock.');
                }
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    private function assertPath(string $path): void
    {
        $this->boundary->assertWritablePath($path);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new DownloadException('Download lock must be a regular project-local file.');
        }
    }
}
