<?php

declare(strict_types=1);

namespace SymPress\Runtime\Compatibility;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Result;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Question;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\BlockingStepInterface;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\Step\FileCreationStepInterface;
use SymPress\Runtime\Step\OptionalStepInterface;
use SymPress\Runtime\Step\PostProcessStepInterface;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\Step\ScriptHaltSignal;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class LegacyApi
{
    public const array ALIASES = [
        'Config\\Config' => Config::class,
        'Config\\Result' => Result::class,
        'Io\\Io' => Io::class,
        'Io\\Question' => Question::class,
        'Env\\WordPressEnvBridge' => EnvReader::class,
        'Util\\DbChecker' => DbChecker::class,
        'Util\\Paths' => Paths::class,
        'Util\\Locator' => Services::class,
        'Step\\Step' => StepInterface::class,
        'Step\\BlockingStep' => BlockingStepInterface::class,
        'Step\\ConditionalStep' => ConditionalStepInterface::class,
        'Step\\FileCreationStep' => FileCreationStepInterface::class,
        'Step\\FileCreationStepInterface' => FileCreationStepInterface::class,
        'Step\\OptionalStep' => OptionalStepInterface::class,
        'Step\\PostProcessStep' => PostProcessStepInterface::class,
        'Step\\ScriptHaltSignal' => ScriptHaltSignal::class,
        'Step\\Steps' => Runner::class,
    ];

    public static function register(): void
    {
        foreach (self::ALIASES as $suffix => $native) {
            self::registerAlias('WeCodeMore\\WpStarter\\' . $suffix, $native);
        }
    }

    /** @param list<string> $files */
    public static function reportUsage(array $files, Io $io): void
    {
        // PHP does not autoload parameter aliases on type checks. Register eagerly,
        // then diagnose declarations/references in files the setup process actually loaded.
        $files = array_values(array_filter($files, static fn (string $file): bool => !str_starts_with($file, dirname(__DIR__, 2) . '/')));
        $reported = [];
        foreach ((new PhpMigrationAnalyzer())->analyze($files) as $finding) {
            $symbol = $finding['symbol'];
            if (!str_starts_with($symbol, 'WeCodeMore\\WpStarter') || isset($reported[$symbol])) {
                continue;
            }
            $reported[$symbol] = true;
            $io->error('Deprecated WP Starter API: ' . $symbol . '; see migrate for replacement guidance.');
        }
    }

    /** @param class-string $native */
    private static function registerAlias(string $legacy, string $native): void
    {
        if (class_exists($legacy, false) || interface_exists($legacy, false)) {
            if (!is_a($legacy, $native, true)) {
                throw new RuntimeException('WP Starter API is already loaded: ' . $legacy . '. Remove the old runtime before running SymPress.');
            }

            return;
        }
        class_alias($native, $legacy);
    }

    public static function configure(ContainerBuilder $container): void
    {
        foreach (self::ALIASES as $suffix => $native) {
            if (!$container->has($native)) {
                continue;
            }
            $container->setAlias('WeCodeMore\\WpStarter\\' . $suffix, $native)->setPublic(true);
        }
    }

    /** @param class-string $class */
    public static function configureStep(Definition $definition, string $class): void
    {
        $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        $first = $parameters[0] ?? null;
        if ($first === null || $first->isVariadic()) {
            return;
        }
        $type = $first->getType();
        $legacy = $type === null || ($type instanceof ReflectionNamedType && $type->getName() === 'WeCodeMore\\WpStarter\\Util\\Locator');
        if (!$legacy) {
            return;
        }
        $definition->setArgument('$' . $first->getName(), new Reference(Services::class));
        $second = $parameters[1] ?? null;
        if ($second === null || $second->isVariadic()) {
            return;
        }
        $type = $second->getType();
        if ($type === null) {
            $definition->setArgument('$' . $second->getName(), new Reference(RunContext::class));
        } elseif ($type instanceof ReflectionNamedType && str_starts_with($type->getName(), 'Composer\\') && !$type->allowsNull()) {
            throw new RuntimeException('Migrate ' . $class . '::$' . $second->getName() . ' from ' . $type->getName() . ' to SymPress\\Runtime\\Application\\RunContext or runner Services.');
        }
    }
}
