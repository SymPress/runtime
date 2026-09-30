<?php

declare(strict_types=1);

namespace SymPress\Runtime\Bridge\Kernel;

use SymPress\Kernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class RuntimeBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        foreach (['doctor', 'check', 'validate', 'dump-env'] as $operation) {
            $container->register(RuntimeConsoleCommand::class . '.' . $operation, RuntimeConsoleCommand::class)
                ->setArguments(['%kernel.project_dir%', $operation])
                ->addTag('console.command', ['command' => $operation]);
        }
    }
}
