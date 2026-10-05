<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class KernelBootTest extends TemporaryProject
{
    /** @param list<array<string, mixed>> $packages */
    private function fixture(array $packages = [], bool $enabled = true): void
    {
        $this->write('composer.json', json_encode(['extra' => ['sympress-runtime' => ['require-wp' => false, 'db-check' => false, 'kernel-boot' => $enabled]]], JSON_THROW_ON_ERROR));
        $this->write('vendor/autoload.php', '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; require_once __DIR__ . "/kernel-stub.php";');
        $this->write('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'sympress/kernel', 'version' => '1.0.0', 'type' => 'library', 'install-path' => '../sympress/kernel'], ['name' => 'sympress/runtime', 'version' => 'v1.2.4', 'type' => 'composer-plugin', 'install-path' => '../sympress/runtime'], ...$packages]], JSON_THROW_ON_ERROR));
        $this->write('vendor/kernel-stub.php', <<<'PHP'
<?php
namespace SymPress\Kernel;
final class App {
    public static int $boots = 0;
    private static ?object $kernel = null;
    public static function kernel(): ?object { return self::$kernel; }
    public static function bootKernel(object $kernel): void { self::$kernel = $kernel; self::$boots++; }
}
namespace SymPress\Kernel\Kernel;
final class SiteKernel { public function __construct(public string $root) {} }
PHP);
    }

    private function runStep(): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '-n', 'kernel-boot'], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false]);
        $process->run();

        return $process;
    }

    #[Group('PAR-SYM-004')]
    #[Group('PAR-NATIVE-007')]
    public function testOptInCreatesGuardedBootAndDisablingRemovesOnlyOwnedFile(): void
    {
        $this->fixture();
        $run = $this->runStep();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $file = $this->root . '/wp-content/mu-plugins/sympress-runtime-kernel.php';
        self::assertFileExists($file);
        self::assertStringContainsString(' * Version: 1.2.4', (string) file_get_contents($file));
        $boot = new Process([PHP_BINARY, '-r', 'define("ABSPATH", __DIR__); require "wp-content/mu-plugins/sympress-runtime-kernel.php"; require "wp-content/mu-plugins/sympress-runtime-kernel.php"; echo json_encode([SymPress\\Kernel\\App::$boots, realpath(SymPress\\Kernel\\App::kernel()->root)]);'], $this->root);
        $boot->mustRun();
        self::assertSame([1, $this->root], json_decode($boot->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $this->fixture(enabled: false);
        self::assertSame(0, $this->runStep()->getExitCode());
        self::assertFileDoesNotExist($file);
        $this->write('wp-content/mu-plugins/sympress-runtime-kernel.php', '<?php // user-owned file');
        self::assertSame(0, $this->runStep()->getExitCode());
        self::assertSame('<?php // user-owned file', file_get_contents($file));
    }

    /** @return iterable<string, array{bool}> */
    public static function installations(): iterable
    {
        yield 'copy' => [false];
        yield 'symlink' => [true];
    }

    #[DataProvider('installations')]
    #[Group('PAR-SYM-004')]
    public function testExistingPackageBootSuppressesGenerationForCopiedAndLinkedPackages(bool $linked): void
    {
        $package = ['name' => 'fixture/boot', 'version' => '1.0.0', 'type' => 'wordpress-muplugin', 'install-path' => '../../wp-content/mu-plugins/boot'];
        $this->fixture([$package]);
        $source = '<?php /** Plugin Name: Existing Boot */ use SymPress\\Kernel\\App; use SymPress\\Kernel\\Kernel\\SiteKernel; App::bootKernel(new SiteKernel(__DIR__));';
        if ($linked) {
            $this->write('packages/boot/app-starter.php', $source);
            $this->write('wp-content/mu-plugins/placeholder', '');
            self::assertTrue(symlink($this->root . '/packages/boot', $this->root . '/wp-content/mu-plugins/boot'));
        }
        if (!$linked) {
            $this->write('wp-content/mu-plugins/boot/app-starter.php', $source);
        }
        $run = $this->runStep();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('existing package owns boot', $run->getOutput());
        self::assertFileDoesNotExist($this->root . '/wp-content/mu-plugins/sympress-runtime-kernel.php');
        $boot = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; require "wp-content/mu-plugins/boot/app-starter.php"; echo SymPress\\Kernel\\App::$boots;'], $this->root);
        $boot->mustRun();
        self::assertSame('1', $boot->getOutput());
    }

    #[Group('PAR-SYM-004')]
    public function testMetadataOwnershipRetiresGeneratedBootAndMultipleOwnersFail(): void
    {
        $this->fixture();
        self::assertSame(0, $this->runStep()->getExitCode());
        $provider = ['name' => 'fixture/owner', 'version' => '1.0.0', 'type' => 'library', 'install-path' => '../fixture/owner', 'extra' => ['sympress-runtime' => ['boots-kernel' => true]]];
        $this->fixture([$provider]);
        self::assertSame(0, $this->runStep()->getExitCode());
        self::assertFileDoesNotExist($this->root . '/wp-content/mu-plugins/sympress-runtime-kernel.php');
        $this->fixture([$provider, array_replace($provider, ['name' => 'fixture/second'])]);
        $run = $this->runStep();
        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('Multiple kernel boot providers', $run->getErrorOutput());
    }
}
