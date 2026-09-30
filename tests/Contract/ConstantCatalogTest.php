<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- These isolated tests explicitly exercise environment globals.
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use SymPress\Runtime\Env\ConstantCatalog;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class ConstantCatalogTest extends TemporaryProject
{
    /** @return iterable<string, array{string, string|null, string, bool|int|float|string}> */
    public static function constants(): iterable
    {
        $inventory = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/maintainers/upstream-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
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

    /** @return iterable<string, array{string, bool}> */
    public static function edgeCases(): iterable
    {
        foreach (self::constants() as $id => [$name, $type]) {
            yield $id => [$name, in_array($type, ['bool', 'int', 'float', 'int|bool', 'mod'], true)];
        }
    }

    #[DataProvider('edgeCases')]
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testMissingAndUnrecognizedValuesSurviveCacheWithTheirTypes(string $name, bool $rejectsUnrecognized): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
        $predefined = defined($name);
        $original = $predefined ? constant($name) : null;
        $missing = new EnvReader();
        $missing->write('RTV_CACHE_SEED', 'fixture');
        $missing->setupConstants();
        self::assertNull($missing->read($name));
        self::assertSame($predefined, defined($name));
        $file = $this->root . '/missing.php';
        self::assertTrue($missing->dumpCached($file));
        $cachedMissing = EnvReader::buildFromCacheDump($file);
        self::assertNull($cachedMissing->read($name));
        self::assertSame($predefined, defined($name));

        $reader = new EnvReader();
        $reader->write($name, 'unrecognized!');
        $expected = $rejectsUnrecognized ? null : 'unrecognized!';
        self::assertSame($expected, $reader->read($name));
        $reader->setupConstants();
        self::assertSame($predefined || !$rejectsUnrecognized, defined($name));
        if (defined($name)) {
            self::assertSame($predefined ? $original : $expected, constant($name));
        }
        $file = $this->root . '/unrecognized.php';
        self::assertTrue($reader->dumpCached($file));
        $cached = EnvReader::buildFromCacheDump($file);
        self::assertSame($expected, $cached->read($name));
        self::assertSame($predefined || !$rejectsUnrecognized, defined($name));
    }

    #[DataProvider('constants')]
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testEveryPredefinedConstantSurvivesSetupAndCache(string $name, ?string $type, string $raw, bool|int|float|string $expected): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
        if (!defined($name)) {
            define($name, 'predefined-fixture');
        }
        $original = constant($name);
        $reader = new EnvReader();
        self::assertSame($type, ConstantCatalog::TYPES[$name]);
        $reader->write($name, $raw);
        $reader->setupConstants();
        self::assertSame($expected, $reader->read($name));
        self::assertSame($original, constant($name));
        $file = $this->root . '/predefined.php';
        self::assertTrue($reader->dumpCached($file));
        $cached = EnvReader::buildFromCacheDump($file);
        self::assertSame($expected, $cached->read($name));
        self::assertSame($original, constant($name));
    }

    public function testBothPinnedCatalogsAreCompleteAndProfileDifferencesStayExplicit(): void
    {
        self::assertCount(157, ConstantCatalog::TYPES);
        self::assertSame('string', ConstantCatalog::forProfile('release-3.0.1')['DB_PASSWORD']);
        self::assertSame('raw-string', ConstantCatalog::forProfile('upstream-dev')['DB_PASSWORD']);
        self::assertNull(ConstantCatalog::TYPES['SUNRISE']);
    }
}
