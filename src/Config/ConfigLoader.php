<?php

declare(strict_types=1);

namespace SymPress\Runtime\Config;

use InvalidArgumentException;
use JsonException;
use Symfony\Component\Filesystem\Path;
use stdClass;

/** @internal */
final class ConfigLoader
{
    /** @param array<string, mixed> $extra */
    public function load(string $root, array $extra): LoadedConfig
    {
        $values = [];
        $provenance = [];
        $diagnostics = [];
        $sources = $this->sources($root, $extra);
        $legacy = isset($sources['extra.wpstarter']) || isset($sources['wpstarter.json']);
        $native = isset($sources['extra.sympress-runtime']) || isset($sources['sympress-runtime.json']);
        foreach ($sources as $source => $config) {
            if ($source === 'extra.wpstarter' || $source === 'wpstarter.json') {
                $diagnostics[] = 'Deprecated configuration source: ' . $source . '. Migrate to sympress-runtime.';
            }
            foreach ($config as $key => $value) {
                if (isset($provenance[$key]) && $values[$key] !== $value) {
                    $diagnostics[] = $key . ': ' . $source . ' overrides ' . $provenance[$key] . '.';
                }
                if (in_array($key, Options::INTERNAL, true)) {
                    throw new InvalidArgumentException($key . ' is provided by the runner and cannot be configured.');
                }
                $values[$key] = $value;
                $provenance[$key] = $source;
            }
        }
        $compatible = filter_var($values['compatibility'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($legacy && $compatible === false) {
            throw new InvalidArgumentException('Legacy WP Starter configuration is present but compatibility is disabled.');
        }
        $scripts = $values['scripts'] ?? [];
        $legacyScripts = is_array($scripts) && (array_key_exists('pre-wpstarter', $scripts) || array_key_exists('post-wpstarter', $scripts));
        if ($compatible === false && ($legacyScripts || array_key_exists('skip-db-check', $values))) {
            throw new InvalidArgumentException('Legacy script names or skip-db-check require compatibility. Use pre/post-sympress-runtime and db-check.');
        }
        $profile = $values['compatibility-profile'] ?? 'auto';
        if ($profile === 'auto') {
            $profile = $legacy && !$native ? 'release-3.0.1' : 'native';
        }
        if (!is_string($profile) || !in_array($profile, ['native', 'release-3.0.1', 'upstream-dev'], true)) {
            throw new InvalidArgumentException('Unknown compatibility profile.');
        }
        $values['compatibility-profile'] = $profile;
        (new SchemaValidator())->validate($values);
        if (array_key_exists('skip-db-check', $values)) {
            $diagnostics[] = 'Deprecated skip-db-check option: use db-check=false.';
        }

        return new LoadedConfig($values, $provenance, $diagnostics, $profile);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, array<string, mixed>>
     */
    private function sources(string $root, array $extra): array
    {
        $sources = [];
        foreach (['wpstarter', 'sympress-runtime'] as $family) {
            if (array_key_exists($family, $extra)) {
                $input = $extra[$family];
                $sources['extra.' . $family] = is_string($input)
                    ? $this->readObject(Path::makeAbsolute(ltrim($input, '/'), $root))
                    : $this->objectArray($input, 'extra.' . $family);
            }
            if (!is_file($root . '/' . $family . '.json')) {
                continue;
            }

            $sources[$family . '.json'] = $this->readObject($root . '/' . $family . '.json');
        }

        return $sources;
    }

    /** @return array<string, mixed> */
    public function readObject(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Configuration file is missing or unreadable: ' . $path);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException('Cannot read configuration file: ' . $path);
        }
        try {
            $object = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            if (!$object instanceof stdClass) {
                throw new InvalidArgumentException('Configuration must contain a JSON object: ' . $path);
            }
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return $this->objectArray($data, $path);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Invalid JSON in ' . $path . ': ' . $error->getMessage(), previous: $error);
        }
    }

    /** @return array<string, mixed> */
    private function objectArray(mixed $value, string $source): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($source . ' must be an object or JSON file path.');
        }
        $object = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($source . ' must have named keys.');
            }
            $object[$key] = $entry;
        }

        return $object;
    }
}
