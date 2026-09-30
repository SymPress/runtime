<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\ConditionalStepInterface;

final readonly class FlushEnvCacheStep implements ConditionalStepInterface
{
    public function __construct(private Filesystem $filesystem)
    {
    }

    public function name(): string
    {
        return 'flushenvcache';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        $file = $this->target($config, $paths);

        return file_exists($file) || is_link($file);
    }

    public function run(Config $config, Paths $paths): int
    {
        $file = $this->target($config, $paths);
        if (is_dir($file) && !is_link($file)) {
            return self::ERROR;
        }

        return $this->filesystem->unlinkOrRemove($file) ? self::SUCCESS : self::ERROR;
    }

    public function success(): string
    {
        return 'Runtime environment cache removed.';
    }

    public function error(): string
    {
        return 'Cannot remove the runtime environment cache file.';
    }

    public function conditionsNotMet(): string
    {
        return 'No runtime environment cache file exists.';
    }

    private function target(Config $config, Paths $paths): string
    {
        $directory = $config['env-dir']->unwrapOrFallback($paths->root());
        if (!is_string($directory)) {
            throw new RuntimeException('Invalid environment directory.');
        }

        return rtrim($directory, '/\\') . EnvReader::CACHE_DUMP_FILE;
    }
}
