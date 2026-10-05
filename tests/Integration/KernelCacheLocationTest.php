<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Discovery\KernelPackageManifestCache;
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
        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"sympress/kernel","version":"1.1.5","install-path":"../sympress/kernel"}]}');
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
        self::assertFileExists($kernel->getCacheDir() . '/meta.json');
        self::assertFileDoesNotExist($kernel->getCacheDir() . '/meta.php');
        $manifest = new KernelPackageManifestCache($this->root, 'production', []);
        $manifest->write(['fixture/package']);
        self::assertSame(['fixture/package'], $manifest->read());
        self::assertFileExists($runtime->discovery());
        self::assertJson((string) file_get_contents($runtime->discovery()));

        return $kernel;
    }

    public function testImplicitMigrationIsReadOnlyInDoctorAndClearsTheActuallyWarmedKernel(): void
    {
        $runtime = $this->fixture();
        $this->write('var/cache/production/kernel/meta.php', '<?php file_put_contents(__DIR__ . "/executed", "unsafe");');
        $this->write('var/cache/production/kernel/discovery-packages.json', '{"unsafe":"old generation"}');
        chmod($this->root . '/var/cache/production/kernel', 0770);
        $selected = CacheLocation::fallbackRoot($this->root) . '/production/kernel';
        self::assertSame($selected, $runtime->cache());
        self::assertSame($selected . '/discovery-packages.json', $runtime->discovery());
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
        self::assertFileExists($this->root . '/var/cache/production/kernel/discovery-packages.json');
        self::assertFileDoesNotExist($this->root . '/var/cache/production/kernel/executed');
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
        self::assertSame($kernel->getCacheDir() . '/discovery-packages.json', $runtime->discovery());
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

    /** @return iterable<string, array{string, bool}> */
    public static function readOnlyMetadata(): iterable
    {
        yield 'JSON metadata retains the warmed location' => ['meta.json', false];
        yield 'legacy PHP metadata cannot select an unwritable location' => ['meta.php', true];
    }

    #[DataProvider('readOnlyMetadata')]
    public function testReadOnlyCacheSelectionMatchesKernelWithoutExecutingMetadata(string $file, bool $fallback): void
    {
        $this->fixture();
        $directory = $this->root . '/var/cache/production/kernel';
        $contents = $file === 'meta.json' ? '{}' : '<?php file_put_contents(__DIR__ . "/executed", "unsafe");';
        $this->write('var/cache/production/kernel/' . $file, $contents);
        $this->write('readonly-probe.php', <<<'PHP'
<?php
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && !posix_setuid(65534)) {
    throw new RuntimeException('Cannot drop the read-only probe identity.');
}
require __DIR__ . '/vendor/autoload.php';
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->loadFile(__DIR__ . '/.env');
$runtime = new SymPress\Runtime\Kernel\KernelPaths($reader, new SymPress\Runtime\Filesystem\Paths(__DIR__));
$selected = $runtime->cache();
$fallback = SymPress\Runtime\Kernel\CacheLocation::fallbackRoot(__DIR__);
$before = is_dir($fallback);
$doctor = new Symfony\Component\Process\Process([PHP_BINARY, $argv[1], '-n', 'doctor', '--json'], __DIR__);
$doctor->run();
$checks = array_column(json_decode($doctor->getOutput(), true, flags: JSON_THROW_ON_ERROR)['checks'], 'status', 'id');
$after = is_dir($fallback);
ini_set('error_log', __DIR__ . '/kernel-warnings.log');
$kernel = new SymPress\Kernel\Kernel\SiteKernel(__DIR__, 'production');
echo json_encode([is_writable(__DIR__ . '/var/cache/production/kernel'), $selected, $before, $checks['kernel.cache'], $after, $kernel->getCacheDir(), $fallback]);
PHP);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                self::assertTrue(chown($entry->getPathname(), 65534));
            }
            self::assertTrue(chown($this->root, 65534));
        }
        chmod($directory, 0500);
        try {
            $probe = new Process([PHP_BINARY, 'readonly-probe.php', dirname(__DIR__, 2) . '/bin/runtime'], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false, 'APP_CACHE_DIR' => false, 'APP_BUILD_DIR' => false]);
            $probe->mustRun();
            $result = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $expected = $fallback ? $result[6] . '/production/kernel' : $directory;
            self::assertSame([false, $expected, false, $fallback ? 'unknown' : 'pass', false, $expected, $result[6]], $result);
            self::assertSame($contents, file_get_contents($directory . '/' . $file));
            self::assertFileDoesNotExist($directory . '/executed');
        } finally {
            chmod($directory, 0700);
        }
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
