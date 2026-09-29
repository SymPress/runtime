<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use InvalidArgumentException;
use SymPress\Runtime\Step\Builtin\CheckPathsStep;
use SymPress\Runtime\Step\Builtin\DropinsStep;
use SymPress\Runtime\Step\Builtin\EnvExampleStep;
use SymPress\Runtime\Step\Builtin\FlushEnvCacheStep;
use SymPress\Runtime\Step\Builtin\IndexStep;
use SymPress\Runtime\Step\Builtin\KernelBootStep;
use SymPress\Runtime\Step\Builtin\KernelCacheStep;
use SymPress\Runtime\Step\Builtin\MoveContentStep;
use SymPress\Runtime\Step\Builtin\MuLoaderStep;
use SymPress\Runtime\Step\Builtin\PublishContentDevStep;
use SymPress\Runtime\Step\Builtin\VcsIgnoreCheckStep;
use SymPress\Runtime\Step\Builtin\WpCliConfigStep;
use SymPress\Runtime\Step\Builtin\WpCliStep;
use SymPress\Runtime\Step\Builtin\WpConfigStep;

final class Registry
{
    public const array DEFAULT_ORDER = [
        'checkpaths', 'wpconfig', 'index', 'flushenvcache', 'muloader', 'envexample',
        'dropins', 'movecontent', 'publishcontentdev', 'vcsignorecheck', 'wpcliconfig', 'wpcli',
    ];

    public const array RESERVED = ['validate', 'doctor', 'check', 'dump-env', 'migrate', 'flush-env-cache'];

    public const array IMPLEMENTATIONS = [
        'checkpaths' => CheckPathsStep::class,
        'wpconfig' => WpConfigStep::class,
        'index' => IndexStep::class,
        'flushenvcache' => FlushEnvCacheStep::class,
        'muloader' => MuLoaderStep::class,
        'envexample' => EnvExampleStep::class,
        'wpcliconfig' => WpCliConfigStep::class,
        'wpcli' => WpCliStep::class,
        'movecontent' => MoveContentStep::class,
        'dropins' => DropinsStep::class,
        'publishcontentdev' => PublishContentDevStep::class,
        'vcsignorecheck' => VcsIgnoreCheckStep::class,
    ];

    /** @var array<string, Definition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach (self::DEFAULT_ORDER as $name) {
            $this->definitions[$name] = new Definition($name, self::IMPLEMENTATIONS[$name]);
        }
        $this->definitions['kernel-cache'] = new Definition('kernel-cache', KernelCacheStep::class, commandOnly: true);
        $this->definitions['kernel-boot'] = new Definition('kernel-boot', KernelBootStep::class, commandOnly: true);
    }

    public function add(Definition $definition): void
    {
        if ($definition->name === '' || in_array($definition->name, self::RESERVED, true)) {
            throw new InvalidArgumentException('Invalid or reserved step name: ' . $definition->name);
        }
        $this->definitions[$definition->name] = $definition;
    }

    /** @return array<string, Definition> */
    public function all(string $profile = 'native'): array
    {
        $definitions = $this->definitions;
        if ($profile === 'upstream-dev') {
            $order = ['checkpaths', 'wpconfig', 'index', 'flushenvcache', 'muloader', 'envexample', 'dropins', 'movecontent', 'publishcontentdev', 'wpcliconfig', 'vcsignorecheck', 'wpcli'];
            $ordered = [];
            foreach ($order as $name) {
                $ordered[$name] = $definitions[$name];
            }
            $definitions = $ordered + $definitions;
        }
        uasort($definitions, static fn (Definition $left, Definition $right): int => $right->priority <=> $left->priority);

        return $definitions;
    }

    public function resolve(string $name, bool $compatibility = true): ?Definition
    {
        if (isset($this->definitions[$name])) {
            return $this->definitions[$name];
        }
        if (!$compatibility) {
            return null;
        }
        $normalized = $this->normalize($name);
        if ($normalized === 'wpcliyml') {
            $normalized = 'wpcliconfig';
        }
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
