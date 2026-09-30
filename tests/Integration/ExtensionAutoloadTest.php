<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ExtensionAutoloadTest extends TemporaryProject
{
    /** @param list<string> $arguments */
    private function execute(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/sympress-runtime', '-n', ...$arguments], $this->root, ['COMPOSER_VENDOR_DIR' => false, 'COMPOSER' => false]);
        $process->run();

        return $process;
    }

    private function fixture(bool $legacy): void
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $type = $legacy ? 'wpstarter-extension' : 'sympress-runtime-extension';
        $key = $legacy ? 'wpstarter-autoload' : 'sympress-runtime-autoload';
        $this->write('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'fixture/extension', 'version' => '1.0.0', 'type' => $type, 'install-path' => '../../packages/extension', 'extra' => [$key => ['psr-4' => ['FixtureExtension\\' => 'src'], 'files' => ['bootstrap.php', 'missing.php']], 'sympress-runtime' => ['steps' => ['extension' => 'FixtureExtension\\ExtensionStep']]]]]], JSON_THROW_ON_ERROR));
        $settings = ['require-wp' => false, 'db-check' => false, 'autoload' => 'runtime-autoload.php', 'scripts' => ['pre-extension' => [['FixtureExtension\\Hooks', 'before']], 'post-extension' => 'FixtureExtension\\Hooks::after']];
        $this->write('composer.json', json_encode(['extra' => ['sympress-runtime' => $settings]], JSON_THROW_ON_ERROR));
        $this->write('runtime-autoload.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/autoload-order', "root\n");
return static function (Symfony\Component\DependencyInjection\ContainerBuilder $container): void {
    $container->register(FixtureExtension\Hooks::class)->setAutowired(true)->setAutoconfigured(true);
};
PHP);
        $this->write('packages/extension/bootstrap.php', <<<'PHP'
<?php
file_put_contents(dirname(__DIR__, 2) . '/autoload-order', "extension\n", FILE_APPEND);
PHP);
        $this->write('packages/extension/src/ExtensionStep.php', <<<'PHP'
<?php
namespace FixtureExtension;
final class ExtensionStep implements \SymPress\Runtime\Step\StepInterface
{
    public function __construct(private \SymPress\Runtime\Services $services) {}
    public function name(): string { return 'extension'; }
    public function success(): string { return 'extension succeeded'; }
    public function error(): string { return 'extension failed'; }
    public function allowed(\SymPress\Runtime\Config\Config $config, \SymPress\Runtime\Filesystem\Paths $paths): bool { return true; }
    public function run(\SymPress\Runtime\Config\Config $config, \SymPress\Runtime\Filesystem\Paths $paths): int
    {
        $this->services->io()->write('extension body');
        return self::SUCCESS;
    }
}
PHP);
        $this->write('packages/extension/src/Hooks.php', <<<'PHP'
<?php
namespace FixtureExtension;
final class Hooks implements \Symfony\Component\EventDispatcher\EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [\SymPress\Runtime\Event\PreStepEvent::class => ['event', 10]]; }
    public function event(\SymPress\Runtime\Event\PreStepEvent $event): void { $event->services->io()->write('extension event'); }
    public static function before(int $result, mixed $step, \SymPress\Runtime\Services $services): void { $services->io()->write('extension pre:' . $result); }
    public static function after(int $result, mixed $step, \SymPress\Runtime\Services $services): void { $services->io()->write('extension post:' . $result); }
}
PHP);
    }

    /** @return iterable<string, array{bool}> */
    public static function profiles(): iterable
    {
        yield 'native' => [false];
        yield 'legacy' => [true];
    }

    #[DataProvider('profiles')]
    #[Group('PAR-EXT-005')]
    #[Group('PAR-EXT-006')]
    #[Group('PAR-EXT-004')]
    public function testExtensionAutoloadIsRunOnlyAndSupportsStepsScriptsAndEventSubscribers(bool $legacy): void
    {
        $this->fixture($legacy);
        $validated = $this->execute(['validate']);
        self::assertSame(0, $validated->getExitCode(), $validated->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/autoload-order');
        $listing = $this->execute(['--list-steps']);
        self::assertSame(0, $listing->getExitCode(), $listing->getErrorOutput());
        self::assertStringContainsString('extension', $listing->getOutput());
        self::assertStringNotContainsString('extension body', $listing->getOutput());
        self::assertSame("root\nextension\n", file_get_contents($this->root . '/autoload-order'));
        $run = $this->execute(['extension']);
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString("extension event\nextension pre:4\nextension body\nextension post:2", $run->getOutput());
        self::assertSame("root\nextension\n", file_get_contents($this->root . '/autoload-order'));
        $normal = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo json_encode([class_exists("FixtureExtension\\ExtensionStep"), class_exists("FixtureExtension\\Hooks")]);'], $this->root);
        $normal->mustRun();
        self::assertSame('[false,false]', $normal->getOutput());
        self::assertFileDoesNotExist($this->root . '/vendor/composer/autoload_psr4.php');
    }

    #[Group('PAR-EXT-005')]
    public function testNativeMetadataErrorsAreStaticAndDoNotExposeValuesOrExecuteFiles(): void
    {
        $this->fixture(false);
        $installed = json_decode((string) file_get_contents($this->root . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        $installed['packages'][0]['extra']['sympress-runtime-autoload']['files'] = ['bootstrap.php', ['synthetic-private-value']];
        $this->write('vendor/composer/installed.json', json_encode($installed, JSON_THROW_ON_ERROR));
        $run = $this->execute(['validate']);
        self::assertNotSame(0, $run->getExitCode());
        self::assertStringNotContainsString('synthetic-private-value', $run->getOutput() . $run->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/autoload-order');
    }
}
