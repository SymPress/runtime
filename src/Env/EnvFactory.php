<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;

final readonly class EnvFactory
{
    public function __construct(private Config $config, private Paths $paths)
    {
    }

    public function create(): EnvReader
    {
        return $this->createForEnvironment();
    }

    public function createForEnvironment(?string $environment = null): EnvReader
    {
        $profile = $this->config['compatibility-profile']->unwrap();
        $file = $this->config['env-file']->unwrap();
        $directory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
        if (!is_string($profile) || !is_string($file) || !is_string($directory)) {
            throw new RuntimeException('Invalid environment loader configuration.');
        }
        $reader = new EnvReader(profile: $profile, compatibility: $this->config['compatibility']->is(true));
        $reader->loadChain($file, $directory, $this->config['env-local-overrides']->is(true), $environment);

        return $reader;
    }
}
