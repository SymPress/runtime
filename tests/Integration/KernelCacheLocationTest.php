<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\SiteKernel;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\ArtifactWriter;
use SymPress\Runtime\Kernel\CacheLocation;
use SymPress\Runtime\Kernel\KernelPaths;
use SymPress\Runtime\Step\Builtin\KernelCacheStep;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Verify real Kernel process and public-root precedence.

#[Group('PAR-SYM-003')]
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class KernelCacheLocationTest extends TemporaryProject
{
    private ?string $external = null;

    protected function tearDown(): void
    {
        if ($this->external !== null) {
            (new Filesystem())->remove($this->external);
        }
        parent::tearDown();
    }

    private function fixture(string $extra = ''): KernelPaths
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"sympress/kernel","version":"1.1.4","install-path":"../sympress/kernel"}]}');
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"db-check":false,"require-wp":false}}}');
        $this->write('wordpress/wp-load.php', '<?php // read-only fixture');
        $this->write('wp-content/mu-plugins/sympress-runtime-kernel.php', '<?php // present boot entry');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nWP_HOME=https://fixture.test\nDB_NAME=fixture\nDB_USER=fixture\nDB_PASSWORD=private-fixture\nDB_HOST=localhost\n" . $extra);
        $reader = new EnvReader();
        $reader->loadFile($this->root . '/.env');

        return new KernelPaths($reader, new Paths($this->root));
    }

    /** @return array{checks: list<array{id: string, status: string, detail: string}>, exit: int} */
    private function diagnose(?string $webroot = null): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '-n', 'doctor', '--json'];
        if ($webroot !== null) {
            array_push($command, '--webroot', $webroot);
        }
        $process = new Process($command, $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false, 'APP_CACHE_DIR' => false, 'APP_BUILD_DIR' => false]);
        $process->run();
        self::assertJson($process->getOutput(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function clear(KernelPaths $kernel): void
    {
        $paths = new Paths($this->root);
        $boundary = new ProjectBoundary($paths);
        $step = new KernelCacheStep($kernel, $boundary, new ArtifactWriter($boundary), new Io(new ArrayInput([]), new BufferedOutput()));
        self::assertSame(StepInterface::SUCCESS, $step->run(new Config([], new Validator($paths)), $paths));
    }

    private function warm(KernelPaths $runtime): SiteKernel
    {
        ini_set('error_log', $this->root . '/kernel-warnings.log');
        $kernel = new SiteKernel($this->root, 'production');
        self::assertSame($kernel->getCacheDir(), $runtime->cache());
        self::assertSame($kernel->getBuildDir(), $runtime->build());
        $container = $kernel->createContainer();
        $registry = new BundleRegistry();
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        $container->builder()->register('fixture.warmed', \ArrayObject::class)->setPublic(true);
        $kernel->createRuntimeContainer($container, $registry, $files);
        self::assertInstanceOf(\ArrayObject::class, $container->get('fixture.warmed'));
        self::assertFileExists($kernel->getCacheDir() . '/meta.php');

        return $kernel;
    }

    public function testImplicitMigrationIsReadOnlyInDoctorAndClearsTheActuallyWarmedKernel(): void
    {
        $runtime = $this->fixture();
        $this->write('var/cache/production/kernel/meta.php', '<?php file_put_contents(__DIR__ . "/executed", "unsafe");');
        chmod($this->root . '/var/cache/production/kernel', 0770);
        $selected = CacheLocation::fallbackRoot($this->root) . '/production/kernel';
        self::assertSame($selected, $runtime->cache());
        self::assertSame($selected . '/discovery-packages.php', $runtime->discovery());
        $report = $this->diagnose();
        $checks = array_column($report['checks'], 'status', 'id');
        self::assertSame('unknown', $checks['kernel.cache']);
        self::assertSame('warning', $checks['kernel.cache-migration']);
        self::assertDirectoryDoesNotExist(CacheLocation::fallbackRoot($this->root));
        self::assertFileDoesNotExist($this->root . '/var/cache/production/kernel/executed');
        $kernel = $this->warm($runtime);
        self::assertSame(1, substr_count((string) file_get_contents($this->root . '/kernel-warnings.log'), 'SymPress migrated'));
        $this->write('var/cache/production/assets/keep.css', 'asset');
        $this->write('var/cache/staging/kernel/keep.php', 'other environment');
        self::assertSame('pass', array_column($this->diagnose()['checks'], 'status', 'id')['kernel.cache']);
        $this->clear($runtime);
        self::assertDirectoryDoesNotExist($kernel->getCacheDir());
        self::assertFileExists($this->root . '/var/cache/production/kernel/meta.php');
        self::assertFileExists($this->root . '/var/cache/production/assets/keep.css');
        self::assertFileExists($this->root . '/var/cache/staging/kernel/keep.php');
    }

    public function testExplicitUnsafeCacheFailsDoctorAndClearBeforeAnyDeletion(): void
    {
        $runtime = $this->fixture("APP_CACHE_DIR=custom/cache\nAPP_BUILD_DIR=custom/build\n");
        $this->write('custom/cache/production/kernel/meta.php', 'unsafe');
        $this->write('custom/build/production/kernel/keep.php', 'keep');
        chmod($this->root . '/custom/cache/production/kernel', 0770);
        $report = $this->diagnose();
        self::assertSame(1, $report['exit']);
        $checks = array_column($report['checks'], 'status', 'id');
        self::assertSame('fail', $checks['kernel.cache']);
        self::assertSame('fail', $checks['permissions.kernel-cache']);
        self::assertStringContainsString('PHP-FPM', json_encode($report, JSON_THROW_ON_ERROR));
        try {
            (new SiteKernel($this->root, 'production'))->getCacheDir();
            self::fail('Actual Kernel must reject the explicit unsafe cache.');
        } catch (RuntimeException) {
            self::assertFileExists($this->root . '/custom/cache/production/kernel/meta.php');
        }
        try {
            $this->clear($runtime);
            self::fail('Maintenance must reject the same unsafe cache.');
        } catch (RuntimeException) {
            self::assertFileExists($this->root . '/custom/build/production/kernel/keep.php');
        }
    }

    public function testPrivateAbsoluteCacheAndBuildOutsideTheReleaseAreWarmedDiagnosedAndCleared(): void
    {
        $this->external = $this->root . '-cache';
        mkdir($this->external, 0700);
        mkdir($this->external . '/build', 0700);
        $runtime = $this->fixture('APP_CACHE_DIR=' . $this->external . "\nAPP_BUILD_DIR=" . $this->external . "/build\n");
        $kernel = $this->warm($runtime);
        (new Filesystem())->dumpFile($kernel->getBuildDir() . '/fixture.php', '<?php // independently configured build artifact');
        (new Filesystem())->dumpFile($this->external . '/production/assets/keep.css', 'asset');
        (new Filesystem())->dumpFile($this->external . '/staging/kernel/keep.php', 'other environment');
        $report = $this->diagnose();
        $checks = array_column($report['checks'], 'status', 'id');
        self::assertSame('pass', $checks['kernel.cache']);
        self::assertSame('pass', $checks['kernel.build']);
        self::assertArrayNotHasKey('kernel.cache-migration', $checks);
        self::assertSame($kernel->getCacheDir() . '/discovery-packages.php', $runtime->discovery());
        $this->clear($runtime);
        self::assertDirectoryDoesNotExist($kernel->getCacheDir());
        self::assertDirectoryDoesNotExist($kernel->getBuildDir());
        self::assertFileExists($this->external . '/production/assets/keep.css');
        self::assertFileExists($this->external . '/staging/kernel/keep.php');
        $this->expectExceptionMessage('inside the project root');
        (new ProjectBoundary(new Paths($this->root)))->assertWritablePath($this->external . '/generic-artifact.php');
    }

    public function testPublicImplicitCacheSelectsTheSamePrivateLocationAsKernelWithoutDoctorCreatingIt(): void
    {
        $runtime = $this->fixture();
        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/var/cache';
        $selected = $runtime->cache();
        self::assertDirectoryDoesNotExist(CacheLocation::fallbackRoot($this->root));
        $checks = array_column($this->diagnose('var/cache')['checks'], 'status', 'id');
        self::assertSame('unknown', $checks['kernel.cache']);
        self::assertSame('warning', $checks['kernel.cache-migration']);
        self::assertDirectoryDoesNotExist(CacheLocation::fallbackRoot($this->root));
        ini_set('error_log', $this->root . '/kernel-warnings.log');
        self::assertSame((new SiteKernel($this->root, 'production'))->getCacheDir(), $selected);
        self::assertSame(CacheLocation::fallbackRoot($this->root) . '/production/kernel', $selected);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeExternalRoots(): iterable
    {
        yield 'shared root' => ['mode'];
        yield 'symlink root' => ['symlink'];
        yield 'parent traversal' => ['traversal'];
        yield 'symlink environment' => ['environment'];
        yield 'shared ancestor' => ['ancestor'];
    }

    #[DataProvider('unsafeExternalRoots')]
    public function testUnsafeExternalRootsDoNotDeleteAValidSeparateCache(string $case): void
    {
        $this->external = $this->root . '-cache';
        mkdir($this->external, 0700);
        $build = $this->external;
        if ($case === 'mode') {
            chmod($this->external, 0770);
        }
        if ($case === 'symlink') {
            symlink($this->external, $this->root . '/build-link');
            $build = $this->root . '/build-link';
        }
        if ($case === 'traversal') {
            $build = $this->root . '/../' . basename($this->external);
        }
        if ($case === 'environment') {
            symlink($this->root, $this->external . '/production');
        }
        if ($case === 'ancestor') {
            mkdir($this->external . '/private', 0700);
            $build = $this->external . '/private';
            chmod($this->external, 0770);
        }
        $runtime = $this->fixture("APP_CACHE_DIR=custom/cache\nAPP_BUILD_DIR=" . $build . "\n");
        $this->write('custom/cache/production/kernel/keep.php', 'keep');
        self::assertSame('fail', array_column($this->diagnose()['checks'], 'status', 'id')['kernel.build']);
        try {
            $this->clear($runtime);
            self::fail('Unsafe build roots must fail before deleting a valid cache.');
        } catch (RuntimeException) {
            self::assertFileExists($this->root . '/custom/cache/production/kernel/keep.php');
        }
    }

    public function testKernelServerPrecedenceWinsOverAConflictingProcessValue(): void
    {
        $runtime = $this->fixture("APP_CACHE_DIR=custom/cache\n");
        putenv('APP_CACHE_DIR=process/cache');
        $_SERVER['APP_CACHE_DIR'] = 'server/cache';
        self::assertSame((new SiteKernel($this->root, 'production'))->getCacheDir(), $runtime->cache());
        self::assertSame($this->root . '/server/cache/production/kernel', $runtime->cache());
    }

    /** @return iterable<string, array{string, string}> */
    public static function publicCacheRoots(): iterable
    {
        yield 'public cache' => ['public/cache', 'cache'];
        yield 'public build' => ['public/build', 'build'];
        yield 'content cache' => ['wp-content/cache', 'cache'];
        yield 'core cache' => ['wordpress/cache', 'cache'];
    }

    #[DataProvider('publicCacheRoots')]
    public function testStandaloneDoctorAndMaintenanceRejectKnownPublicRootsWithoutBootingWordPress(string $base, string $kind): void
    {
        $name = $kind === 'cache' ? 'APP_CACHE_DIR' : 'APP_BUILD_DIR';
        $runtime = $this->fixture($name . '=' . $base . "\n");
        $this->write($base . '/production/kernel/keep.php', 'private modes do not make a public location safe');
        chmod($this->root . '/' . $base . '/production/kernel', 0700);
        $report = $this->diagnose();
        self::assertSame(1, $report['exit']);
        $checks = array_column($report['checks'], 'status', 'id');
        self::assertSame('fail', $checks['kernel.' . $kind]);
        self::assertSame('fail', $checks['permissions.kernel-' . $kind]);
        try {
            $this->clear($runtime);
            self::fail('Known public roots must fail maintenance.');
        } catch (RuntimeException) {
            self::assertFileExists($this->root . '/' . $base . '/production/kernel/keep.php');
        }
        if ($kind !== 'cache') {
            return;
        }
        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/' . dirname($base);
        $this->expectExceptionMessage('outside publicly served directories');
        (new SiteKernel($this->root, 'production'))->getCacheDir();
    }

    public function testDoctorUsesAnExplicitCustomWebrootForItsCacheBoundary(): void
    {
        $this->fixture("APP_CACHE_DIR=served/cache\n");
        $this->write('served/cache/production/kernel/keep.php', 'keep');
        $report = $this->diagnose('served');
        self::assertSame(1, $report['exit']);
        self::assertSame('fail', array_column($report['checks'], 'status', 'id')['kernel.cache']);
        self::assertFileExists($this->root . '/served/cache/production/kernel/keep.php');
    }
}
