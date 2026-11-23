<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Kernel\CacheLocation;

final class KernelVersionGateTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function installedVersions(): iterable
    {
        yield 'older release' => ['1.1.14.0', false];
        yield 'same release' => ['1.1.15.0', true];
        yield 'newer release' => ['1.1.18.0', true];
        yield 'aliased branch' => ['1.1.9999999.9999999-dev', true];
        yield 'unaliased branch' => ['dev-feature', true];
        yield 'replaced or provided kernel' => [null, true];
    }

    #[DataProvider('installedVersions')]
    public function testUncomparableKernelVersionsFollowTheNewestRules(?string $installed, bool $expected): void
    {
        self::assertSame($expected, CacheLocation::versionAtLeast($installed, '1.1.15'));
    }
}
