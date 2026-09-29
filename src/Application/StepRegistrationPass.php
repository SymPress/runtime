<?php

declare(strict_types=1);

namespace SymPress\Runtime\Application;

use InvalidArgumentException;
use SymPress\Runtime\Step\Definition;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class StepRegistrationPass implements CompilerPassInterface
{
    public function __construct(private Registry $registry)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('sympress.runtime.step') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();
            if ($class === null || !is_subclass_of($class, StepInterface::class)) {
                throw new InvalidArgumentException('Tagged service must implement StepInterface: ' . $id);
            }
            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    throw new InvalidArgumentException('Step tag must be an attribute map.');
                }
                $name = $tag['name'] ?? null;
                $priority = $tag['priority'] ?? 0;
                if (!is_string($name) || !is_int($priority)) {
                    throw new InvalidArgumentException('Step tag requires a string name and integer priority.');
                }
                $existing = $this->registry->all()[$name] ?? null;
                if ($existing?->class !== null && ($existing->class !== $class || ($existing->serviceId ?? $existing->class) !== $id)) {
                    throw new InvalidArgumentException('Conflicting step registrations: ' . $name);
                }
                $commandOnly = $existing->commandOnly ?? (($tag['command-only'] ?? false) === true);
                $this->registry->add(new Definition($name, $class, !$commandOnly, $commandOnly, $priority, $id));
                $definition->setPublic(true);
            }
        }
    }
}
