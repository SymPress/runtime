<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Parser inputs are restored before returning to the environment boundary.
/** @internal */
final class EnvCacheInterpolation
{
    /** @var array<string, array{marker: string}> */
    private array $dependencies = [];
    /** @var array<string, string> */
    private array $templates = [];
    /** @var array<string, string> */
    private array $resolved = [];
    private bool $uncacheable = false;
    private ?EnvCacheExpressions $expressions = null;
    private ?Dotenv $dotenv = null;

    /**
     * @param callable(string): ?string $external
     * @return array<string, string>
     */
    public function parse(Dotenv $dotenv, string $content, string $path, callable $external): array
    {
        $this->dotenv = $dotenv;
        ($this->expressions ??= new EnvCacheExpressions())->record($content, $path);
        // The selected source chain cannot lose its history through later overrides.
        $this->uncacheable = $this->uncacheable || $this->expressions->hasDynamicEnvironmentSelection();
        $replacements = [];
        $externalReferences = [];
        $loaded = $_SERVER['SYMFONY_DOTENV_VARS'] ?? $_ENV['SYMFONY_DOTENV_VARS'] ?? '';
        $filePrecedence = array_fill_keys(is_string($loaded) ? explode(',', $loaded) : [], true);
        foreach ($this->expressions->referencesFor($path) as $name) {
            $value = $external($name);
            $marker = $this->dependencies[$name]['marker'] ?? 'SYMPRESS_INTERPOLATION_' . bin2hex(random_bytes(16));
            $this->dependencies[$name] = ['marker' => $marker];
            if ($value !== null && $value !== '') {
                $externalReferences[$name] = true;
            }
            if (str_starts_with($name, 'HTTP_') && !isset($_ENV[$name])) {
                $filePrecedence[$name] = true;
            }
            // HTTP getenv fallbacks remain live: injecting them into ENV would
            // override trusted file definitions that Dotenv normally prefers.
            if ($value === null || $value === '' || (str_starts_with($name, 'HTTP_') && !isset($_ENV[$name]))) {
                continue;
            }
            $replacements[$marker] = $value;
        }
        $this->expressions->trackExternalReferences($path, $externalReferences, $filePrecedence);
        // Commands may transform secrets and cannot be represented by substitution templates.
        if (str_contains($content, '$(')) {
            $values = self::validateValues($dotenv->parse($content, $path));
            foreach (array_keys($values) as $name) {
                unset($this->templates[$name]);
                $this->resolved[$name] = $values[$name];
            }
            return $values;
        }
        foreach ($this->dependencies as $name => $dependency) {
            $value = $external($name);
            if ($value === null || $value === '' || (str_starts_with($name, 'HTTP_') && !isset($_ENV[$name]))) {
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
        $this->expressions?->forget($name);
    }

    public function canPersist(bool $immutable): bool
    {
        $dynamic = $this->expressions?->payload()['dynamic'] ?? [];

        return !$this->uncacheable && array_intersect_key($dynamic, array_flip(['WP_ENVIRONMENT_TYPE', 'WP_ENV', 'WORDPRESS_ENV'])) === [];
    }

    /** @return array{dependencies: array<string, array{marker: string}>, templates: array<string, string>, expressions: array<string, string>, dynamic: array<string, true>, transient: array<string, true>, process: array<string, true>, replay: array<string, true>, refresh: bool, plans: array<string, mixed>|null} */
    public function payload(): array
    {
        $templates = [];
        $needed = [];
        foreach ($this->templates as $name => $template) {
            foreach ($this->dependencies as $reference => $dependency) {
                if (!str_contains($template, $dependency['marker'])) {
                    continue;
                }
                $templates[$name] = $template;
                $needed[$reference] = true;
            }
        }
        $expressions = $this->expressions?->payload() ?? ['expressions' => [], 'dynamic' => [], 'transient' => [], 'process' => [], 'needed' => [], 'replay' => []];
        $dependencies = array_intersect_key($this->dependencies, $needed + $expressions['needed']);
        unset($expressions['needed']);

        $plans = $this->dotenv === null ? [] : $this->expressions?->templates($this->dotenv);
        return ['dependencies' => $dependencies, 'templates' => $templates, 'refresh' => $expressions['dynamic'] !== [], 'plans' => $plans] + $expressions;
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
        foreach ($payload['dependencies'] as $name => $dependency) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_array($dependency) || !is_string($dependency['marker'] ?? null) || !preg_match('/^SYMPRESS_INTERPOLATION_[a-f0-9]{32}$/D', $dependency['marker'])) {
                throw new RuntimeException('Environment cache interpolation dependency is invalid.');
            }
            $value = $external($name);
            $replacements[$dependency['marker']] = $value ?? '';
        }
        $values = [];
        foreach ($payload['templates'] as $name => $template) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_string($template)) {
                throw new RuntimeException('Environment cache interpolation template is invalid.');
            }
            $values[$name] = strtr($template, $replacements);
        }
        return ($payload['dynamic'] ?? []) !== [] || ($payload['transient'] ?? []) !== [] || ($payload['refresh'] ?? false) ? null : $values;
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
