<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Unit;

use Composer\Package\Package;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Composer\PackageDestination;

final class PackageDestinationTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, ?string}> */
    public static function paths(): iterable
    {
        yield 'plugin default' => ['wordpress-plugin', [], 'wp-content/plugins/example/'];
        yield 'mu default' => ['wordpress-muplugin', [], 'wp-content/mu-plugins/example/'];
        yield 'theme default' => ['wordpress-theme', [], 'wp-content/themes/example/'];
        yield 'dropin default' => ['wordpress-dropin', [], 'wp-content/example/'];
        yield 'core' => ['wordpress-core', ['wordpress-install-dir' => 'public/wp'], 'public/wp'];
        yield 'first matching selector' => ['wordpress-plugin', ['installer-paths' => ['first/{$vendor}/{$name}' => ['private/example'], 'second/{$name}' => ['type:wordpress-plugin']]], 'first/private/example'];
        yield 'vendor and type variables' => ['wordpress-theme', ['installer-paths' => ['custom/{$type}/{$name}' => 'vendor:private']], 'custom/wordpress-theme/example'];
        yield 'disable wordpress' => ['wordpress-plugin', ['installer-disable' => ['wordpress']], null];
        yield 'disable all' => ['wordpress-plugin', ['installer-disable' => true], null];
        yield 'other type' => ['library', [], null];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('paths')]
    public function testStandardPathRules(string $type, array $extra, ?string $expected): void
    {
        $package = new Package('private/example', '1.0.0.0', '1.0.0');
        $package->setType($type);
        self::assertSame($expected, (new PackageDestination())->forPackage($package, ['extra' => $extra], ['composer/installers', 'johnpbloch/wordpress-core-installer']));
        self::assertNull((new PackageDestination())->forPackage($package, ['extra' => $extra], []));
        $package->setExtra(['installer-name' => '']);
        self::assertSame($expected, (new PackageDestination())->forPackage($package, ['extra' => $extra], ['composer/installers', 'johnpbloch/wordpress-core-installer']));
    }
}
