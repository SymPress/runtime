<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;

final readonly class EnvironmentFiles
{
    public function __construct(private Config $config, private Paths $paths)
    {
    }

    /** @return list<string> */
    public function files(): array
    {
        [$directory, $name] = $this->location();
        $files = [];
        $artifacts = [$directory . EnvReader::BUILD_DUMP_FILE, $directory . EnvReader::CACHE_DUMP_FILE];
        $entries = @scandir($directory);
        if ($entries === false && is_dir($directory)) {
            throw new RuntimeException('Cannot safely inspect an unlistable environment directory.');
        }
        foreach ($entries ?: [] as $entry) {
            if (!str_starts_with($entry, $name)) {
                continue;
            }
            $file = $directory . '/' . $entry;
            if ((!is_file($file) && !is_link($file)) || ($file !== $directory . '/' . $name && in_array($file, $artifacts, true))) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }

    /** Existing paths in the active chain, selected without evaluating dotenv.
     *
     * @return list<string>
     */
    public function activeFiles(): array
    {
        [$directory, $name] = $this->location();
        $base = $directory . '/' . $name;
        $environment = $this->environment($base);
        $files = [$base];
        $local = $this->config['env-local-overrides']->is(true);
        if ($environment !== 'example') {
            if ($local && $environment !== 'test') {
                $files[] = $base . '.local';
            }
            $files[] = $base . '.' . $environment;
            if ($local) {
                $files[] = $base . '.' . $environment . '.local';
            }
        }

        return array_values(array_filter($files, static fn (string $file): bool => is_file($file) || is_link($file)));
    }

    /** @return array{string, string} */
    private function location(): array
    {
        $directory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
        $name = $this->config['env-file']->unwrap();
        if (!is_string($directory) || !is_string($name)) {
            throw new RuntimeException('Invalid environment file configuration.');
        }

        return [rtrim($directory, '/'), $name];
    }

    private function environment(string $base): string
    {
        $native = $this->config['compatibility-profile']->is('native');
        $names = $native ? ['WP_ENVIRONMENT_TYPE', 'WP_ENV', 'WORDPRESS_ENV'] : EnvReader::WP_STARTER_ENV_VARS;
        // This diagnostic owns a read-only superglobal boundary; it never populates values.
        // phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable
        $process = getenv();
        $values = array_replace($_SERVER, $_ENV, $native && is_array($process) ? $process : []);
        // phpcs:enable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable
        $content = is_file($base) && is_readable($base) ? file_get_contents($base) : false;
        $fileValues = [];
        foreach (is_string($content) ? explode("\n", $content) : [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?(WP_ENVIRONMENT_TYPE|WP_ENV|WORDPRESS_ENV)\s*=\s*(.*?)\s*$/D', $line, $match) !== 1) {
                continue;
            }
            $literal = preg_replace('/\s+#.*$/', '', $match[2]);
            $fileValues[$match[1]] = trim(is_string($literal) ? $literal : '', " \t\r\"'");
        }
        foreach ($names as $name) {
            $value = $values[$name] ?? $fileValues[$name] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1 || str_contains($value, '..')) {
                throw new RuntimeException('Environment selector cannot be resolved statically.');
            }

            return strtolower($value);
        }

        return 'production';
    }

    public function containsCommands(): bool
    {
        foreach ($this->files() as $file) {
            $content = @file_get_contents($file);
            if (is_string($content) && str_contains($content, '$(')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function names(string $file): array
    {
        $content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if (!is_string($content)) {
            return [];
        }
        preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $content, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
