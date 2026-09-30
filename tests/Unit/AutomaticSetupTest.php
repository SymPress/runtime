<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Unit;

use Composer\Composer;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Composer\AutomaticSetup;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class AutomaticSetupTest extends TemporaryProject
{
    /**
     * @param array<string, mixed> $extra
     * @param list<Package> $packages
     */
    private function composer(array $extra = [], array $packages = []): Composer
    {
        $root = new RootPackage('fixture/site', '1.0.0.0', '1.0.0');
        $root->setExtra($extra);
        $repositories = $this->createStub(RepositoryManager::class);
        $repositories->method('getLocalRepository')->willReturn(new InstalledArrayRepository($packages));
        $composer = new Composer();
        $composer->setPackage($root);
        $composer->setRepositoryManager($repositories);

        return $composer;
    }

    public function testUnconfiguredConsumerDefersButAnyCoreResumesValidation(): void
    {
        $guard = new AutomaticSetup();
        self::assertFalse($guard->required($this->composer(), $this->root));
        $this->write('.env', 'not-runtime-configuration');
        $library = new Package('fixture/library', '1.0.0.0', '1.0.0');
        self::assertFalse($guard->required($this->composer(['unrelated' => true], [$library]), $this->root));
        $first = new Package('fixture/core', '1.0.0.0', '1.0.0');
        $first->setType('wordpress-core');
        $second = new Package('fixture/other-core', '1.0.0.0', '1.0.0');
        $second->setType('wordpress-core');
        self::assertTrue($guard->required($this->composer(packages: [$first]), $this->root));
        self::assertTrue($guard->required($this->composer(packages: [clone $first, $second]), $this->root));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function configurations(): iterable
    {
        foreach (['sympress-runtime', 'wpstarter'] as $key) {
            foreach (['empty' => [], 'null' => null, 'invalid' => false, 'file' => 'config/setup.json'] as $type => $value) {
                yield $key . '-' . $type => [[$key => $value]];
            }
        }
        yield 'core-layout' => [['wordpress-install-dir' => 'public/wp']];
        yield 'content-layout' => [['wordpress-content-dir' => null]];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('configurations')]
    public function testExplicitConfigurationAlwaysReachesValidation(array $extra): void
    {
        self::assertTrue((new AutomaticSetup())->required($this->composer($extra), $this->root));
    }

    /** @return iterable<string, array{string}> */
    public static function markers(): iterable
    {
        foreach (
            [
            'sympress-runtime.json', 'wpstarter.json', 'sympress-runtime-autoload.php', 'wpstarter-autoload.php',
            'wp-config.php', 'wordpress/index.php', 'wp-content/index.php', 'wp-includes/version.php',
            'var/runtime/payload/manifest.json', 'sympress-runtime.lock',
            ] as $path
        ) {
            yield $path => [$path];
        }
    }

    #[DataProvider('markers')]
    public function testConfigurationAndExistingSiteMarkersPreventDeferral(string $path): void
    {
        $this->write($path, 'invalid contents must not be silently ignored');
        self::assertTrue((new AutomaticSetup())->required($this->composer(), $this->root));
    }

    public function testInstalledExtensionsPreventDeferralEvenWithInvalidMetadata(): void
    {
        foreach (['sympress-runtime-extension', 'wpstarter-extension'] as $type) {
            $package = new Package('fixture/extension', '1.0.0.0', '1.0.0');
            $package->setType($type);
            self::assertTrue((new AutomaticSetup())->required($this->composer(packages: [$package]), $this->root));
        }
        $package = new Package('fixture/library-extension', '1.0.0.0', '1.0.0');
        $package->setExtra(['sympress-runtime' => null]);
        self::assertTrue((new AutomaticSetup())->required($this->composer(packages: [$package]), $this->root));
    }
}
