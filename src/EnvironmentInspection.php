<?php

declare(strict_types=1);

namespace SymPress\Runtime;

use InvalidArgumentException;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\EnvironmentFiles;
use SymPress\Runtime\Env\EnvFactory;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Inspection restores the complete environment boundary before returning.

/** Read-only environment inspection without bootstrap or Composer lifecycle. @api */
final class EnvironmentInspection
{
    /**
     * @api
     * @param list<string> $names
     * @return array{environment: string, values: array<string, string|null>, source: string}
     */
    public static function inspect(string $projectRoot, array $names, ?string $manifest = null, ?string $environmentFile = null): array
    {
        $loader = new ConfigLoader();
        $metadata = $loader->readObject($manifest ?? $projectRoot . '/composer.json');
        $extra = $metadata['extra'] ?? [];
        if (!is_array($extra)) {
            throw new InvalidArgumentException('Composer extra must be an object.');
        }
        $namedExtra = [];
        foreach ($extra as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Composer extra must have named keys.');
            }
            $namedExtra[$key] = $value;
        }
        $loaded = $loader->load($projectRoot, $namedExtra);
        $loadedValues = $environmentFile === null ? $loaded->values : array_replace($loaded->values, ['env-file' => $environmentFile]);
        $paths = new Paths($projectRoot);
        $config = new Config($loadedValues, new Validator($paths, $loaded->profile), $loaded->profile);
        return self::withConfiguration($config, $paths, $names, $environmentFile === null);
    }

    /**
     * @param list<string> $names
     * @return array{environment: string, values: array<string, string|null>, source: string}
     * @internal
     */
    public static function withConfiguration(Config $config, Paths $paths, array $names, bool $useArtifacts = true): array
    {
        foreach ($names as $name) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException('Environment inspection requires variable names.');
            }
        }
        $environment = $_ENV;
        $server = $_SERVER;
        $process = getenv();
        $process = is_array($process) ? $process : [];
        try {
            [$reader, $source] = self::loadReader($config, $paths, $useArtifacts);
            $values = [];
            foreach ($names as $name) {
                $values[$name] = $reader->rawValue($name);
            }
            return ['environment' => $reader->determineEnvType(), 'values' => $values, 'source' => $source];
        } finally {
            $_ENV = $environment;
            $_SERVER = $server;
            $current = getenv();
            foreach (is_array($current) ? array_diff_key($current, $process) : [] as $name => $value) {
                putenv($name);
            }
            foreach ($process as $name => $value) {
                putenv($name . '=' . $value);
            }
        }
    }

    /** @return array{EnvReader, string} @internal */
    public static function loadReader(Config $config, Paths $paths, bool $useArtifacts = true): array
    {
        $directory = $config['env-dir']->unwrapOrFallback($paths->root());
        $profile = $config['compatibility-profile']->unwrap();
        if (!is_string($directory) || !is_string($profile)) {
            throw new RuntimeException('Invalid environment inspection configuration.');
        }
        $source = 'files';
        $reader = null;
        foreach ($useArtifacts ? ['dump' => EnvReader::BUILD_DUMP_FILE, 'cache' => EnvReader::CACHE_DUMP_FILE] : [] as $kind => $suffix) {
            if ($kind === 'cache' && $config['cache-env']->is(false)) {
                continue;
            }
            if (!is_file($directory . $suffix)) {
                continue;
            }
            $candidate = EnvReader::buildFromCacheDump($directory . $suffix, $profile, compatibility: $config['compatibility']->is(true), validateSources: $kind === 'cache', refreshInterpolation: false, defineConstants: false, dataOnly: true);
            if (!$candidate->hasCachedValues()) {
                continue;
            }
            $reader = $candidate;
            $source = $kind;
            break;
        }
        if ($reader === null) {
            if ((new EnvironmentFiles($config, $paths))->containsCommands()) {
                throw new RuntimeException('Environment inspection refuses shell command substitutions.');
            }
            $reader = (new EnvFactory($config, $paths))->create();
        }
        return [$reader, $source];
    }
}
