<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use InvalidArgumentException;
use SymPress\Runtime\Step\Builtin\CheckPathsStep;
use SymPress\Runtime\Step\Builtin\FlushEnvCacheStep;
use SymPress\Runtime\Step\Builtin\IndexStep;

final class Registry
{
    public const array DEFAULT_ORDER = [
        'checkpaths', 'wpconfig', 'index', 'flushenvcache', 'muloader', 'envexample',
        'dropins', 'movecontent', 'publishcontentdev', 'vcsignorecheck', 'wpcliconfig', 'wpcli',
    ];

    public const array RESERVED = ['validate', 'doctor', 'check', 'dump-env', 'migrate', 'flush-env-cache'];

    public const array IMPLEMENTATIONS = [
        'checkpaths' => CheckPathsStep::class,
        'index' => IndexStep::class,
        'flushenvcache' => FlushEnvCacheStep::class,
    ];

    /** @var array<string, Definition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach (self::DEFAULT_ORDER as $name) {
            $this->definitions[$name] = new Definition($name, self::IMPLEMENTATIONS[$name] ?? null);
        }
    }

    public function add(Definition $definition): void
    {
        if ($definition->name === '' || in_array($definition->name, self::RESERVED, true)) {
            throw new InvalidArgumentException('Invalid or reserved step name: ' . $definition->name);
        }
        $this->definitions[$definition->name] = $definition;
    }

    /** @return array<string, Definition> */
    public function all(): array
    {
        $definitions = $this->definitions;
        uasort($definitions, static fn (Definition $left, Definition $right): int => $right->priority <=> $left->priority);

        return $definitions;
    }

    public function resolve(string $name): ?Definition
    {
        if (isset($this->definitions[$name])) {
            return $this->definitions[$name];
        }
        $normalized = $this->normalize($name);
        foreach ($this->definitions as $definition) {
            if ($normalized !== '' && $this->normalize($definition->name) === $normalized) {
                return $definition;
            }
        }

        return null;
    }

    private function normalize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '', preg_replace('/^build/', '', $name) ?? $name) ?? $name;
    }
}
