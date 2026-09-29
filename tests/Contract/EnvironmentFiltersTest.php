<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\Filters;

final class EnvironmentFiltersTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?bool}> */
    public static function booleans(): iterable
    {
        yield 'empty invalid' => ['', null];
        yield 'null invalid' => [null, null];
        yield 'false stays false' => [false, false];
        yield 'zero stays false' => [0, false];
        yield 'false text' => ['false', false];
        yield 'case and whitespace' => [' YES ', true];
        yield 'off' => ['off', false];
        yield 'unrecognized' => ['invalid', null];
        yield 'nonbinary number' => [2, null];
        yield 'array' => [[], null];
    }

    #[DataProvider('booleans')]
    #[Group('PAR-ENV-011')]
    public function testBooleanCasting(mixed $value, ?bool $expected): void
    {
        self::assertSame($expected, (new Filters())->filter(Filters::FILTER_BOOL, $value));
    }

    /** @return iterable<string, array{string, mixed, int|float|null}> */
    public static function numbers(): iterable
    {
        yield 'integer decimal truncation' => ['int', '12.9', 12];
        yield 'negative truncation' => ['int', '-12.9', -12];
        yield 'integer exponent' => ['int', '1e3', 1000];
        yield 'integer bool' => ['int', true, 1];
        yield 'invalid integer' => ['int', 'word', null];
        yield 'float exponent' => ['float', '1.25e2', 125.0];
        yield 'float bool rejected' => ['float', true, null];
        yield 'float null rejected' => ['float', null, null];
    }

    #[DataProvider('numbers')]
    #[Group('PAR-ENV-012')]
    public function testNumericCasting(string $mode, mixed $value, int|float|null $expected): void
    {
        self::assertSame($expected, (new Filters())->filter($mode, $value));
    }

    #[Group('PAR-ENV-013')]
    public function testMixedTypesRetainBranchPriorityAndEmptyValueBehavior(): void
    {
        $filters = new Filters();
        self::assertSame(0, $filters->filter('int|bool', '0'));
        self::assertSame(1, $filters->filter('int|bool', '1'));
        self::assertFalse($filters->filter('int|bool', 'false'));
        self::assertNull($filters->filter('int|bool', ''));
        self::assertFalse($filters->filter('string|bool', '0'));
        self::assertSame('minor', $filters->filter('string|bool', 'minor'));
        self::assertSame('path&amp;file', $filters->filter('string|bool', 'path&file'));
        self::assertNull($filters->filter('string|bool', ''));
    }

    #[Group('PAR-ENV-014')]
    public function testNativeSecretsRemainExactWhileBothLegacyCastsAreRetained(): void
    {
        $input = "<b>A</b> & ' \" \\ \0 ä";
        $escaped = 'A &amp; &#039; &quot; \\  ä';
        self::assertSame($input, (new Filters())->filter('raw-string', $input));
        self::assertSame($escaped, (new Filters('release-3.0.1'))->filter('raw-string', $input));
        self::assertSame("<b>A</b> & \\' \\\" \\\\ \\0 ä", (new Filters('upstream-dev'))->filter('raw-string', $input));
        self::assertSame('&amp;', (new Filters())->filter('string', '&amp;'));
        self::assertNull((new Filters())->filter('string', []));
        self::assertNull((new Filters())->filter('raw-string', null));
    }

    #[Group('PAR-ENV-015')]
    public function testPermissionCastingKeepsUpstreamNumericStringSemantics(): void
    {
        $filters = new Filters();
        self::assertSame(420, $filters->filter('mod', 0644));
        self::assertSame(420, $filters->filter('mod', '0644'));
        self::assertSame(493, $filters->filter('mod', '755'));
        self::assertSame(0, $filters->filter('mod', '89'));
        self::assertNull($filters->filter('mod', 755));
        self::assertNull($filters->filter('mod', 'not numeric'));
    }

    #[Group('PAR-ENV-016')]
    public function testTablePrefixFiltering(): void
    {
        $filters = new Filters();
        self::assertSame('wp_', $filters->filter('table-prefix', ''));
        self::assertSame('wp_', $filters->filter('table-prefix', null));
        self::assertSame('my_wp2', $filters->filter('table-prefix', 'my-_ wp2!'));
        self::assertSame('', $filters->filter('table-prefix', '!'));
    }

    #[Group('PAR-ENV-010')]
    public function testCustomFilterAliasesKeepReleaseDifferencesAndExcludeTablePrefix(): void
    {
        self::assertSame('int|bool', Filters::resolveFilterName(' INT_OR_BOOL '));
        self::assertSame('string|bool', Filters::resolveFilterName('STRING_OR_BOOL'));
        self::assertSame('mod', Filters::resolveFilterName('OCTAL_MOD'));
        self::assertSame('', Filters::resolveFilterName('TABLE_PREFIX'));
        self::assertSame('', Filters::resolveFilterName('unknown'));
        self::assertSame('', Filters::resolveFilterName('RAW_STRING', 'release-3.0.1'));
        self::assertSame('raw-string', Filters::resolveFilterName('RAW_STRING', 'upstream-dev'));
        self::assertSame('raw-string', Filters::resolveFilterName('raw-string'));
        self::assertSame('', Filters::resolveFilterName('raw-string', 'upstream-dev'));
    }
}
