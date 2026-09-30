<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use SymPress\Runtime\Tests\Support\PublicApiInventory;
use SymPress\Runtime\Tests\Support\PublicApiUsage;

final class PublicApiTest extends TestCase
{
    public function testDocumentedInventoryExactlyMatchesPublicMembers(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/api.md');
        self::assertSame(1, preg_match('/<!-- api-inventory -->\n```text\n(.*?)\n```\n<!-- \/api-inventory -->/s', $document, $match));
        self::assertSame(PublicApiInventory::members(), explode("\n", $match[1]));
    }

    public function testEverySourceClassHasExactlyOneVisibilityAnnotation(): void
    {
        foreach (PublicApiInventory::classes() as $class) {
            $doc = $class->getDocComment() ?: '';
            self::assertSame(1, preg_match_all('/@(api|internal)\b/', $doc), $class->getName());
            if (!PublicApiUsage::exposed($class)) {
                continue;
            }
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!PublicApiUsage::isRuntime($method->getDeclaringClass()->getName())) {
                    continue;
                }
                self::assertSame(1, preg_match_all('/@(api|internal)\b/', $method->getDocComment() ?: ''), $class->getName() . '::' . $method->getName());
            }
        }
    }

    public function testPublicSignaturesNeverLeakUnclassifiedInternalTypes(): void
    {
        foreach (PublicApiInventory::classes() as $class) {
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!PublicApiUsage::publicMethod($method)) {
                    continue;
                }
                $types = [$method->getReturnType(), ...array_map(static fn (ReflectionParameter $parameter) => $parameter->getType(), $method->getParameters())];
                foreach ($types as $type) {
                    $this->assertExposedTypes($type, $class->getName(), $method->getName());
                }
            }
            if (!str_contains($class->getDocComment() ?: '', '@api')) {
                continue;
            }
            foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $this->assertExposedTypes($property->getType(), $class->getName(), '$' . $property->getName());
            }
        }
    }

    private function assertExposedTypes(?\ReflectionType $type, string $class, string $member): void
    {
        foreach (PublicApiUsage::types($type, $class) as $name) {
            if (!PublicApiUsage::isRuntime($name)) {
                continue;
            }

            self::assertTrue(PublicApiUsage::exposed(new ReflectionClass($name)), $class . '::' . $member . ' exposes ' . $name);
        }
    }

    public function testExtensionExamplesAndConsumerTestsUseOnlyPublicMembers(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [];
        foreach (['examples', 'tests/Extension'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $paths[] = $file->getPathname();
            }
        }
        // Existing executable extension fixtures are also consumer code. Probe stubs
        // for internal implementations remain in the regular contract test harness.
        foreach (['LifecycleCallbacks', 'ProbeStep', 'TailStep', 'StepTrace', 'PhpToolProbe'] as $fixture) {
            $paths[] = $root . '/tests/Fixtures/' . $fixture . '.php';
        }
        self::assertNotEmpty($paths);
        foreach ($paths as $path) {
            self::assertSame([], (new PublicApiUsage())->violations((string) file_get_contents($path)), $path);
        }
    }

    #[DataProvider('invalidConsumers')]
    public function testArchitectureRuleRejectsInternalAccess(string $source, string $expected): void
    {
        $violations = (new PublicApiUsage())->violations('<?php ' . $source);
        self::assertNotEmpty($violations);
        self::assertStringContainsString($expected, implode("\n", $violations));
    }

    public static function invalidConsumers(): iterable
    {
        yield 'case insensitive namespace' => ['function extension(\\sympress\\runtime\\Services $services) { $services->runContext(); }', 'Services::runContext'];
        yield 'internal type' => ['use SymPress\\Runtime\\Application\\RunContext; function extension(RunContext $context) {}', 'Internal symbol'];
        yield 'accessor alias' => ['function extension(\\SymPress\\Runtime\\Services $services) { $alias = $services; $alias->runContext(); }', 'Services::runContext'];
        yield 'service chain' => ['function extension(\\SymPress\\Runtime\\Services $services) { $services->env()->loadChain(); }', 'EnvReader::loadChain'];
        yield 'service constructor' => ['new \\SymPress\\Runtime\\Filesystem\\Filesystem();', 'Filesystem::__construct'];
        yield 'internal constant' => ['echo \\SymPress\\Runtime\\Env\\EnvReader::CACHE_DUMP_FILE;', 'Internal member'];
        yield 'dynamic method' => ['function extension(\\SymPress\\Runtime\\Services $services, string $method) { $services->$method(); }', '(dynamic)'];
        yield 'lifecycle chain' => ['function extension(\\SymPress\\Runtime\\Event\\PreRunEvent $event) { $event->services->packageFinder(); }', 'Services::packageFinder'];
        yield 'callable method alias' => ['function extension(\\SymPress\\Runtime\\Services $services) { $callback = [$services, \'runContext\']; $callback(); }', 'Services::runContext'];
        yield 'typed property' => ['class Extension { public function __construct(private \\SymPress\\Runtime\\Services $services) {} public function run() { $this->services->env()->loadChain(); } }', 'EnvReader::loadChain'];
    }

    public function testTypedAliasesAndLifecyclePublicAccessAreAccepted(): void
    {
        $source = <<<'SOURCE'
<?php
function extension(\SymPress\Runtime\Event\PreRunEvent $event): void {
    $services = $event->services;
    $env = $services->env();
    $env->read('WP_HOME');
    $services->config()['cache-env']->is(true);
    $event->subject->name();
    $event->stopPropagation();
    $event->isPropagationStopped();
}
SOURCE;
        self::assertSame([], (new PublicApiUsage())->violations($source));
    }
}
