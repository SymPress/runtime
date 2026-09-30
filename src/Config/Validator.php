<?php

declare(strict_types=1);

namespace SymPress\Runtime\Config;

use InvalidArgumentException;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\WordPress\VersionDiscovery;
use Symfony\Component\Filesystem\Path;

final class Validator
{
    public function __construct(private readonly Paths $paths, private readonly string $profile = 'native')
    {
    }

    public function validate(string $key, mixed $value): Result
    {
        $validated = match ($key) {
            'cache-env', 'install-wp-cli', 'require-wp', 'skip-db-check', 'compatibility',
            'env-local-overrides', 'allow-insecure-downloads', 'require-download-checksums',
            'kernel-boot' => $this->boolean($value),
            'check-vcs-ignore', 'create-vcs-ignore-file', 'move-content',
            'register-theme-folder', 'unknown-dropins' => $this->boolOrAsk($value),
            'db-check' => is_string($value) && strtolower($value) === 'health' ? 'health' : $this->boolean($value),
            'content-dev-op', 'dropins-op' => $this->operation($value),
            'env-file' => $this->filename($value),
            'autoload' => $this->optionalPath($value, 'file', $this->profile === 'native' ? 'sympress-runtime-autoload.php' : 'wpstarter-autoload.php'),
            'content-dev-dir' => $this->optionalPath($value, 'directory', 'content-dev'),
            'templates-dir' => $this->optionalPath($value, 'directory'),
            'early-hook-file' => $value === '' ? null : $this->optionalPath($value, 'file'),
            'env-dir', 'env-bootstrap-dir' => $value === null ? null : $this->path($value),
            'env-example' => $this->example($value),
            'prevent-overwrite' => is_array($value) ? $this->strings($value) : ($value === null ? null : $this->boolOrAsk($value)),
            'skip-steps' => $value === null ? null : $this->strings($value),
            'custom-steps', 'command-steps', 'steps' => $this->steps($value),
            'scripts' => $this->scripts($value),
            'dropins' => $this->dropins($value),
            'wp-cli-commands' => $this->commands($value),
            'wp-cli-files' => $this->files($value),
            'wp-version' => $this->version($value),
            'compatibility-profile' => $this->profile($value),
            'download-checksums' => $this->checksums($value),
            'kernel-build-id' => $value === null ? null : $this->string($value),
            default => $value,
        };

        return Result::ok($validated);
    }

    private function boolean(mixed $value): bool
    {
        $bool = $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new InvalidArgumentException('Expected a boolean or a recognized boolean string/number.');
        }

