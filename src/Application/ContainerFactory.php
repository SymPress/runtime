<?php

declare(strict_types=1);

namespace SymPress\Runtime\Application;

use InvalidArgumentException;
use ReflectionClass;
use SymPress\Runtime\Compatibility\ComposerConfiguration;
use SymPress\Runtime\Compatibility\LegacyApi;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Database\DatabaseProbe;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Database\MysqliProbe;
use SymPress\Runtime\Download\DownloadLock;
use SymPress\Runtime\Download\PharInstaller;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Env\EnvFactory;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Event\Lifecycle;
use SymPress\Runtime\Filesystem\ContentPublisher;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\ArtifactWriter;
use SymPress\Runtime\Generation\RuntimeBundleBuilder;
use SymPress\Runtime\Generation\SaltStore;
use SymPress\Runtime\Generation\Salter;
use SymPress\Runtime\Generation\SectionMerger;
use SymPress\Runtime\Generation\WpConfigGenerator;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Kernel\BootOwnership;
use SymPress\Runtime\Kernel\KernelPaths;
use SymPress\Runtime\Package\ExtensionMetadata;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Process\PhpProcess;
use SymPress\Runtime\Process\PhpToolProcessFactory;
use SymPress\Runtime\Process\SystemProcess;
use SymPress\Runtime\Process\WpCliTool;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\AsRuntimeStep;
use SymPress\Runtime\Step\Builtin\KernelBootStep;
use SymPress\Runtime\Step\Builtin\KernelCacheStep;
use SymPress\Runtime\Step\Definition;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Step\ScriptDispatcher;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition as ServiceDefinition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as EventDispatcherContract;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @internal */
final class ContainerFactory
{
    public function create(Config $config, Paths $paths, Io $io, RunContext $context, Registry $registry, ?callable $configure = null, Selection $selection = new Selection()): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('event_dispatcher', EventDispatcher::class)->setPublic(true);
        $container->setAlias(EventDispatcherInterface::class, 'event_dispatcher')->setPublic(true);
        $container->setAlias(EventDispatcherContract::class, 'event_dispatcher')->setPublic(true);
        $container->registerForAutoconfiguration(EventSubscriberInterface::class)->addTag('kernel.event_subscriber');
        $container->register(ScriptDispatcher::class)->setAutowired(true)->addTag('kernel.event_subscriber');
        $container->register(Lifecycle::class)->setAutowired(true)->setPublic(true);
        $container->addCompilerPass(new RegisterListenersPass());
        $container->register(HttpClientInterface::class)->setFactory([HttpClient::class, 'create']);
        $container->register(EnvFactory::class)->setAutowired(true);
        $container->register(EnvReader::class)->setFactory([new Reference(EnvFactory::class), 'create'])->setLazy(true)->setPublic(true);
        $container->register(MysqliProbe::class);
        $container->setAlias(DatabaseProbe::class, MysqliProbe::class);
        $container->register(DbChecker::class)->setAutowired(true)->setPublic(true);
        $container->register(DatabasePreflight::class)->setAutowired(true)->setPublic(true);
        $container->register(ProjectBoundary::class)->setAutowired(true);
        $container->register(ContentPublisher::class)->setAutowired(true);
        $container->register(KernelPaths::class)->setAutowired(true)->setPublic(true);
        $container->register(KernelCacheStep::class)->setAutowired(true)->setPublic(true);
        $container->register(BootOwnership::class)->setAutowired(true)->setPublic(true);
        $container->register(KernelBootStep::class)->setAutowired(true)->setPublic(true);
        if ($config['kernel-boot']->is(true) || is_file($paths->wpContent('mu-plugins/sympress-runtime-kernel.php'))) {
            $registry->add(new Definition('kernel-boot', KernelBootStep::class));
        }
        foreach (Registry::IMPLEMENTATIONS as $class) {
            $container->register($class)->setAutowired(true)->setPublic(true);
        }
        $container->registerAttributeForAutoconfiguration(AsRuntimeStep::class, static function (ChildDefinition $definition, AsRuntimeStep $attribute): void {
            $definition->addTag('sympress.runtime.step', ['name' => $attribute->name, 'priority' => $attribute->priority]);
        });
        $container->addCompilerPass(new StepRegistrationPass($registry));
        $instances = [Config::class => $config, Paths::class => $paths, Io::class => $io, RunContext::class => $context, Selection::class => $selection];
        foreach ($instances as $id => $service) {
            $container->setDefinition($id, (new ServiceDefinition($id))->setSynthetic(true)->setPublic(true));
        }
        foreach ([Filesystem::class, FileContentBuilder::class, OverwritePolicy::class, PackageFinder::class, MuPluginList::class, UrlDownloader::class, PharInstaller::class, PhpToolProcessFactory::class, WpCliTool::class, SystemProcess::class, PhpProcess::class, ExecutableFinder::class, Salter::class, WpConfigSectionEditor::class, ComposerConfiguration::class, Services::class] as $class) {
            $container->register($class, $class)->setAutowired(true)->setPublic(true);
        }
        foreach ([SaltStore::class, ArtifactWriter::class, RuntimeBundleBuilder::class, SectionMerger::class, WpConfigGenerator::class] as $class) {
            $container->register($class)->setAutowired(true)->setPublic(true);
        }
        $container->register(DownloadLock::class)->setAutowired(true);
        $mode = $config['generated-file-mode']->unwrap();
        $container->getDefinition(ArtifactWriter::class)->setArgument('$mode', is_string($mode) ? octdec($mode) : 0600);
        $container->getDefinition(RuntimeBundleBuilder::class)->setArgument('$bundleBootstrap', $config['bundle-bootstrap']->is(true));
        if (!$config['compatibility']->is(false)) {
            LegacyApi::configure($container);
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
                if ($config['compatibility']->is(false)) {
                    continue;
                }
                LegacyApi::configureStep($container->getDefinition($class), $class);
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
                if ($config['compatibility']->is(false)) {
                    continue;
                }
                LegacyApi::configureStep($container->getDefinition($class), $class);
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
