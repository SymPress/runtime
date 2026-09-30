<?php

declare(strict_types=1);

namespace SymPress\Runtime\Maintenance;

use RuntimeException;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Filesystem\Filesystem;

/** Shared lock for generation, recovery and retention. */
final class MaintenanceLock
{
    /** @var resource|null */
    private $handle = null;

    public static function acquire(Paths $paths): self
    {
        $file = $paths->root('var/runtime/.maintenance.lock');
        (new ProjectBoundary($paths))->assertWritablePath($file);
        if (is_link($file)) {
            throw new RuntimeException('Maintenance lock must not be a symlink.');
        }
        // Payload code must remain traversable by a shared PHP service group.
        // Secret journal files and backup trees enforce their own private modes.
        (new Filesystem())->mkdir(dirname($file), 0755);
        $lock = new self();
        $handle = fopen($file, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the maintenance lock.');
        }
        $lock->handle = $handle;
        if (!flock($lock->handle, LOCK_EX | LOCK_NB)) {
            $lock->release();
            throw new RuntimeException('Another Runtime maintenance operation is running.');
        }

        return $lock;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