        return $bool;
    }

    private function boolOrAsk(mixed $value): bool|string
    {
        return $value === 'ask' ? 'ask' : $this->boolean($value);
    }

    private function operation(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $operation = strtolower(trim($value));
            if (in_array($operation, ['auto', 'copy', 'symlink', 'none', 'ask'], true)) {
                return $operation;
            }
        }

        return $this->boolean($value) ? ($this->profile === 'release-3.0.1' ? 'symlink' : 'auto') : 'none';
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Expected a non-empty string without null bytes.');
        }

        return $value;
    }

    private function filename(mixed $value): string
    {
        $name = $this->string($value);
        if (preg_match('~[\\\\/\x00-\x1f$+!*(),{}|^\\[\\]`"<>#;?:&\x27]~', $name) || str_contains($name, '..') || trim($name, ' .~%@=') === '') {
            throw new InvalidArgumentException('Expected a simple filename without traversal or reserved characters.');
        }

        return $name;
    }

    private function path(mixed $value): string
    {
        $path = $this->string($value);
        if (str_contains($path, '://')) {
            throw new InvalidArgumentException('Expected a local filesystem path.');
        }

        return Path::makeAbsolute($path, $this->paths->root());
    }

    private function optionalPath(mixed $value, string $kind, ?string $optionalDefault = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $path = $this->path($value);
        $exists = $kind === 'file' ? is_file($path) : is_dir($path);
        if (!$exists && $value === $optionalDefault) {
            return null;
        }
        if (!$exists || !is_readable($path)) {
            throw new InvalidArgumentException('Expected a readable existing ' . $kind . '.');
        }

        return $path;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected an array of strings.');
        }

        return array_map($this->string(...), array_values($value));
    }

    private function example(mixed $value): bool|string
    {
        if ($value === 'ask') {
            return 'ask';
        }
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
            return $this->source($value);
        }

        return $this->boolean($value);
    }

    private function source(mixed $value): string
    {
        $source = $this->string($value);
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            if (!in_array(strtolower((string) parse_url($source, PHP_URL_SCHEME)), ['https', 'http'], true)) {
                throw new InvalidArgumentException('Only HTTP(S) download URLs are supported.');
            }

            return $source;
        }

        return $this->optionalPath($source, 'file') ?? throw new InvalidArgumentException('Expected an existing source file.');
    }

    /** @return array<string, string>|null */
    private function steps(mixed $value): ?array
    {
        if (!$value) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected a class-string list or named step map.');
        }
        $steps = [];
        foreach ($value as $name => $class) {
            $class = ltrim(trim($this->string($class)), '\\');
            if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/D', $class)) {
                throw new InvalidArgumentException('Invalid step class name.');
            }
            $name = is_string($name) ? trim($name) : basename(str_replace('\\', '/', $class));
            $steps[$this->string($name)] = $class;
        }

        return $steps;
    }

    /** @return array<string, list<string|\Closure>> */
    private function scripts(mixed $value): array
    {
        if (!$value) {
            return [];
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected a map of pre/post callbacks.');
        }
        $scripts = [];
        foreach ($value as $name => $callbacks) {
            if (!is_string($name) || !preg_match('/^(pre|post)-/i', $name)) {
                throw new InvalidArgumentException('Script names must start with pre- or post-.');
            }
            $callbacks = is_string($callbacks) ? [$callbacks] : $callbacks;
            if (!is_array($callbacks)) {
                throw new InvalidArgumentException('Expected an array of callbacks.');
            }
            $normalized = [];
            foreach ($callbacks as $callback) {
                if (!is_string($callback) && is_callable($callback)) {
                    $normalized[] = \Closure::fromCallable($callback);
                    continue;
                }
                if (is_array($callback) && array_is_list($callback) && count($callback) === 2 && is_string($callback[0]) && is_string($callback[1])) {
                    $callback = $callback[0] . '::' . $callback[1];
                }
                $callback = $this->string($callback);
                if (!preg_match('/^\\\\?[a-zA-Z_][a-zA-Z0-9_\\\\]*(?:::[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $callback)) {
                    throw new InvalidArgumentException('Invalid callback name.');
                }
                $normalized[] = $callback;
            }
            $scripts[strtolower($name)] = $normalized;
        }

        return $scripts;
    }

    /** @return array<string, string>|null */
    private function dropins(mixed $value): ?array
    {
        if (!$value) {
            return null;
        }
        if (!is_array($value) && !is_string($value)) {
            throw new InvalidArgumentException('Expected dropin paths or a map.');
        }
        $dropins = [];
        foreach (is_string($value) ? [$value] : $value as $name => $source) {
            $source = $this->source($source);
            $name = is_string($name) ? $name : basename((string) (parse_url($source, PHP_URL_PATH) ?: $source));
            $dropins[$this->filename($name)] = $source;
        }

        return $dropins;
    }

    /** @return list<string>|string|null */
    private function commands(mixed $value): array|string|null
    {
        if (!$value) {
            return null;
        }
        if (is_string($value)) {
            $path = $this->optionalPath($value, 'file');
            if ($path === null || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'json'], true)) {
                throw new InvalidArgumentException('Command provider must be a PHP or JSON file.');
            }

            return $path;
        }
        $commands = $this->strings($value);
        foreach ($commands as $command) {
            if (!str_starts_with($command, 'wp ')) {
                throw new InvalidArgumentException('WP-CLI commands must start with "wp ".');
            }
        }

        return $commands;
    }

    /** @return list<array{file: string, args: list<string>, skip-wordpress: bool}>|null */
    private function files(mixed $value): ?array
    {
        if (!$value) {
            return null;
        }
        $value = is_string($value) ? [$value] : $value;
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected a list of WP-CLI file descriptors.');
        }
        $files = [];
        foreach ($value as $file) {
            $file = is_string($file) ? ['file' => $file] : $file;
            if (!is_array($file)) {
                throw new InvalidArgumentException('Expected file, args and skip-wordpress fields.');
            }
            $path = $this->optionalPath($file['file'] ?? null, 'file');
            if ($path === null || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                throw new InvalidArgumentException('Expected a readable PHP file.');
            }
            $files[] = ['file' => $path, 'args' => $this->fileArguments($file['args'] ?? []), 'skip-wordpress' => $this->boolean($file['skip-wordpress'] ?? false)];
        }

        return $files;
    }

    /** @return list<string> */
    private function fileArguments(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('WP-CLI file arguments must be an array.');
        }
        $arguments = [];
        foreach ($value as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('WP-CLI file arguments must be strings without null bytes.');
            }
            if ($argument === '' || ($this->profile !== 'native' && $argument === '0')) {
                continue;
            }
            $arguments[] = $argument;
        }

        return $arguments;
    }

    private function version(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Expected a numeric WordPress version.');
        }
        $version = VersionDiscovery::normalize((string) $value);
        if ($version === '') {
            throw new InvalidArgumentException('Expected a numeric WordPress version.');
        }

        return $version;
    }

    private function profile(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['native', 'release-3.0.1', 'upstream-dev'], true)) {
            throw new InvalidArgumentException('Unknown compatibility profile.');
        }

        return $value;
    }

    /** @return array<string, string> */
    private function checksums(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected URL to SHA-256 checksum map.');
        }
        $checksums = [];
        foreach ($value as $url => $hash) {
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !is_string($hash) || !preg_match('/^[0-9a-f]{64}$/iD', $hash)) {
                throw new InvalidArgumentException('Expected valid URLs and SHA-256 hex checksums.');
            }
            $checksums[$url] = strtolower($hash);
        }

        return $checksums;
    }
}
