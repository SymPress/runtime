<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/** @internal */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    /** @var list<array{name: string, version: string}> */
    private array $updatedPackages = [];

    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /** @return array<string, array{string, int}|string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['run', 0],
            ScriptEvents::POST_UPDATE_CMD => ['run', 0],
            PackageEvents::PRE_PACKAGE_UPDATE => 'recordUpdate',
            PackageEvents::PRE_PACKAGE_INSTALL => 'recordUpdate',
        ];
    }

    /** @return array<class-string, class-string> */
    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }

    public function recordUpdate(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $package = match (true) {
            $operation instanceof UpdateOperation => $operation->getTargetPackage(),
            $operation instanceof InstallOperation => $operation->getPackage(),
            default => null,
        };
        if ($package === null) {
            return;
        }

        $this->updatedPackages[] = ['name' => $package->getName(), 'version' => $package->getPrettyVersion()];
    }

    public function run(Event $event): void
    {
        if (in_array($event->getComposer()->getPackage()->getType(), ['sympress-runtime-extension', 'wpstarter-extension'], true)) {
            return;
        }
        $root = getcwd();
        if ($root !== false && !(new AutomaticSetup())->required($event->getComposer(), $root)) {
            $this->updatedPackages = [];
            $event->getIO()->writeError('Runtime setup deferred: no WordPress core or Runtime configuration found. Configure the project, then run vendor/bin/runtime.');

            return;
        }
        $mode = $event->getName() === ScriptEvents::POST_INSTALL_CMD ? 'install' : 'update';
        $context = (new ContextFactory())->create($event->getComposer(), $event->getIO(), $mode, $event->isDevMode(), $this->updatedPackages);
        $status = (new RunnerProcess())->run($context);
        $this->updatedPackages = [];
        if ($status !== 0) {
            throw new ScriptExecutionException('SymPress Runtime failed with exit code ' . $status . '.', $status);
        }
    }
}
