<?php

declare(strict_types=1);

namespace SymPress\Runtime\Kernel;

use RuntimeException;
use SymPress\Kernel\App;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Env\EnvironmentName;
use SymPress\Runtime\Filesystem\Paths;
use Symfony\Component\Filesystem\Path;

final readonly class KernelPaths
{
    public function __construct(private EnvReader $env, private Paths $paths)
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
        return $this->directory('APP_CACHE_DIR') ?? $this->paths->root('var/cache/' . $this->environment() . '/kernel');
    }

    public function build(): string
    {
        return $this->directory('APP_BUILD_DIR') ?? $this->cache();
    }

    public function discovery(): string
    {
        return $this->paths->root('var/cache/' . $this->environment() . '/kernel/discovery-packages.php');
    }

    public function buildIdFile(): string
    {
        return $this->paths->root('var/runtime/' . $this->environment() . '/kernel-build-id.json');
    }

    /** @return list<string> */
    public function clearTargets(): array
    {
        $targets = array_values(array_unique([$this->cache(), $this->build()]));
        if (!array_any($targets, fn (string $target): bool => Path::isBasePath($target, $this->discovery()))) {
            $targets[] = $this->discovery();
        }

        return $targets;
    }

    private function directory(string $name): ?string
    {
        $value = trim($this->env->rawValue($name) ?? '');

        return $value === '' ? null : Path::makeAbsolute($value, $this->paths->root()) . '/' . $this->environment() . '/kernel';
    }
}
