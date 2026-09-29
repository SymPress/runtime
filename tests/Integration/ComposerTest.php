<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ComposerTest extends TemporaryProject
{
    private string $packageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packageRoot = dirname(__DIR__, 2);
    }

    private function fixture(bool $plugin = true, bool $customVendor = false): void
    {
        $lock = json_decode((string) file_get_contents($this->packageRoot . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $repositories = [['type' => 'path', 'url' => $this->packageRoot, 'options' => ['symlink' => true, 'versions' => ['sympress/runtime' => 'dev-main']]]];
        foreach ($lock['packages'] as $package) {
            $path = $this->packageRoot . '/vendor/' . $package['name'];
            if (!is_file($path . '/composer.json')) {
                continue;
            }

            $repositories[] = ['type' => 'path', 'url' => $path, 'options' => ['symlink' => true, 'versions' => [$package['name'] => $package['version']]]];
        }
        $repositories[] = ['packagist.org' => false];
        $manifest = [
            'name' => 'fixture/site',
            'require' => ['sympress/runtime' => 'dev-main'],
            'repositories' => $repositories,
            'minimum-stability' => 'dev',
            'autoload' => ['classmap' => ['host-probe.php']],
            'scripts' => ['post-install-cmd' => ['RuntimeHostProbe::record'], 'post-update-cmd' => ['RuntimeHostProbe::record']],
            'config' => ['allow-plugins' => ['sympress/runtime' => $plugin], 'vendor-dir' => $customVendor ? 'dependencies' : 'vendor', 'bin-dir' => $customVendor ? 'tools' : 'vendor/bin'],
            'extra' => ['sympress-runtime' => ['require-wp' => false, 'db-check' => false, 'custom-steps' => ['fixture' => 'RuntimeFixtureStep'], 'skip-steps' => Registry::DEFAULT_ORDER]],
        ];
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->write('host-probe.php', <<<'PHP'
<?php
declare(strict_types=1);
final class RuntimeHostProbe
{
    public static function record(Composer\Script\Event $event): void
    {
        $host = [
            'applicationLoaded' => class_exists(SymPress\Runtime\Console\Application::class, false),
            'containerLoaded' => class_exists(Symfony\Component\DependencyInjection\ContainerBuilder::class, false),
            'console' => (new ReflectionClass(Symfony\Component\Console\Application::class))->getFileName(),
        ];
        file_put_contents('host.json', json_encode($host, JSON_THROW_ON_ERROR));
        file_put_contents('order.log', "root-script\n", FILE_APPEND);
    }
}
PHP);
        $this->write('sympress-runtime-autoload.php', <<<'PHP'
<?php
declare(strict_types=1);
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\StepInterface;
final class RuntimeFixtureStep implements StepInterface
{
    public function __construct(private readonly Services $services) {}
    public function name(): string { return 'fixture'; }
    public function success(): string { return 'Fixture generated.'; }
    public function error(): string { return 'Fixture failed.'; }
    public function allowed(Config $config, Paths $paths): bool { return true; }
    public function run(Config $config, Paths $paths): int
    {
        $this->services->filesystem()->save('deterministic generated output', $paths->root('managed.txt'));
        file_put_contents($paths->root('order.log'), "runtime\n", FILE_APPEND);
        $data = [
            'mode' => $this->services->runContext()->mode,
            'dev' => $this->services->runContext()->dev,
            'interactive' => $this->services->runContext()->interactive,
            'decorated' => $this->services->runContext()->decorated,
            'verbosity' => $this->services->runContext()->verbosity,
            'stdinTty' => stream_isatty(STDIN),
            'stdoutTty' => stream_isatty(STDOUT),
            'install' => $config['is-composer-install']->unwrap(),
            'update' => $config['is-composer-update']->unwrap(),
            'selected' => $config['is-runtime-selected-command']->unwrap(),
            'console' => (new ReflectionClass(Symfony\Component\Console\Application::class))->getFileName(),
        ];
        $this->services->filesystem()->save(json_encode($data, JSON_THROW_ON_ERROR), $paths->root('context.json'));
        return self::SUCCESS;
    }
}
PHP);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|false> $environment
     */
    private function composer(array $arguments, array $environment = []): Process
    {
        $binary = getenv('RUNTIME_TEST_COMPOSER') ?: '/usr/local/bin/composer';
        self::assertFileExists($binary, 'Set RUNTIME_TEST_COMPOSER to a real Composer executable.');
        $process = new Process([PHP_BINARY, $binary, ...$arguments, '--no-interaction'], $this->root, array_replace(['COMPOSER_ALLOW_SUPERUSER' => '1'], $environment));
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return json_decode((string) file_get_contents($this->root . '/context.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Group('PAR-CLI-001')]
    #[Group('PAR-CLI-002')]
    #[Group('PAR-CLI-013')]
    #[Group('PAR-CLI-018')]
    public function testFirstInstallRepeatInstallAndComposerCommandInIsolatedChild(): void
    {
        $this->fixture(customVendor: true);
        $first = $this->composer(['install', '--no-dev']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput() . $first->getErrorOutput());
        self::assertFileExists($this->root . '/managed.txt');
        self::assertSame('update', $this->context()['mode'], 'Composer without a lock emits post-update-cmd.');
        self::assertFalse($this->context()['dev']);
        self::assertStringContainsString('symfony/console', $this->context()['console']);
        $host = json_decode((string) file_get_contents($this->root . '/host.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($host['applicationLoaded']);
        self::assertFalse($host['containerLoaded']);
        self::assertStringStartsWith('phar://', $host['console']);
        self::assertSame("runtime\nroot-script\n", file_get_contents($this->root . '/order.log'));
        $again = $this->composer(['install', '--no-dev']);
        self::assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        self::assertTrue($this->context()['install']);
        $selected = $this->composer(['sympress-runtime', 'fixture', '--skip-custom']);
        self::assertSame(0, $selected->getExitCode(), $selected->getErrorOutput());
        self::assertTrue($this->context()['selected']);
        self::assertSame('command', $this->context()['mode']);
        $validate = $this->composer(['sympress-runtime:validate']);
        self::assertSame(0, $validate->getExitCode(), $validate->getErrorOutput());
        self::assertStringContainsString('configuration is valid', $validate->getOutput());
    }

    #[Group('PAR-CLI-014')]
    public function testNoPluginsInstallAndStandaloneProduceTheSameFile(): void
    {
        $this->fixture();
        $install = $this->composer(['install', '--no-plugins']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/managed.txt');
        $standalone = new Process([PHP_BINARY, $this->root . '/vendor/bin/sympress-runtime', '-n'], $this->root);
        $standalone->run();
        self::assertSame(0, $standalone->getExitCode(), $standalone->getErrorOutput());
        $standaloneFile = file_get_contents($this->root . '/managed.txt');
        self::assertSame('standalone', $this->context()['mode']);
        $plugin = $this->composer(['install']);
        self::assertSame(0, $plugin->getExitCode(), $plugin->getErrorOutput());
        self::assertSame($standaloneFile, file_get_contents($this->root . '/managed.txt'));
    }

    public function testConsoleFlagsReachServicesInComposerAndStandalone(): void
    {
        $this->fixture();
        $install = $this->composer(['install', '--no-plugins']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        $quiet = $this->composer(['sympress-runtime', 'fixture', '--quiet']);
        self::assertSame(0, $quiet->getExitCode(), $quiet->getErrorOutput());
        self::assertSame('', $quiet->getOutput());
        self::assertFalse($this->context()['interactive']);
        self::assertSame(16, $this->context()['verbosity']);
        $standalone = new Process([PHP_BINARY, $this->root . '/vendor/bin/sympress-runtime', 'fixture', '-n', '-vvv', '--ansi'], $this->root);
        $standalone->run();
        self::assertSame(0, $standalone->getExitCode(), $standalone->getErrorOutput());
        self::assertSame(256, $this->context()['verbosity']);
        self::assertFalse($this->context()['interactive']);
        self::assertTrue($this->context()['decorated']);
    }

    public function testEnvironmentServiceIsSharedAndOnlyLoadsWhenUsed(): void
    {
        $this->fixture();
        $this->write('.env', 'malformed synthetic-secret');
        $install = $this->composer(['install']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        $source = (string) file_get_contents($this->root . '/sympress-runtime-autoload.php');
        $this->write('sympress-runtime-autoload.php', str_replace('return self::SUCCESS;', '$this->services->env()->read("RTV_LAZY"); return self::SUCCESS;', $source));
        $invalid = $this->composer(['sympress-runtime', 'fixture']);
        self::assertNotSame(0, $invalid->getExitCode());
        self::assertStringContainsString('Cannot parse environment file', $invalid->getErrorOutput());
        self::assertStringNotContainsString('synthetic-secret', $invalid->getErrorOutput());
        $this->write('.env', "RTV_LAZY=loaded\n");
        $valid = $this->composer(['sympress-runtime', 'fixture']);
        self::assertSame(0, $valid->getExitCode(), $valid->getErrorOutput());
    }

    public function testCustomComposerManifestRetainsProjectRootInBothEntrypoints(): void
    {
        $this->fixture(customVendor: true);
        $manifest = (string) file_get_contents($this->root . '/composer.json');
        $this->write('config/dependencies.json', $manifest);
        unlink($this->root . '/composer.json');
        $install = $this->composer(['install'], ['COMPOSER' => 'config/dependencies.json']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        self::assertFileExists($this->root . '/managed.txt');
        self::assertFileDoesNotExist($this->root . '/config/managed.txt');
        $standalone = new Process([PHP_BINARY, $this->root . '/tools/sympress-runtime', 'fixture', '-n'], $this->root, ['COMPOSER' => 'config/dependencies.json']);
        $standalone->run();
        self::assertSame(0, $standalone->getExitCode(), $standalone->getErrorOutput());
        self::assertSame('standalone', $this->context()['mode']);
    }

    public function testInstalledPackageContributesAutowiredStepAndMetadataIsValidated(): void
    {
        $this->fixture();
        $manifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['require']['fixture/extension'] = '1.0.0';
        array_unshift($manifest['repositories'], ['type' => 'path', 'url' => './extension']);
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $extension = ['name' => 'fixture/extension', 'version' => '1.0.0', 'type' => 'sympress-runtime-extension', 'autoload' => ['classmap' => ['step.php']], 'extra' => ['sympress-runtime' => ['steps' => ['contributed' => 'ContributedStep']]]];
        $this->write('extension/composer.json', json_encode($extension, JSON_THROW_ON_ERROR));
        $source = (string) file_get_contents($this->root . '/sympress-runtime-autoload.php');
        $this->write('extension/step.php', str_replace(['RuntimeFixtureStep', "return 'fixture';"], ['ContributedStep', "return 'contributed';"], $source));
        $install = $this->composer(['install', '--no-plugins']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        $run = $this->composer(['sympress-runtime', 'contributed']);
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame('deterministic generated output', file_get_contents($this->root . '/managed.txt'));
        $installed = json_decode((string) file_get_contents($this->root . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($installed['packages'] as &$package) {
            if ($package['name'] !== 'fixture/extension') {
                continue;
            }

            $package['extra']['sympress-runtime']['steps'] = 'synthetic-secret';
        }
        unset($package);
        $this->write('vendor/composer/installed.json', json_encode($installed, JSON_THROW_ON_ERROR));
        $invalid = $this->composer(['sympress-runtime:validate']);
        self::assertNotSame(0, $invalid->getExitCode());
        self::assertStringContainsString('fixture/extension', $invalid->getErrorOutput());
        self::assertStringNotContainsString('synthetic-secret', $invalid->getErrorOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function extensionTypes(): iterable
    {
        yield 'native' => ['sympress-runtime-extension'];
        yield 'legacy' => ['wpstarter-extension'];
    }

    #[DataProvider('extensionTypes')]
    public function testExtensionRootDoesNotAutorunSetup(string $type): void
    {
        $this->fixture();
        $manifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['type'] = $type;
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $install = $this->composer(['install']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/managed.txt');
        self::assertSame("root-script\n", file_get_contents($this->root . '/order.log'));
        $run = $this->composer(['sympress-runtime', 'fixture']);
        self::assertNotSame(0, $run->getExitCode());
        self::assertStringContainsString('extension roots', $run->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/managed.txt');
    }

    public function testComposerChildInheritsPseudoTerminalStreams(): void
    {
        if (!Process::isPtySupported()) {
            self::markTestSkipped('This platform has no PTY support.');
        }
        $this->fixture();
        $install = $this->composer(['install', '--no-plugins']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        $binary = getenv('RUNTIME_TEST_COMPOSER') ?: '/usr/local/bin/composer';
        $process = new Process([PHP_BINARY, $binary, 'sympress-runtime', 'fixture', '--ansi'], $this->root, ['COMPOSER_ALLOW_SUPERUSER' => '1', 'COMPOSER_NO_INTERACTION' => false]);
        $process->setPty(true);
        $process->setTimeout(30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertTrue($this->context()['stdinTty']);
        self::assertTrue($this->context()['stdoutTty']);
        self::assertTrue($this->context()['decorated']);
        self::assertTrue($this->context()['interactive']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function forwardedSignals(): iterable
    {
        yield 'SIGINT' => [2, 130];
        yield 'SIGTERM' => [15, 143];
    }

    #[DataProvider('forwardedSignals')]
    public function testComposerForwardsSignalToChild(int $signal, int $exit): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('Signal forwarding requires pcntl.');
        }
        $this->fixture();
        $install = $this->composer(['install', '--no-plugins']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        $source = (string) file_get_contents($this->root . '/sympress-runtime-autoload.php');
        $this->write('sympress-runtime-autoload.php', str_replace('return self::SUCCESS;', <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($paths): void {
    file_put_contents($paths->root('signal-received'), 'SIGTERM');
    exit(143);
});
file_put_contents($paths->root('child-ready'), (string) getmypid());
while (true) { usleep(10000); }
PHP
        , $source));
        $source = (string) file_get_contents($this->root . '/sympress-runtime-autoload.php');
        $this->write('sympress-runtime-autoload.php', str_replace(['SIGTERM', '143'], [(string) $signal, (string) $exit], $source));
        $binary = getenv('RUNTIME_TEST_COMPOSER') ?: '/usr/local/bin/composer';
        $process = new Process([PHP_BINARY, $binary, 'sympress-runtime', 'fixture', '-n'], $this->root, ['COMPOSER_ALLOW_SUPERUSER' => '1']);
        $process->setTimeout(10);
        $process->start();
        try {
            for ($attempt = 0; $attempt < 500 && !is_file($this->root . '/child-ready'); ++$attempt) {
                usleep(10000);
            }
            self::assertFileExists($this->root . '/child-ready');
            $process->signal($signal);
            $process->wait();
            self::assertSame($exit, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame((string) $signal, file_get_contents($this->root . '/signal-received'));
        } finally {
            $process->stop(1);
            if (is_file($this->root . '/child-ready') && !is_file($this->root . '/signal-received') && function_exists('posix_kill')) {
                posix_kill((int) file_get_contents($this->root . '/child-ready'), SIGKILL);
            }
        }
    }

    #[Group('PAR-CLI-008')]
    #[Group('PAR-CLI-015')]
    public function testListingDoesNotExecuteAndInvalidFlagsFail(): void
    {
        $this->fixture();
        $install = $this->composer(['install', '--no-plugins']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        $binary = $this->root . '/vendor/bin/sympress-runtime';
        $list = new Process([PHP_BINARY, $binary, '--list-steps', 'fixture'], $this->root);
        $list->run();
        self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
        self::assertSame("fixture\n", $list->getOutput());
        self::assertFileDoesNotExist($this->root . '/managed.txt');
        $invalid = new Process([PHP_BINARY, $binary, '--skip'], $this->root);
        $invalid->run();
        self::assertNotSame(0, $invalid->getExitCode());
        self::assertStringContainsString('--skip requires', $invalid->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/managed.txt');
    }

    #[Group('PAR-CLI-015')]
    public function testStepFailurePropagatesThroughComposerAndStopsRootScripts(): void
    {
        $this->fixture();
        $source = (string) file_get_contents($this->root . '/sympress-runtime-autoload.php');
        $this->write('sympress-runtime-autoload.php', str_replace('return self::SUCCESS;', 'return self::ERROR;', $source));
        $process = $this->composer(['install']);
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Fixture failed', $process->getErrorOutput());
        self::assertSame("runtime\n", file_get_contents($this->root . '/order.log'));
        self::assertFileDoesNotExist($this->root . '/host.json');
    }

    #[Group('PAR-SYM-002')]
    public function testValidateDoesNotLoadRunOnlyCodeAndInvalidConfigurationPreventsWrites(): void
    {
        $this->fixture();
        $install = $this->composer(['install', '--no-plugins']);
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());
        $this->write('sympress-runtime-autoload.php', '<?php throw new RuntimeException("Run-only file executed");');
        $binary = $this->root . '/vendor/bin/sympress-runtime';
        $validate = new Process([PHP_BINARY, $binary, 'validate'], $this->root);
        $validate->run();
        self::assertSame(0, $validate->getExitCode(), $validate->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/managed.txt');
        $this->write('sympress-runtime.json', '{"cache-env":"synthetic-secret-invalid-value"}');
        $invalid = new Process([PHP_BINARY, $binary], $this->root);
        $invalid->run();
        self::assertNotSame(0, $invalid->getExitCode());
        self::assertStringContainsString('cache-env', $invalid->getErrorOutput());
        self::assertStringNotContainsString('synthetic-secret-invalid-value', $invalid->getErrorOutput());
        self::assertStringNotContainsString('Run-only file executed', $invalid->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/managed.txt');
    }
}
