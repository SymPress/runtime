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
use SymPress\Kernel\Kernel\SiteKernel;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Kernel\KernelPaths;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Compare the installed Kernel's document-root boundary.

#[Group('PAR-SYM-003')]
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class KernelTemporaryCacheTest extends TemporaryProject
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        chmod($this->root, 0755);
        (new Filesystem())->remove($this->temporaryRoots);
        parent::tearDown();
    }

    private function fixture(): KernelPaths
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"sympress/kernel","version":"1.1.5","install-path":"../sympress/kernel"}]}');
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"db-check":false,"require-wp":false}}}');
        $this->write('wordpress/wp-load.php', '<?php // read-only fixture');
        $this->write('wp-content/mu-plugins/sympress-runtime-kernel.php', '<?php // present boot entry');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nWP_HOME=https://fixture.test\nDB_NAME=fixture\nDB_USER=fixture\nDB_PASSWORD=fixture\nDB_HOST=localhost\n");
        $reader = new EnvReader();
        $reader->loadFile($this->root . '/.env');

        return new KernelPaths($reader, new Paths($this->root), $this->root);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function temporaryFallbacks(): iterable
    {
        yield 'classic document root' => ['classic', false];
        yield 'cold read-only project' => ['readonly', false];
        yield 'classic document root without ext-posix' => ['classic', true];
    }

    #[DataProvider('temporaryFallbacks')]
    public function testTemporaryFallbackMatchesPublishedKernelAndIsReadOnlyUntilWarmup(string $case, bool $withoutPosix): void
    {
        $this->fixture();
        if ($case === 'classic') {
            $this->write('var/cache/production/kernel/discovery-packages.php', '<?php file_put_contents(__DIR__ . "/executed", "unsafe");');
            $this->write('var/cache/production/kernel/discovery-packages.json', '{"packages":["old/default"]}');
        }
        $this->write('temporary-probe.php', <<<'PHP'
<?php
if ($argv[1] === 'readonly' && function_exists('posix_geteuid') && posix_geteuid() === 0 && !posix_setuid(65534)) {
    throw new RuntimeException('Cannot drop the read-only probe identity.');
}
require __DIR__ . '/vendor/autoload.php';
$classic = $argv[1] === 'classic';
if ($classic) { $_SERVER['DOCUMENT_ROOT'] = __DIR__; }
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->loadFile(__DIR__ . '/.env');
$paths = new SymPress\Runtime\Filesystem\Paths(__DIR__);
$runtime = new SymPress\Runtime\Kernel\KernelPaths($reader, $paths, $classic ? __DIR__ : null);
$selected = $runtime->cache();
$root = dirname($selected, 2);
$before = is_dir($root);
$runtime->assertSafe($selected, 'cache');
$targets = $runtime->clearTargets();
$command = [PHP_BINARY];
if (!function_exists('posix_geteuid')) { array_push($command, '-d', 'disable_functions=posix_geteuid'); }
array_push($command, $argv[2], '-n', 'doctor', '--json');
if ($classic) { array_push($command, '--webroot', __DIR__); }
$doctor = new Symfony\Component\Process\Process($command, __DIR__);
$doctor->run();
$checks = array_column(json_decode($doctor->getOutput(), true, flags: JSON_THROW_ON_ERROR)['checks'], 'status', 'id');
$after = is_dir($root);
ini_set('error_log', $root . '/kernel-warnings.log');
$kernel = new SymPress\Kernel\Kernel\SiteKernel(__DIR__, 'production');
$actual = $kernel->getCacheDir();
$container = $kernel->createContainer();
$registry = new SymPress\Kernel\Bundle\BundleRegistry();
$files = $kernel->configureContainer($container->builder(), $container, $registry);
$container->builder()->register('fixture.warmed', ArrayObject::class)->setPublic(true);
$kernel->createRuntimeContainer($container, $registry, $files);
$manifest = new SymPress\Kernel\Discovery\KernelPackageManifestCache(__DIR__, 'production', []);
$manifest->write(['fixture/package']);
$metadata = is_file($selected . '/meta.json') && is_file($runtime->discovery()) && $manifest->read() === ['fixture/package'];
$filesystem = new Symfony\Component\Filesystem\Filesystem();
$filesystem->dumpFile($root . '/production/assets/keep.css', 'asset');
$filesystem->dumpFile($root . '/staging/kernel/keep.php', 'other environment');
$boundary = new SymPress\Runtime\Filesystem\ProjectBoundary($paths);
$step = new SymPress\Runtime\Step\Builtin\KernelCacheStep($runtime, $boundary, new SymPress\Runtime\Generation\ArtifactWriter($boundary), new SymPress\Runtime\Console\Io(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\BufferedOutput()));
$status = $step->run(new SymPress\Runtime\Config\Config([], new SymPress\Runtime\Config\Validator($paths)), $paths);
echo json_encode([$selected, $actual, $before, $after, $checks['kernel.cache'], $checks['permissions.kernel-cache'], $checks['kernel.cache-migration'], $runtime->usesFallback(), $targets, $metadata, $status, is_dir($selected), is_file($root . '/production/assets/keep.css'), is_file($root . '/staging/kernel/keep.php')]);
PHP);
        $owner = posix_geteuid();
        if ($case === 'readonly' && $owner === 0) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                self::assertTrue(chown($entry->getPathname(), 65534));
            }
            self::assertTrue(chown($this->root, 65534));
            $owner = 65534;
        }
        $temporary = (string) realpath(sys_get_temp_dir()) . '/sympress-kernel-' . $owner . '-' . substr(hash('sha256', (string) realpath($this->root)), 0, 24);
        $this->temporaryRoots[] = $temporary;
        if ($case === 'readonly') {
            chmod($this->root, 0555);
        }
        $command = [PHP_BINARY];
        if ($withoutPosix) {
            array_push($command, '-d', 'disable_functions=posix_geteuid');
        }
        array_push($command, 'temporary-probe.php', $case, dirname(__DIR__, 2) . '/bin/runtime');
        $probe = new Process($command, $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false, 'APP_CACHE_DIR' => false, 'APP_BUILD_DIR' => false]);
        $probe->mustRun();
        $selected = $temporary . '/production/kernel';
        self::assertSame([$selected, $selected, false, false, 'unknown', 'pass', 'warning', true, [$selected], true, StepInterface::SUCCESS, false, true, true], json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertFileDoesNotExist($this->root . '/var/cache/production/kernel/executed');
        if ($case !== 'classic') {
            return;
        }
        self::assertFileExists($this->root . '/var/cache/production/kernel/discovery-packages.php');
        self::assertFileExists($this->root . '/var/cache/production/kernel/discovery-packages.json');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeTemporaryRoots(): iterable
    {
        yield 'symlink root' => ['symlink'];
        yield 'shared writable root' => ['mode'];
    }

    #[DataProvider('unsafeTemporaryRoots')]
    public function testPrecreatedUnsafeTemporaryRootsAreNeverAdoptedOrCleared(string $case): void
    {
        $runtime = $this->fixture();
        $_SERVER['DOCUMENT_ROOT'] = $this->root;
        $temporary = (string) realpath(sys_get_temp_dir()) . '/sympress-kernel-' . posix_geteuid() . '-' . substr(hash('sha256', (string) realpath($this->root)), 0, 24);
        $this->temporaryRoots[] = $temporary;
        $this->write('target/keep.php', 'protected');
        if ($case === 'symlink') {
            symlink($this->root . '/target', $temporary);
        }
        if ($case === 'mode') {
            mkdir($temporary, 0770);
            chmod($temporary, 0770);
        }
        foreach ([$runtime->cache(...), (new SiteKernel($this->root, 'production'))->getCacheDir(...)] as $resolve) {
            try {
                $resolve();
                self::fail('An unsafe temporary root must fail without adoption.');
            } catch (RuntimeException) {
                self::assertSame('protected', file_get_contents($this->root . '/target/keep.php'));
            }
        }
        self::assertFileDoesNotExist($temporary . '/production/kernel/meta.json');
    }

    public function testTemporaryRootMustBelongToTheCallingIdentity(): void
    {
        $runtime = $this->fixture();
        $_SERVER['DOCUMENT_ROOT'] = $this->root;
        $temporary = (string) realpath(sys_get_temp_dir()) . '/sympress-kernel-' . posix_geteuid() . '-' . substr(hash('sha256', (string) realpath($this->root)), 0, 24);
        $this->temporaryRoots[] = $temporary;
        mkdir($temporary, 0700);
        (new Filesystem())->dumpFile($temporary . '/production/kernel/keep.php', 'protected owner fixture');
        if (posix_geteuid() === 0) {
            // A root test runner can also exercise a genuinely foreign owner.
            self::assertTrue(chown($temporary, 65534));
        }
        foreach ([$runtime->cache(...), (new SiteKernel($this->root, 'production'))->getCacheDir(...)] as $resolve) {
            if (posix_geteuid() !== 0) {
                self::assertSame($temporary . '/production/kernel', $resolve());
                continue;
            }
            try {
                $resolve();
                self::fail('A foreign-owned root must fail without adoption.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('another user', $error->getMessage());
            }
        }
        self::assertSame('protected owner fixture', file_get_contents($temporary . '/production/kernel/keep.php'));
    }

    public function testSharedTemporaryParentWithoutStickyBitCannotCreateACacheRoot(): void
    {
        $this->fixture();
        $parent = $this->root . '-unsafe-temp';
        $this->temporaryRoots[] = $parent;
        mkdir($parent, 0770);
        chmod($parent, 0770);
        $this->write('unsafe-temp-probe.php', <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$_SERVER['DOCUMENT_ROOT'] = __DIR__;
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->loadFile(__DIR__ . '/.env');
$runtime = new SymPress\Runtime\Kernel\KernelPaths($reader, new SymPress\Runtime\Filesystem\Paths(__DIR__));
$results = [];
foreach ([$runtime->cache(...), (new SymPress\Kernel\Kernel\SiteKernel(__DIR__, 'production'))->getCacheDir(...)] as $resolve) {
    try { $resolve(); $results[] = false; }
    catch (RuntimeException $error) { $results[] = str_contains($error->getMessage(), 'sticky bit'); }
}
echo json_encode($results);
PHP);
        $probe = new Process([PHP_BINARY, '-d', 'sys_temp_dir=' . $parent, 'unsafe-temp-probe.php'], $this->root, ['APP_CACHE_DIR' => false, 'APP_BUILD_DIR' => false]);
        $probe->mustRun();
        self::assertSame([true, true], json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertDirectoryExists($parent);
        self::assertSame([], glob($parent . '/*') ?: []);
    }
}
