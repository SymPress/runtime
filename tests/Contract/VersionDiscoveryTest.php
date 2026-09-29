<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use SymPress\Runtime\WordPress\VersionDiscovery;

final class VersionDiscoveryTest extends TemporaryProject
{
    /** @return iterable<string, array{string, string}> */
    public static function versions(): iterable
    {
        yield 'major' => ['6', '6.0.0'];
        yield 'minor' => ['6.8', '6.8.0'];
        yield 'normalized' => ['6.8.1.0', '6.8.1'];
        yield 'suffix' => ['6.8-beta', '6.8.0'];
        yield 'inline suffix' => ['6.8beta', '6.8.0'];
        yield 'legacy empty component' => ['6..3', '6.0.3'];
        yield 'dev' => ['dev-main', ''];
        yield 'prefixed' => ['v6.8', ''];
    }

    #[DataProvider('versions')]
    #[Group('PAR-OPT-034')]
    public function testSourceVersionNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, VersionDiscovery::normalize($input));
    }

    /** @param list<string> $versions */
    private function discovery(array $versions): VersionDiscovery
    {
        $packages = [];
        foreach ($versions as $index => $version) {
            $packages[] = ['name' => 'example/core-' . $index, 'version' => $version, 'type' => 'wordpress-core', 'install-path' => '../../public/wp-' . $index];
        }
        $this->write('vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

        return new VersionDiscovery(new PackageFinder(new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin')));
    }

    #[Group('PAR-CLI-016')]
    public function testFallbackOnlyAppliesToSingleNonnumericCore(): void
    {
        self::assertSame('6.8.1', $this->discovery(['6.8.1.0'])->discover('5.0'));
        self::assertSame('6.8.0', $this->discovery(['dev-main'])->discover('6.8'));
        $this->expectExceptionMessage('Exactly one');
        $this->discovery([])->discover('6.8');
    }

    #[Group('PAR-CLI-016')]
    public function testMultipleCoresAreRejectedEvenWithFallback(): void
    {
        $this->expectExceptionMessage('Exactly one');
        $this->discovery(['6.8', '6.9'])->discover('6.8');
    }

    #[Group('PAR-CLI-016')]
    public function testOldWordPressIsRejected(): void
    {
        $this->expectExceptionMessage('4.8 or newer');
        $this->discovery(['4.7'])->discover();
    }
}
