<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Parser inputs are restored before returning to the environment boundary.
/** @internal */
final class EnvCacheInterpolation
{
    /** @var array<string, array{marker: string, fingerprint: string|null}> */
    private array $dependencies = [];
    /** @var array<string, string> */
    private array $templates = [];
    /** @var array<string, string> */
    private array $resolved = [];
    private bool $external = false;
    private bool $uncacheable = false;

    /**
     * @param callable(string): ?string $external
     * @return array<string, string>
     */
    public function parse(Dotenv $dotenv, string $content, string $path, callable $external): array
    {
        $replacements = [];
        if (preg_match_all('/\\$\\{?([A-Za-z_][A-Za-z0-9_]*)/', $content, $references)) {
            foreach ($references[1] as $name) {
                $value = $external($name);
                $marker = $this->dependencies[$name]['marker'] ?? 'SYMPRESS_INTERPOLATION_' . bin2hex(random_bytes(16));
                $this->dependencies[$name] = ['marker' => $marker, 'fingerprint' => self::fingerprint($marker, $value)];
                if ($value === null) {
                    continue;
                }
                $this->external = true;
                if ($value === '') {
                    continue;
                }
                $replacements[$marker] = $value;
            }
        }
        // Commands may transform secrets and cannot be represented by substitution templates.
        if (str_contains($content, '$(')) {
            $this->uncacheable = $this->uncacheable || $this->external;
            $values = self::validateValues($dotenv->parse($content, $path));
            foreach (array_keys($values) as $name) {
                unset($this->templates[$name], $this->resolved[$name]);
            }
            return $values;
        }
        foreach ($this->dependencies as $name => $dependency) {
            $value = $external($name);
            if ($value === null || $value === '') {
                continue;
            }
            $replacements[$dependency['marker']] = $value;
        }
        $environment = $_ENV;
        try {
            foreach ($this->templates as $name => $template) {
                if ($external($name) !== null) {
                    continue;
                }
                $_ENV[$name] = $template;
            }
            foreach ($this->dependencies as $name => $dependency) {
                if (!isset($replacements[$dependency['marker']])) {
                    continue;
                }
                $_ENV[$name] = $dependency['marker'];
            }
            $templates = self::validateValues($dotenv->parse($content, $path));
        } finally {
            $_ENV = $environment;
        }
        $values = [];
        foreach ($templates as $name => $template) {
            $this->templates[$name] = $template;
            $values[$name] = strtr($template, $replacements);
            $this->resolved[$name] = $values[$name];
            $this->trackEnvironmentSelection($name, $template, $replacements);
        }
        return $values;
    }

    public function written(string $name, string $value): void
    {
        if (($this->resolved[$name] ?? null) === $value) {
            return;
        }
        unset($this->templates[$name], $this->resolved[$name]);
    }

    public function canPersist(bool $immutable): bool
    {
        return !$this->uncacheable && (!$immutable || !$this->external);
    }

    /** @return array{dependencies: array<string, array{marker: string, fingerprint: string|null}>, templates: array<string, string>} */
    public function payload(): array
    {
        $templates = [];
        foreach ($this->templates as $name => $template) {
            foreach ($this->dependencies as $dependency) {
                if (!str_contains($template, $dependency['marker'])) {
                    continue;
                }
                $templates[$name] = $template;
                break;
            }
        }
        return ['dependencies' => $this->dependencies, 'templates' => $templates];
    }

    /**
     * @param callable(string): ?string $external
     * @return array<string, string>|null Null means a dependency changed.
     */
    public static function resolve(mixed $payload, callable $external): ?array
    {
        if ($payload === null) {
            return [];
        }
        if (!is_array($payload) || !is_array($payload['dependencies'] ?? null) || !is_array($payload['templates'] ?? null)) {
            throw new RuntimeException('Environment cache interpolation data is invalid.');
        }
        $replacements = [];
        $changed = false;
        foreach ($payload['dependencies'] as $name => $dependency) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_array($dependency) || !is_string($dependency['marker'] ?? null) || !preg_match('/^SYMPRESS_INTERPOLATION_[a-f0-9]{32}$/D', $dependency['marker']) || !array_key_exists('fingerprint', $dependency) || ($dependency['fingerprint'] !== null && (!is_string($dependency['fingerprint']) || !preg_match('/^[a-f0-9]{64}$/D', $dependency['fingerprint'])))) {
                throw new RuntimeException('Environment cache interpolation dependency is invalid.');
            }
            $value = $external($name);
            $changed = $changed || self::fingerprint($dependency['marker'], $value) !== $dependency['fingerprint'];
            $replacements[$dependency['marker']] = $value ?? '';
        }
        $values = [];
        foreach ($payload['templates'] as $name => $template) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_string($template)) {
                throw new RuntimeException('Environment cache interpolation template is invalid.');
            }
            $values[$name] = strtr($template, $replacements);
        }
        return $changed ? null : $values;
    }

    private static function fingerprint(string $marker, ?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $marker . "\0" . $value);
    }

    /** @param array<string, string> $replacements */
    private function trackEnvironmentSelection(string $name, string $template, array $replacements): void
    {
        if (!in_array($name, ['WP_ENVIRONMENT_TYPE', 'WP_ENV', 'WORDPRESS_ENV'], true)) {
            return;
        }
        foreach (array_keys($replacements) as $marker) {
            if (!str_contains($template, $marker)) {
                continue;
            }
            // The selected environment and attempted source paths are persisted metadata.
            $this->uncacheable = true;
        }
    }

    /** @return array<string, string> */
    private static function validateValues(mixed $values): array
    {
        if (!is_array($values)) {
            throw new RuntimeException('Environment parser returned invalid values.');
        }
        foreach ($values as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new RuntimeException('Environment parser returned an invalid value.');
            }
        }
        return $values;
    }
}
