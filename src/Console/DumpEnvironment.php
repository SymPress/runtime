<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Env\EnvFactory;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Env\EnvRequirements;
use SymPress\Runtime\Filesystem\Paths;
use Symfony\Component\Filesystem\Filesystem;

final readonly class DumpEnvironment
{
    public function __construct(private Config $config, private Paths $paths, private Io $io)
    {
    }

    public function run(string $environment): int
    {
        $reader = (new EnvFactory($this->config, $this->paths))->createForEnvironment($environment);
        $requirements = $this->config['required-env']->unwrapOrFallback([]);
        $errors = (new EnvRequirements())->validate($reader, is_array($requirements) ? $requirements : []);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->io->error($error);
            }

            return 1;
        }
        $reader->setupConstants();
        $directory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
        if (!is_string($directory)) {
            throw new RuntimeException('Invalid environment dump directory.');
        }
        (new Filesystem())->mkdir($directory);
        $file = rtrim($directory, '/\\') . EnvReader::BUILD_DUMP_FILE;
        if (!$reader->dumpCached($file, immutable: true)) {
            $this->io->error('Cannot publish the environment dump; check target ownership and permissions.');

            return 1;
        }
        $this->io->success('Environment dump written for ' . strtolower($environment) . ': ' . $file);

        return 0;
    }
}
