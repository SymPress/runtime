<?php

declare(strict_types=1);

namespace SymPress\Runtime\Kernel;

use RuntimeException;
use SymPress\Kernel\App;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Env\EnvironmentName;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Filesystem\Path;

/** @internal */
final readonly class KernelPaths
{
    public function __construct(private EnvReader $env, private Paths $paths, private ?string $webroot = null)
    {
    }

    public function environment(): string
    {
        $environment = EnvironmentName::canonical($this->env->determineEnvType());
        if (class_exists(App::class) && App::kernel() !== null && App::kernel()->getEnvironment() !== $environment) {
            throw new RuntimeException('The active kernel environment differs from the runtime environment; align them before managing cache paths.');
        }

        return $environment;
    }

    public function cache(): string
    {
        return CacheLocation::resolve($this->paths->root(), $this->environment(), $this->configured('APP_CACHE_DIR'), $this->publicRoots());
    }

    public function build(): string
    {
        return $this->directory('APP_BUILD_DIR') ?? $this->cache();
    }

    public function discovery(): string
    {
        return $this->cache() . '/discovery-packages.json';
    }

    public function usesFallback(): bool
    {
        return $this->cache() === CacheLocation::fallbackRoot($this->paths->root()) . '/' . $this->environment() . '/kernel';
    }

    public function assertSafe(string $path, string $kind): void
    {
        $configured = $this->configured($kind === 'build' ? 'APP_BUILD_DIR' : 'APP_CACHE_DIR');
        if ($kind === 'build' && $configured === null) {
            $configured = $this->configured('APP_CACHE_DIR');
        }
        $root = $configured === null ? ($this->usesFallback() ? CacheLocation::fallbackRoot($this->paths->root()) : $this->paths->root('var/cache')) : Path::makeAbsolute($configured, $this->paths->root());
        $external = !Path::isBasePath($this->paths->root(), $root);
        if (!$external) {
            (new ProjectBoundary($this->paths))->assertWritablePath($path);
        }
        CacheLocation::assertTarget($path, $root, $external, $this->publicRoots());
    }

    public function buildIdFile(): string
    {
        return $this->paths->root('var/runtime/' . $this->environment() . '/kernel-build-id.json');
    }

    /** @return list<string> */
    public function clearTargets(): array
    {
        $targets = array_values(array_unique([$this->cache(), $this->build()]));
        // Retire only known default discovery artifacts; never adopt or delete
        // the unsafe implicit generation that caused Kernel's private fallback.
        if (!$this->usesFallback()) {
            foreach (['discovery-packages.json', 'discovery-packages.php'] as $file) {
                $legacy = $this->paths->root('var/cache/' . $this->environment() . '/kernel/' . $file);
                if (!is_file($legacy) || array_any($targets, static fn (string $target): bool => Path::isBasePath($target, $legacy))) {
                    continue;
                }
                (new ProjectBoundary($this->paths))->assertWritablePath($legacy);
                CacheLocation::assertTarget(dirname($legacy), $this->paths->root('var/cache'), false, $this->publicRoots());
                $targets[] = $legacy;
            }
        }

        return $targets;
    }

    private function directory(string $name): ?string
    {
        $value = $this->configured($name);

        return $value === null ? null : Path::makeAbsolute($value, $this->paths->root()) . '/' . $this->environment() . '/kernel';
    }

    /** @return list<string> */
    private function publicRoots(): array
    {
        $roots = [$this->paths->wpContent(), $this->paths->wp(), $this->paths->root('public')];
        if ($this->paths->wpParent() !== $this->paths->root()) {
            $roots[] = $this->paths->wpParent();
        }
        if ($this->webroot !== null) {
            $roots[] = Path::makeAbsolute($this->webroot, $this->paths->root());
        }

        return $roots;
    }

    private function configured(string $name): ?string
    {
        // Match KernelConfigurationResolver for already populated process data.
        // phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Keep the actual Kernel's configured-root precedence.
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? $this->env->rawValue($name);
        // phpcs:enable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable
        $value = is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '';
        if (preg_match('~(?:^|[/\\\\])\\.\\.(?:[/\\\\]|$)~', $value) === 1) {
            throw new RuntimeException('Kernel cache roots must not contain parent traversal segments.');
        }

        return $value === '' ? null : $value;
    }
}
