<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use Composer\IO\NullIO;
use Composer\Installer\BinaryInstaller;
use Composer\Package\Loader\ArrayLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SymPress\Runtime\Composer\PackageLayout;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class PackageLayoutTest extends TemporaryProject
{
    /** @return array<string, mixed> */
    private function fixture(string $vendor = 'vendor', bool $dev = true): array
    {
        $manifest = [
            'name' => 'fixture/site',
        'version' => '1.0.0',
            'config' => ['vendor-dir' => $vendor, 'classmap-authoritative' => true],
            'extra' => [
                'wordpress-install-dir' => 'public/wp',
        'wordpress-content-dir' => 'public/content',
                'installer-paths' => ['public/content/{$type}/{$name}/' => ['vendor:private']],
                'sympress-runtime' => ['require-wp' => false, 'db-check' => false],
            ],
            'autoload' => ['files' => ['root-autoload.php']],
            'scripts' => ['pre-autoload-dump' => '@php -r "exit(99);"'],
        ];
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->write('root-autoload.php', '<?php file_put_contents(__DIR__ . "/autoload-ran", "yes", FILE_APPEND);');
        $packages = [];
        foreach (['composer/installers' => 'composer-plugin', 'johnpbloch/wordpress-core-installer' => 'composer-plugin', 'private/core' => 'wordpress-core', 'private/plugin' => 'wordpress-plugin', 'private/mu' => 'wordpress-muplugin', 'private/theme' => 'wordpress-theme', 'private/dropin' => 'wordpress-dropin'] as $name => $type) {
            $package = ['name' => $name, 'version' => '1.0.0', 'type' => $type, 'install-path' => '../' . $name];
            $this->write($vendor . '/' . $name . '/marker', $name . ':1.0.0');
            if ($name === 'private/plugin') {
                $package['extra']['installer-name'] = 'renamed';
                $package['autoload'] = ['psr-4' => ['FixturePlugin\\' => 'src/'], 'files' => ['functions.php']];
                $this->write($vendor . '/' . $name . '/src/Probe.php', '<?php namespace FixturePlugin; final class Probe { public const string VALUE = "correct"; }');
                $this->write($vendor . '/' . $name . '/functions.php', '<?php function fixture_plugin(): string { return "correct"; }');
            }
            $packages[] = $package;
        }
        $data = ['packages' => $packages, 'dev' => $dev, 'dev-package-names' => $dev ? ['private/theme'] : []];
        $manifest['require'] = array_fill_keys(array_column($packages, 'name'), '*');
        if ($dev) {
            unset($manifest['require']['private/theme']);
            $manifest['require-dev'] = ['private/theme' => '*'];
        }
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->write($vendor . '/composer/installed.json', json_encode($data, JSON_THROW_ON_ERROR));
        foreach (['installed.php', 'InstalledVersions.php', 'autoload_namespaces.php', 'autoload_psr4.php', 'autoload_classmap.php', 'autoload_files.php', 'autoload_static.php', 'autoload_real.php'] as $file) {
            $this->write($vendor . '/composer/' . $file, '<?php return [];');
        }
        $this->write($vendor . '/autoload.php', '<?php throw new RuntimeException("obsolete loader");');

        return $data;
    }

    private function prepare(string $vendor = 'vendor'): void
    {
        $previous = getcwd();
        chdir($this->root);
        try {
            (new PackageLayout())->prepare($this->root, $this->root . '/' . $vendor, $this->root . '/composer.json');
        } finally {
            chdir((string) $previous);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function layouts(): iterable
    {
        yield 'dev' => ['vendor', true];
        yield 'custom vendor no dev' => ['dependencies', false];
    }

    /** @return iterable<string, array{bool}> */
    public static function regularInstallerLayouts(): iterable
    {
        yield 'project root core' => [true];
        yield 'content nested inside core' => [false];
    }

    /** @return array<string, mixed> */
    private function regularInstallerFixture(bool $rootCore, bool $installed): array
    {
        $data = $this->fixture();
        $manifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $core = $rootCore ? '.' : 'public/wp';
        $content = $rootCore ? 'wp-content' : 'public/wp/wp-content';
        $manifest['extra']['wordpress-install-dir'] = $core;
        $manifest['extra']['wordpress-content-dir'] = $content;
        $manifest['extra']['installer-paths'] = [$content . '/{$type}/{$name}/' => ['vendor:private']];
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        if ($installed) {
            foreach ($data['packages'] as &$package) {
                if (!str_starts_with($package['type'], 'wordpress-')) {
                    continue;
                }
                $path = $package['type'] === 'wordpress-core' ? $core : $content . '/' . $package['type'] . '/' . ($package['extra']['installer-name'] ?? basename($package['name']));
                $source = $this->root . '/vendor/' . $package['name'];
                $target = $this->root . '/' . $path;
                (new Filesystem())->mkdir(dirname($target));
                $package['install-path'] = '../../' . $path;
                if ($path === '.') {
                    rename($source . '/marker', $this->root . '/marker');
                    rmdir($source);
                    continue;
                }
                rename($source, $target);
            }
            unset($package);
            $this->write('vendor/composer/installed.json', json_encode($data, JSON_THROW_ON_ERROR));
        }

        return $data;
    }

    #[DataProvider('regularInstallerLayouts')]
    public function testCorrectRegularInstallerLayoutIsPreserved(bool $rootCore): void
    {
        $data = $this->regularInstallerFixture($rootCore, true);
        $metadata = file_get_contents($this->root . '/vendor/composer/installed.json');
        $autoload = file_get_contents($this->root . '/vendor/autoload.php');
        $this->prepare();
        $state = file_get_contents($this->root . '/var/runtime/package-layout.json');
        $this->prepare();
        self::assertSame($state, file_get_contents($this->root . '/var/runtime/package-layout.json'));
        self::assertSame($metadata, file_get_contents($this->root . '/vendor/composer/installed.json'));
        self::assertSame($autoload, file_get_contents($this->root . '/vendor/autoload.php'));
        foreach ($data['packages'] as $package) {
            self::assertSame($package['name'] . ':1.0.0', file_get_contents($this->root . '/vendor/composer/' . $package['install-path'] . '/marker'));
        }
        self::assertFileDoesNotExist($this->root . '/autoload-ran');
        self::assertDirectoryDoesNotExist($this->root . '/var/runtime/package-backups');
    }

    #[DataProvider('regularInstallerLayouts')]
    public function testUnsafeFreshRecoveryStillRequiresRegularInstallers(bool $rootCore): void
    {
        $data = $this->regularInstallerFixture($rootCore, false);
        $metadata = file_get_contents($this->root . '/vendor/composer/installed.json');
        try {
            $this->prepare();
            self::fail('Expected unsupported recovery layout.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($rootCore ? 'Unsafe package installation path' : 'must not overlap', $error->getMessage());
        }
        self::assertSame($metadata, file_get_contents($this->root . '/vendor/composer/installed.json'));
        foreach ($data['packages'] as $package) {
            self::assertSame($package['name'] . ':1.0.0', file_get_contents($this->root . '/vendor/' . $package['name'] . '/marker'));
        }
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.json');
        self::assertFileDoesNotExist($this->root . '/public/wp/marker');
        self::assertDirectoryDoesNotExist($this->root . '/var/runtime/package-backups');
    }

    #[DataProvider('regularInstallerLayouts')]
    public function testUnsupportedMetadataRecoveryPreservesRegularInstallerTrees(bool $rootCore): void
    {
        $data = $this->regularInstallerFixture($rootCore, true);
        $this->prepare();
        $state = file_get_contents($this->root . '/var/runtime/package-layout.json');
        $drifted = $data;
        foreach ($drifted['packages'] as &$package) {
            $package['install-path'] = '../' . $package['name'];
        }
        unset($package);
        $this->write('vendor/composer/installed.json', json_encode($drifted, JSON_THROW_ON_ERROR));
        $metadata = file_get_contents($this->root . '/vendor/composer/installed.json');
        try {
            $this->prepare();
            self::fail('Expected unsupported metadata recovery layout.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($rootCore ? 'Unsafe package installation path' : 'must not overlap', $error->getMessage());
        }
        self::assertSame($metadata, file_get_contents($this->root . '/vendor/composer/installed.json'));
        self::assertSame($state, file_get_contents($this->root . '/var/runtime/package-layout.json'));
        foreach ($data['packages'] as $package) {
            self::assertSame($package['name'] . ':1.0.0', file_get_contents($this->root . '/vendor/composer/' . $package['install-path'] . '/marker'));
        }
        self::assertDirectoryDoesNotExist($this->root . '/var/runtime/package-backups');
    }

    #[DataProvider('layouts')]
    public function testFreshOfflinePlacementRegeneratesMetadataAndAuthoritativeAutoload(string $vendor, bool $dev): void
    {
        $this->fixture($vendor, $dev);
        $this->prepare($vendor);
        self::assertFileDoesNotExist($this->root . '/autoload-ran');
        foreach (['wp' => 'core', 'content/wordpress-plugin/renamed' => 'plugin', 'content/wordpress-muplugin/mu' => 'mu', 'content/wordpress-theme/theme' => 'theme', 'content/wordpress-dropin/dropin' => 'dropin'] as $path => $name) {
            self::assertSame('private/' . $name . ':1.0.0', file_get_contents($this->root . '/public/' . $path . '/marker'));
        }
        $probe = new Process([PHP_BINARY, '-r', '$loader=require ' . var_export($this->root . '/' . $vendor . '/autoload.php', true) . '; echo json_encode([FixturePlugin\\Probe::VALUE, fixture_plugin(), $loader->isClassMapAuthoritative(), Composer\\InstalledVersions::getInstallPath("private/plugin")]);'], $this->root);
        $probe->mustRun();
        $result = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['correct', 'correct', true], array_slice($result, 0, 3));
        self::assertSame(realpath($this->root . '/public/content/wordpress-plugin/renamed'), realpath($result[3]));
        self::assertSame('yes', file_get_contents($this->root . '/autoload-ran'));
        $data = json_decode((string) file_get_contents($this->root . '/' . $vendor . '/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($dev, $data['dev']);
        self::assertSame($dev ? ['private/theme'] : [], $data['dev-package-names']);
        $before = hash_file('sha256', $this->root . '/' . $vendor . '/composer/autoload_static.php');
        $this->prepare($vendor);
        self::assertSame($before, hash_file('sha256', $this->root . '/' . $vendor . '/composer/autoload_static.php'));
    }

    public function testMetadataDriftIgnoresStaleVendorCopiesAndUpdatesPreserveOldTree(): void
    {
        $data = $this->fixture();
        $this->prepare();
        $this->write('vendor/private/plugin/marker', 'stale');
        $this->write('vendor/composer/installed.json', json_encode($data, JSON_THROW_ON_ERROR));
        $this->prepare();
        self::assertSame('private/plugin:1.0.0', file_get_contents($this->root . '/public/content/wordpress-plugin/renamed/marker'));
        self::assertSame('stale', file_get_contents($this->root . '/vendor/private/plugin/marker'));
        foreach ($data['packages'] as &$package) {
            if ($package['name'] !== 'private/plugin') {
                continue;
            }

            $package['version'] = '2.0.0';
            unset($package['autoload']);
        }
        unset($package);
        $this->write('vendor/private/plugin/marker', 'private/plugin:2.0.0');
        $this->write('vendor/composer/installed.json', json_encode($data, JSON_THROW_ON_ERROR));
        $this->prepare();
        self::assertSame('private/plugin:2.0.0', file_get_contents($this->root . '/public/content/wordpress-plugin/renamed/marker'));
        $backups = glob($this->root . '/var/runtime/package-backups/*/marker');
        self::assertCount(1, $backups);
        self::assertSame('private/plugin:1.0.0', file_get_contents($backups[0]));
    }

    public function testUnknownDestinationIsRejectedBeforeAnyPackageMoves(): void
    {
        $this->fixture();
        $this->write('public/content/wordpress-plugin/renamed/owner', 'keep');
        try {
            $this->prepare();
            self::fail('Expected destination conflict.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('not managed', $error->getMessage());
        }
        self::assertFileExists($this->root . '/vendor/private/core/marker');
        self::assertSame('keep', file_get_contents($this->root . '/public/content/wordpress-plugin/renamed/owner'));
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.json');
    }

    public function testChangedInstallerPathMovesManagedPackageWithoutLosingLocalFiles(): void
    {
        $this->fixture();
        $this->prepare();
        $this->write('public/content/wordpress-plugin/renamed/local-file', 'keep');
        $manifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['extra']['installer-paths'] = ['public/content/new/{$name}' => ['private/plugin']] + $manifest['extra']['installer-paths'];
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->prepare();
        self::assertSame('keep', file_get_contents($this->root . '/public/content/new/renamed/local-file'));
        self::assertFileDoesNotExist($this->root . '/public/content/wordpress-plugin/renamed/marker');
    }

    public function testRelativePathRepositoryLinkRemainsUsableWithoutChangingItsSource(): void
    {
        $this->fixture();
        rename($this->root . '/vendor/private/plugin', $this->root . '/local-plugin');
        symlink('../../local-plugin', $this->root . '/vendor/private/plugin');
        $this->prepare();
        $target = $this->root . '/public/content/wordpress-plugin/renamed';
        self::assertTrue(is_link($target));
        self::assertSame(realpath($this->root . '/local-plugin'), realpath($target));
        self::assertSame('private/plugin:1.0.0', file_get_contents($this->root . '/local-plugin/marker'));
    }

    public function testEscapingParentSymlinkIsRejectedWithoutTouchingExternalData(): void
    {
        $this->fixture();
        $outside = $this->root . '-external';
        mkdir($outside);
        symlink($outside, $this->root . '/public');
        try {
            $this->prepare();
            self::fail('Expected escaping parent rejection.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('outside', $error->getMessage());
            self::assertSame(['.', '..'], scandir($outside));
            self::assertFileExists($this->root . '/vendor/private/core/marker');
        } finally {
            rmdir($outside);
        }
    }

    public function testAutoloadFailureRollsBackMovedPackagesAndMetadata(): void
    {
        $this->fixture();
        $manifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['autoload']['classmap'] = ['missing-classmap.php'];
        $this->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $metadata = file_get_contents($this->root . '/vendor/composer/installed.json');
        try {
            $this->prepare();
            self::fail('Expected missing classmap failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('missing-classmap', $error->getMessage());
        }
        self::assertSame($metadata, file_get_contents($this->root . '/vendor/composer/installed.json'));
        self::assertSame('<?php throw new RuntimeException("obsolete loader");', file_get_contents($this->root . '/vendor/autoload.php'));
        self::assertFileExists($this->root . '/vendor/private/core/marker');
        self::assertFileDoesNotExist($this->root . '/public/wp/marker');
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.json');
    }

    public function testComposerBinaryProxyStillRunsAfterPackageRelocation(): void
    {
        $data = $this->fixture();
        foreach ($data['packages'] as &$package) {
            if ($package['name'] !== 'private/plugin') {
                continue;
            }

            $package['bin'] = ['command.php'];
            $this->write('vendor/private/plugin/command.php', '<?php echo "package command";');
            $loaded = (new ArrayLoader())->load($package);
            (new BinaryInstaller(new NullIO(), $this->root . '/vendor/bin', 'full', vendorDir: $this->root . '/vendor'))->installBinaries($loaded, $this->root . '/vendor/private/plugin');
        }
        unset($package);
        $this->write('vendor/composer/installed.json', json_encode($data, JSON_THROW_ON_ERROR));
        $this->prepare();
        $process = new Process([PHP_BINARY, $this->root . '/vendor/bin/command.php'], $this->root);
        $process->mustRun();
        self::assertSame('package command', $process->getOutput());
        self::assertStringContainsString('COMPOSER_RUNTIME_BIN_DIR', (string) file_get_contents($this->root . '/vendor/bin/command.php.bat'));
    }
}
