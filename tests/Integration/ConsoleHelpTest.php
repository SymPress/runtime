<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ConsoleHelpTest extends TemporaryProject
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('composer.json', '{}');
    }

    private function command(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '-n', ...$arguments], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false]);
        $process->run();

        return $process;
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function inspectionCommands(): iterable
    {
        yield 'list' => [['list'], 'Available commands:'];
        yield 'raw list' => [['list', '--raw'], 'validate'];
        yield 'help' => [['help'], 'help'];
        yield 'setup help' => [['help', 'run'], '--list-steps'];
        yield 'doctor help' => [['help', 'doctor'], '--production'];
        yield 'dump help' => [['help', 'dump-env'], 'environment'];
        yield 'list help' => [['list', '--help'], '--format'];
        yield 'default help' => [['--help'], '--list-steps'];
        yield 'existing command help' => [['doctor', '--help'], '--production'];
        yield 'global flags before command' => [['--no-ansi', '-v', 'help', 'prune'], '--keep'];
        yield 'completion' => [['completion', 'bash'], 'bash'];
    }

    /** @param list<string> $arguments */
    #[DataProvider('inspectionCommands')]
    public function testInspectionWorksWithoutWordPressAndDoesNotPrepareOrRunTheProject(array $arguments, string $expected): void
    {
        // Help must work even when setup configuration and recovery need attention.
        $this->write('sympress-runtime.json', '{invalid configuration');
        $this->write('sympress-runtime-autoload.php', '<?php file_put_contents(__DIR__ . "/provider-ran", "bad");');
        $this->write('var/runtime/package-layout.pending.json', '{pending transaction');
        $process = $this->command(...$arguments);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString($expected, $process->getOutput());
        self::assertSame('{pending transaction', file_get_contents($this->root . '/var/runtime/package-layout.pending.json'));
        self::assertFileDoesNotExist($this->root . '/provider-ran');
        self::assertFileDoesNotExist($this->root . '/wp-config.php');
        self::assertFileDoesNotExist($this->root . '/index.php');
    }

    public function testJsonListContainsEveryRuntimeOperation(): void
    {
        $process = $this->command('list', '--format=json');
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $document = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($document['commands'], 'name');
        foreach (['run', 'help', 'list', ...Registry::RESERVED] as $name) {
            self::assertContains($name, $names);
        }
        self::assertDirectoryDoesNotExist($this->root . '/var');
    }

    public function testUnknownHelpTargetFailsWithoutRunningSetup(): void
    {
        $process = $this->command('help', 'not-a-command');
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('not-a-command', $process->getErrorOutput());
        self::assertStringNotContainsString('No valid selected steps', $process->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->root . '/var');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function setupSelections(): iterable
    {
        yield 'direct step' => [['index']];
        yield 'explicit run' => [['run', 'index']];
        yield 'escaped list step' => [['--', 'list']];
        yield 'escaped help step' => [['--', 'help']];
        yield 'escaped run step' => [['--', 'run']];
        yield 'help as later step' => [['index', 'help']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('setupSelections')]
    public function testSetupAndCustomStepNamesRemainAvailable(array $arguments): void
    {
        $steps = [];
        $provider = '<?php ';
        foreach (['list', 'help', 'run'] as $name) {
            $class = 'Fixture' . ucfirst($name) . 'Step';
            $steps[$name] = $class;
            $provider .= sprintf(<<<'PHP'
                final class %s implements \SymPress\Runtime\Step\StepInterface {
                    public function name(): string { return '%s'; }
                    public function success(): string { return 'done'; }
                    public function error(): string { return 'failed'; }
                    public function allowed(\SymPress\Runtime\Config\Config $config, \SymPress\Runtime\Filesystem\Paths $paths): bool { return true; }
                    public function run(\SymPress\Runtime\Config\Config $config, \SymPress\Runtime\Filesystem\Paths $paths): int {
                        file_put_contents($paths->root('index.php'), 'fixture');
                        return self::SUCCESS;
                    }
                }
                PHP, $class, $name);
        }
        $this->write('sympress-runtime-autoload.php', $provider);
        $this->write('composer.json', json_encode([
            'extra' => [
                'sympress-runtime' => [
                    'require-wp' => false,
                    'db-check' => false,
                    'command-steps' => $steps,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->write('wordpress/.keep', '');
        $process = $this->command(...$arguments);
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertFileExists($this->root . '/index.php');
    }
}
