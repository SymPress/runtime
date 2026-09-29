<?php

declare(strict_types=1);

namespace SymPress\Runtime\Application;

use InvalidArgumentException;
use ReflectionClass;
use SymPress\Runtime\Compatibility\ComposerConfiguration;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Database\DatabaseProbe;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Database\MysqliProbe;
use SymPress\Runtime\Download\PharInstaller;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Env\EnvFactory;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\Salter;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Package\ExtensionMetadata;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Process\PhpProcess;
use SymPress\Runtime\Process\PhpToolProcessFactory;
use SymPress\Runtime\Process\SystemProcess;
use SymPress\Runtime\Process\WpCliTool;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\AsRuntimeStep;
use SymPress\Runtime\Step\Definition;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition as ServiceDefinition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContainerFactory
{
    public function create(Config $config, Paths $paths, Io $io, RunContext $context, Registry $registry, ?callable $configure = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(HttpClientInterface::class)->setFactory([HttpClient::class, 'create']);
        $container->register(EnvFactory::class)->setAutowired(true);
        $container->register(EnvReader::class)->setFactory([new Reference(EnvFactory::class), 'create'])->setLazy(true)->setPublic(true);
        $container->register(MysqliProbe::class);
        $container->setAlias(DatabaseProbe::class, MysqliProbe::class);
        $container->register(DbChecker::class)->setAutowired(true)->setPublic(true);
        $container->register(DatabasePreflight::class)->setAutowired(true)->setPublic(true);
        $container->register(ProjectBoundary::class)->setAutowired(true);
        foreach (Registry::IMPLEMENTATIONS as $class) {
            $container->register($class)->setAutowired(true)->setPublic(true);
        }
        $container->registerAttributeForAutoconfiguration(AsRuntimeStep::class, static function (ChildDefinition $definition, AsRuntimeStep $attribute): void {
            $definition->addTag('sympress.runtime.step', ['name' => $attribute->name, 'priority' => $attribute->priority]);
        });
        $container->addCompilerPass(new StepRegistrationPass($registry));
        $instances = [Config::class => $config, Paths::class => $paths, Io::class => $io, RunContext::class => $context];
        foreach ($instances as $id => $service) {
            $container->setDefinition($id, (new ServiceDefinition($id))->setSynthetic(true)->setPublic(true));
        }
        foreach ([Filesystem::class, FileContentBuilder::class, OverwritePolicy::class, PackageFinder::class, MuPluginList::class, UrlDownloader::class, PharInstaller::class, PhpToolProcessFactory::class, WpCliTool::class, SystemProcess::class, PhpProcess::class, ExecutableFinder::class, Salter::class, WpConfigSectionEditor::class, ComposerConfiguration::class, Services::class] as $class) {
            $container->register($class, $class)->setAutowired(true)->setPublic(true);
        }
        foreach (['custom-steps' => false, 'command-steps' => true, 'steps' => false] as $option => $commandOnly) {
            $steps = $config[$option]->unwrapOrFallback([]);
            if (!is_array($steps)) {
                throw new InvalidArgumentException('Invalid step configuration.');
            }
            foreach ($steps as $name => $class) {
                if (!is_string($name) || !is_string($class) || !is_subclass_of($class, StepInterface::class)) {
                    throw new InvalidArgumentException('Configured step must implement ' . StepInterface::class . '.');
                }
                $attributes = (new ReflectionClass($class))->getAttributes(AsRuntimeStep::class);
                $attribute = $attributes === [] ? null : $attributes[0]->newInstance();
                $name = $attribute->name ?? $name;
                $priority = $attribute->priority ?? 0;
                $registry->add(new Definition($name, $class, !$commandOnly, $commandOnly, $priority));
                $container->register($class, $class)->setAutowired(true)->setPublic(true)
                    ->addTag('sympress.runtime.step', ['name' => $name, 'priority' => $priority]);
            }
        }
        foreach ((new PackageFinder($context))->all() as $package) {
            $steps = (new ExtensionMetadata($paths))->steps($package);
            foreach ($steps as $name => $class) {
                if (!is_string($class) || !is_subclass_of($class, StepInterface::class)) {
                    throw new InvalidArgumentException('Contributed step must implement StepInterface: ' . $package->getName());
                }
                $attributes = (new ReflectionClass($class))->getAttributes(AsRuntimeStep::class);
                $attribute = $attributes === [] ? null : $attributes[0]->newInstance();
                $name = $attribute->name ?? $name;
                if (!is_string($name)) {
                    throw new InvalidArgumentException('Contributed step requires a name.');
                }
                $container->register($class, $class)->setAutowired(true)->setPublic(true)
                    ->addTag('sympress.runtime.step', ['name' => $name, 'priority' => $attribute->priority ?? 0]);
            }
        }
        if ($configure !== null) {
            $configure($container);
        }
        $container->compile();
        foreach ($instances as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }
}
