<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class DoctorTest extends TemporaryProject
{
    private function fixture(bool $database = true): void
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('composer.json', json_encode(['extra' => ['sympress-runtime' => ['db-check' => $database, 'autoload' => 'setup-only.php']]], JSON_THROW_ON_ERROR));
        $this->write('setup-only.php', '<?php file_put_contents(__DIR__ . "/executed", "wrong");');
        $this->write('wordpress/wp-load.php', '<?php // inspected, never executed');
        $this->write('wp-content/keep.txt', 'existing content');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nWP_HOME=https://fixture.test\nDB_NAME=fixture\nDB_USER=fixture\nDB_PASSWORD=private-test-credential\nDB_HOST=localhost\nWPDB_ENV_VALID=true\nWPDB_EXISTS=true\nWP_INSTALLED=true\n");
    }

    private function diagnose(string $command = 'doctor'): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '-n', $command, '--json', '-vvv'], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false]);
        $process->run();

        return $process;
    }

    #[Group('PAR-SYM-001')]
    public function testJsonDiagnosticsAreRedactedReadOnlyAndDoNotExecuteSetupProviders(): void
    {
        $this->fixture();
        $before = hash_file('sha256', $this->root . '/.env');
        $process = $this->diagnose();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('production', $report['environment']);
        self::assertSame('pass', array_column($report['checks'], 'status', 'id')['database']);
        self::assertSame('not-applicable', array_column($report['checks'], 'status', 'id')['kernel.cache']);
        self::assertStringNotContainsString('private-test-credential', $process->getOutput() . $process->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/executed');
        self::assertDirectoryDoesNotExist($this->root . '/var');
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
        self::assertSame($before, hash_file('sha256', $this->root . '/.env'));
        self::assertSame(0, $this->diagnose('check')->getExitCode());
    }

    #[Group('PAR-SYM-001')]
    public function testDisabledDatabaseIsUnknownAndMissingPathsAreFailures(): void
    {
        $this->fixture(false);
        $unknown = $this->diagnose();
        self::assertSame(2, $unknown->getExitCode());
        $report = json_decode($unknown->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('unknown', array_column($report['checks'], 'status', 'id')['database']);
        unlink($this->root . '/wordpress/wp-load.php');
        $failed = $this->diagnose();
        self::assertSame(1, $failed->getExitCode());
        $report = json_decode($failed->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('fail', array_column($report['checks'], 'status', 'id')['paths.wordpress']);
    }

    #[Group('PAR-SYM-001')]
    public function testMalformedEnvironmentDoesNotRevealItsContents(): void
    {
        $this->fixture();
        $this->write('.env', 'DB_PASSWORD="private-test-credential');
        $failed = $this->diagnose();
        self::assertSame(1, $failed->getExitCode());
        self::assertStringNotContainsString('private-test-credential', $failed->getOutput() . $failed->getErrorOutput());
        $report = json_decode($failed->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('fail', array_column($report['checks'], 'status', 'id')['runtime-inspection']);
    }

    #[Group('PAR-SYM-001')]
    public function testInvalidRuntimeOptionsStillProduceStructuredRedactedDiagnostics(): void
    {
        $this->fixture();
        $this->write('sympress-runtime.json', '{"kernel-build-id":"private invalid value"}');
        $failed = $this->diagnose();
        self::assertSame(1, $failed->getExitCode());
        self::assertStringNotContainsString('private invalid value', $failed->getOutput() . $failed->getErrorOutput());
        $report = json_decode($failed->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('fail', array_column($report['checks'], 'status', 'id')['configuration']);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function kernelFreeLayouts(): iterable
    {
        yield 'ordinary layout' => [false, false];
        yield 'core in root' => [true, false];
        yield 'unused unsafe cache' => [false, true];
        yield 'core in root with unused unsafe cache' => [true, true];
    }

    #[DataProvider('kernelFreeLayouts')]
    #[Group('PAR-SYM-001')]
    public function testKernelFreeWordPressDoesNotInspectUnusedKernelDirectories(bool $coreInRoot, bool $unsafeCache): void
    {
        $this->fixture();
        if ($coreInRoot) {
            $this->write('composer.json', '{"extra":{"wordpress-install-dir":".","sympress-runtime":{}}}');
            $this->write('wp-load.php', '<?php // WordPress core in the project root');
        }
        if ($unsafeCache) {
            $this->write('unused-cache/production/kernel/meta.php', 'unused');
            chmod($this->root . '/unused-cache/production/kernel', 0770);
            $this->write('.env', (string) file_get_contents($this->root . '/.env') . "APP_CACHE_DIR=unused-cache\n");
        }
        $process = $this->diagnose();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $checks = array_column($report['checks'], 'status', 'id');
        foreach (['kernel.cache', 'kernel.build', 'permissions.kernel-cache', 'permissions.kernel-build'] as $id) {
            self::assertSame('not-applicable', $checks[$id]);
        }
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
        self::assertDirectoryDoesNotExist($this->root . '/var');
    }
}
