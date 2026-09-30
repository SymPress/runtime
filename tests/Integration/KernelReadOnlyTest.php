<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class KernelReadOnlyTest extends TemporaryProject
{
    #[Group('PAR-SYM-006')]
    public function testProductionDumpAndActualCompiledKernelRunWithoutWritableProjectFiles(): void
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_setuid')) {
            self::markTestSkipped('Read-only production permission verification requires POSIX process identities.');
        }
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"require-wp":false,"db-check":false}}}');
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nRUNTIME_READ_ONLY_VALUE=from-build-dump\nSYMPRESS_KERNEL_BUILD_ID=readonly-build\n");
        $dump = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '-n', 'dump-env', 'production'], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false]);
        $dump->mustRun();
        $this->write('.env', 'INVALID="must not be parsed');
        $this->write('config/services.php', <<<'PHP'
<?php
return static function (Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void {
    $container->services()->set('fixture.readonly', ArrayObject::class)->args([['value' => '%env(RUNTIME_READ_ONLY_VALUE)%']])->public();
};
PHP);
        $this->write('probe.php', <<<'PHP'
<?php
if ($argv[1] === 'readonly' && posix_geteuid() === 0 && !posix_setuid(65534)) { throw new RuntimeException('Cannot drop test identity.'); }
require __DIR__ . '/vendor/autoload.php';
$reader = SymPress\Runtime\Env\EnvReader::buildFromCacheDump(__DIR__ . '/.env.dump.php');
$reader->setupConstants();
$reader->setupKernelBuildId(__DIR__ . '/var/runtime');
$kernel = new SymPress\Kernel\Kernel\SiteKernel(__DIR__);
$container = $kernel->createContainer();
$registry = new SymPress\Kernel\Bundle\BundleRegistry();
$cached = $kernel->tryUseRuntimeContainer($container, $registry);
if (!$cached) {
    $files = $kernel->configureContainer($container->builder(), $container, $registry);
    $kernel->createRuntimeContainer($container, $registry, $files);
}
echo json_encode([$cached, $kernel->getEnvironment(), $container->get('fixture.readonly')['value'], SYMPRESS_KERNEL_BUILD_ID, is_writable(__DIR__)]);
PHP);
        $warm = new Process([PHP_BINARY, 'probe.php', 'warm'], $this->root);
        $warm->mustRun();
        self::assertSame([false, 'production', 'from-build-dump', 'readonly-build', true], json_decode($warm->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $hashes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) {
                chmod($file->getPathname(), 0555);
                continue;
            }
            $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            chmod($file->getPathname(), 0444);
        }
        chmod($this->root, 0555);
        $readOnly = new Process([PHP_BINARY, 'probe.php', 'readonly'], $this->root);
        $readOnly->run();
        self::assertSame(0, $readOnly->getExitCode(), $readOnly->getErrorOutput());
        self::assertSame([true, 'production', 'from-build-dump', 'readonly-build', false], json_decode($readOnly->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        foreach ($hashes as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $file));
        }
        // Restore directory permissions so an unprivileged test runner can remove its fixture.
        chmod($this->root, 0755);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if (!$file->isDir()) {
                continue;
            }
            chmod($file->getPathname(), 0755);
        }
    }
}
