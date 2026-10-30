<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use Composer\IO\NullIO;
use Composer\Package\Loader\ArrayLoader;
use RuntimeException;
use SymPress\Runtime\Composer\LayoutBinaries;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class LayoutBinariesTest extends TemporaryProject
{
    public function testPhysicalBinaryDirectoryAliasesPreserveExactOwnershipChecks(): void
    {
        $source = $this->root . '/vendor/fixture/plugin';
        $this->write('vendor/fixture/plugin/tool.php', '<?php echo "owned";');
        (new Filesystem())->mkdir($this->root . '/physical/deeper/bin');
        self::assertTrue(symlink($this->root . '/physical/deeper/bin', $this->root . '/bin'));
        $package = (new ArrayLoader())->load(['name' => 'fixture/plugin', 'version' => '1.0.0', 'bin' => ['tool.php']]);
        // Keep Composer's suppressed filesystem probes in the native PHP boundary.
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; $package = (new Composer\\Package\\Loader\\ArrayLoader())->load('
            . var_export(['name' => 'fixture/plugin', 'version' => '1.0.0', 'bin' => ['tool.php']], true)
            . '); (new Composer\\Installer\\BinaryInstaller(new Composer\\IO\\NullIO(), getcwd() . "/bin", "full", vendorDir: getcwd() . "/vendor"))->installBinaries($package, getcwd() . "/vendor/fixture/plugin");';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], $this->root);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput(), 'Composer must not emit unsuppressed PHP diagnostics.');
        $boundary = new ProjectBoundary(new Paths($this->root, $this->root . '/vendor'));
        $binaries = new LayoutBinaries(new NullIO(), $this->root . '/bin', 'full', vendorDir: $this->root . '/vendor');
        $binaries->plan($package, $source, [$source], $boundary);
        self::assertSame([$this->root . '/bin/tool.php', $this->root . '/bin/tool.php.bat'], $binaries->paths());
        $proxy = (string) file_get_contents($this->root . '/bin/tool.php');
        $binaries->write();
        self::assertSame($proxy, file_get_contents($this->root . '/bin/tool.php'));
        $this->write('bin/tool.php', $proxy . '// user changes');
        try {
            (new LayoutBinaries(new NullIO(), $this->root . '/bin', 'full', vendorDir: $this->root . '/vendor'))->plan($package, $source, [$source], $boundary);
            self::fail('A modified proxy must not be treated as owned.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('not an owned Composer proxy', $error->getMessage());
            self::assertSame($proxy . '// user changes', file_get_contents($this->root . '/bin/tool.php'));
        }
    }
}
