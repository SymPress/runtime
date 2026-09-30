<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Platform;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Composer\ContextFile;
use SymPress\Runtime\Env\SecureFileWriter;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

final class PlatformContractTest extends TemporaryProject
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = Path::canonicalize($this->root);
    }

    public function testUnavailableSymlinksFallBackToCopiesWithoutRemovingSources(): void
    {
        $native = new class extends SymfonyFilesystem {
            public function symlink(string $originDir, string $targetDir, bool $copyOnWindows = false): void
            {
                throw new RuntimeException('Fixture: symlink privilege unavailable.');
            }
        };
        $filesystem = new Filesystem($native);
        $this->write('source directory/nested/file.txt', 'source survives');
        self::assertTrue($filesystem->symlinkOrCopy($this->root . '/source directory', $this->root . '/copied directory'));
        self::assertSame('source survives', file_get_contents($this->root . '/copied directory/nested/file.txt'));
        self::assertFileExists($this->root . '/source directory/nested/file.txt');
        self::assertFalse(is_link($this->root . '/copied directory'));
        self::assertTrue($filesystem->symlinkOrCopy($this->root . '/source directory/nested/file.txt', $this->root . '/copied.txt'));
        self::assertFalse($filesystem->symlinkOrCopyOperation($this->root . '/source directory', $this->root . '/explicit-link', Filesystem::OP_SYMLINK));
        self::assertDirectoryDoesNotExist($this->root . '/explicit-link');
    }

    public function testNativeAutoPlacementPreservesSourceAndFileBytes(): void
    {
        $this->write('original/nested.txt', 'native auto placement');
        self::assertTrue((new Filesystem())->symlinkOrCopy($this->root . '/original', $this->root . '/placed'));
        self::assertSame('native auto placement', file_get_contents($this->root . '/placed/nested.txt'));
        self::assertSame('native auto placement', file_get_contents($this->root . '/original/nested.txt'));
    }

    public function testPathsUseNativeAbsoluteRootsAndPortableSeparators(): void
    {
        $paths = new Paths($this->root, 'custom deps', 'custom tools', 'public/wp', 'public/content');
        self::assertSame($this->root . '/custom deps/autoload.php', $paths->vendor('autoload.php'));
        self::assertSame($this->root . '/public/content/plugins', $paths->wpContent('plugins'));
        self::assertSame('../target/file.php', (new Filesystem())->findShortestPath($this->root . '/source/from.php', $this->root . '/target/file.php', preferRelative: true));
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        self::assertSame('D:/target/file.php', (new Filesystem())->findShortestPath('C:/source/file.php', 'D:/target/file.php'));
    }

    public function testCaseVariantsRespectTheActualFilesystemWithoutLosingData(): void
    {
        $this->write('CaseProbe.txt', 'case-safe content');
        $variant = $this->root . '/caseprobe.txt';
        $sameFile = is_file($variant);
        self::assertTrue((new Filesystem())->copyFile($this->root . '/CaseProbe.txt', $variant));
        self::assertSame('case-safe content', file_get_contents($this->root . '/CaseProbe.txt'));
        self::assertSame('case-safe content', file_get_contents($variant));
        self::assertCount($sameFile ? 1 : 2, glob($this->root . '/*.txt') ?: []);
        if (!$sameFile) {
            return;
        }
        self::assertTrue((new Filesystem())->moveFile($this->root . '/CaseProbe.txt', $variant));
        self::assertSame('case-safe content', file_get_contents($variant));
        self::assertSame('case-safe content', file_get_contents($this->root . '/CaseProbe.txt'));
    }

    public function testDirectoryCaseAliasesCannotDeleteTheirOwnTree(): void
    {
        $this->write('CaseDirectory/keep.txt', 'case-safe tree');
        $source = $this->root . '/CaseDirectory';
        $alias = $this->root . '/casedirectory';
        $sameDirectory = is_dir($alias);
        $files = new Filesystem();
        self::assertTrue($files->copyDir($source, $alias));
        self::assertSame('case-safe tree', file_get_contents($source . '/keep.txt'));
        self::assertSame('case-safe tree', file_get_contents($alias . '/keep.txt'));
        if (!$sameDirectory) {
            return;
        }
        self::assertTrue($files->moveDir($source, $alias));
        self::assertFalse($files->copyDir($source, $alias . '/nested'));
        self::assertFalse($files->moveDir($source, $alias . '/nested'));
        self::assertSame('case-safe tree', file_get_contents($source . '/keep.txt'));
    }

    public function testPrivateWritesRespectPlatformPermissionSemantics(): void
    {
        $file = $this->root . '/private.php';
        self::assertTrue(SecureFileWriter::write($file, 'first', 0600));
        self::assertTrue(SecureFileWriter::write($file, 'replacement', 0640));
        self::assertSame('replacement', file_get_contents($file));
        self::assertFileIsReadable($file);
        self::assertFileIsWritable($file);
        self::assertSame([], glob($this->root . '/.sympress-private-*'));
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $file);
            self::assertSame(0640, fileperms($file) & 0777);
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $bsdStat = new Process(['stat', '-f', '%Lp', $file]);
            $bsdStat->mustRun();
            self::assertSame('640', trim($bsdStat->getOutput()));
        }
        // Windows chmod does not establish a Unix group/other ACL contract.
        $this->expectException(\InvalidArgumentException::class);
        SecureFileWriter::write($file, 'unsafe', 0666);
    }

    public function testRelocatedPackageRunsBothComposerProxyFormats(): void
    {
        $manifest = [
            'name' => 'fixture/platform',
            'version' => '1.0.0',
            'require' => ['composer/installers' => '*', 'fixture/plugin' => '*'],
            'extra' => ['installer-paths' => ['public/content/plugins/{$name}/' => ['type:wordpress-plugin']]],
        ];
        $plugin = ['name' => 'fixture/plugin', 'version' => '1.0.0', 'type' => 'wordpress-plugin', 'install-path' => '../fixture/plugin', 'bin' => ['platform.php']];
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->write('vendor/fixture/plugin/platform.php', '<?php echo "platform binary works";');
        $this->write('vendor/composer/installers/marker', 'installer fixture');
        $packages = [['name' => 'composer/installers', 'version' => '2.0.0', 'type' => 'composer-plugin', 'install-path' => '../composer/installers'], $plugin];
        $this->write('vendor/composer/installed.json', json_encode(['packages' => $packages, 'dev' => true, 'dev-package-names' => []], JSON_THROW_ON_ERROR));
        $this->composerProcess(
            '$package = (new Composer\\Package\\Loader\\ArrayLoader())->load(' . var_export($plugin, true) . ');'
            . '(new Composer\\Installer\\BinaryInstaller(new Composer\\IO\\NullIO(), getcwd() . "/vendor/bin", "full", vendorDir: getcwd() . "/vendor"))->installBinaries($package, getcwd() . "/vendor/fixture/plugin");'
            . '(new SymPress\\Runtime\\Composer\\PackageLayout())->prepare(getcwd(), getcwd() . "/vendor", getcwd() . "/composer.json");',
        );
        self::assertFileExists($this->root . '/public/content/plugins/plugin/platform.php');
        $proxy = $this->root . '/vendor/bin/platform.php';
        self::assertFileExists($proxy . '.bat');
        self::assertStringContainsString('COMPOSER_RUNTIME_BIN_DIR', (string) file_get_contents($proxy . '.bat'));
        $process = new Process([PHP_BINARY, $proxy], $this->root);
        $process->mustRun();
        self::assertSame('platform binary works', $process->getOutput());
        if (PHP_OS_FAMILY === 'Windows') {
            $windows = new Process([$proxy . '.bat'], $this->root);
            $windows->mustRun();
            self::assertSame('platform binary works', trim($windows->getOutput()));

            return;
        }
        self::assertTrue(is_executable($proxy));
    }

    public function testPrivateContextRoundTripUsesSystemTemporaryDirectoryAndDeletesInput(): void
    {
        $context = new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin', 'update');
        $file = ContextFile::create($context);
        self::assertStringStartsWith('sympress-context-', basename($file));
        try {
            self::assertTrue(chmod($file, 0600));
            file_put_contents($file, json_encode($context->toArray(), JSON_THROW_ON_ERROR));
            self::assertSame($context->toArray(), ContextFile::consume($file)->toArray());
            self::assertFileDoesNotExist($file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testContextOutsideSystemTemporaryDirectoryIsPreserved(): void
    {
        $this->write('sympress-context-outside', '{}');
        try {
            ContextFile::consume($this->root . '/sympress-context-outside');
            self::fail('A nested project file must not be consumed as a context.');
        } catch (RuntimeException) {
            self::assertSame('{}', file_get_contents($this->root . '/sympress-context-outside'));
        }
    }

    public function testContextPermissionAndLinkGuardsPreserveRejectedInput(): void
    {
        $context = new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin');
        $file = ContextFile::create($context);
        try {
            file_put_contents($file, json_encode($context->toArray(), JSON_THROW_ON_ERROR));
            if (PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(chmod($file, 0644));
                clearstatcache(true, $file);
                try {
                    ContextFile::consume($file);
                    self::fail('Unix context mode must be exactly 0600.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('permissions', $error->getMessage());
                    self::assertFileExists($file);
                }
            }
            unlink($file);
            $this->write('context-target.json', '{}');
            if (!@symlink($this->root . '/context-target.json', $file)) {
                // An unprivileged Windows runner may disallow creating file symlinks.
                self::assertSame('Windows', PHP_OS_FAMILY);
                self::assertSame('{}', file_get_contents($this->root . '/context-target.json'));

                return;
            }
            try {
                ContextFile::consume($file);
                self::fail('A symlink must never be consumed as a private context.');
            } catch (RuntimeException) {
                self::assertTrue(is_link($file));
                self::assertSame('{}', file_get_contents($this->root . '/context-target.json'));
            }
        } finally {
            if (file_exists($file) || is_link($file)) {
                unlink($file);
            }
        }
    }

    private function composerProcess(string $code): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'require ' . var_export($autoload, true) . '; ' . $code], $this->root);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
    }
}
