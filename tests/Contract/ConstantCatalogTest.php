<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- These isolated tests explicitly exercise environment globals.
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\ConstantCatalog;
use SymPress\Runtime\Env\EnvReader;

final class ConstantCatalogTest extends TestCase
{
    /** @return iterable<string, array{string, string|null, string, bool|int|float|string}> */
    public static function constants(): iterable
    {
        $inventory = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/upstream-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
        $types = ['STRING' => 'string', 'RAW_STRING' => 'raw-string', 'BOOL' => 'bool', 'INT' => 'int', 'FLOAT' => 'float', 'INT_OR_BOOL' => 'int|bool', 'STRING_OR_BOOL' => 'string|bool', 'OCTAL_MOD' => 'mod', 'null' => null];
        $samples = [
            'STRING' => ['<b>value</b>&', 'value&amp;'],
            'RAW_STRING' => ["raw'<tag>&\\", "raw'<tag>&\\"],
            'BOOL' => ['false', false],
            'INT' => ['12.9', 12],
            'FLOAT' => ['1.25e2', 125.0],
            'INT_OR_BOOL' => ['3', 3],
            'STRING_OR_BOOL' => ['minor', 'minor'],
            'OCTAL_MOD' => ['0755', 493],
            'null' => ['unfiltered', 'unfiltered'],
        ];
        foreach ($inventory['baselines']['dev']['constants'] as $constant) {
            [$raw, $value] = $samples[$constant['type']];
            yield 'PAR-CONST-' . $constant['name'] => [$constant['name'], $types[$constant['type']], $raw, $value];
        }
    }

    #[DataProvider('constants')]
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testEveryInventoriedConstantIsReadAndDefinedWithItsNativeType(string $name, ?string $type, string $raw, bool|int|float|string $expected): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
        $reader = new EnvReader();
        self::assertSame($type, ConstantCatalog::TYPES[$name]);
        self::assertFalse($reader->has($name));
        $predefined = defined($name);
        $original = $predefined ? constant($name) : null;
        self::assertTrue(!$predefined || in_array($name, ['FTP_ASCII', 'FTP_BINARY'], true), 'Only the FTP extension may predefine these catalog entries.');
        $reader->write($name, $raw);
        self::assertSame($expected, $reader->read($name));
        $reader->setupConstants();
        self::assertSame($predefined ? $original : $expected, constant($name));
    }

    public function testBothPinnedCatalogsAreCompleteAndProfileDifferencesStayExplicit(): void
    {
        self::assertCount(157, ConstantCatalog::TYPES);
        self::assertSame('string', ConstantCatalog::forProfile('release-3.0.1')['DB_PASSWORD']);
        self::assertSame('raw-string', ConstantCatalog::forProfile('upstream-dev')['DB_PASSWORD']);
        self::assertNull(ConstantCatalog::TYPES['SUNRISE']);
    }
}
