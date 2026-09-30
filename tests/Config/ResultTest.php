<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SymPress\Runtime\Config\Result;

final class ResultTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function values(): iterable
    {
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty-string' => [''];
        yield 'empty-array' => [[]];
        yield 'text' => ['value'];
    }

    #[DataProvider('values')]
    #[Group('PAR-RES-001')]
    #[Group('PAR-RES-002')]
    #[Group('PAR-RES-003')]
    #[Group('PAR-RES-004')]
    #[Group('PAR-RES-005')]
    #[Group('PAR-RES-006')]
    public function testValuesPreserveTheirTypes(mixed $value): void
    {
        $result = Result::ok($value);
        self::assertSame($value, $result->unwrap());
        self::assertSame($value, $result->unwrapOrFallback('fallback'));
        self::assertTrue($result->notEmpty());
        self::assertTrue($result->is($value));
        self::assertFalse($result->not($value));
        self::assertTrue($result->either(new \stdClass(), $value));
        self::assertFalse($result->is(null));
        self::assertFalse($result->either(null, new \stdClass()));
    }

    #[Group('PAR-RES-001')]
    #[Group('PAR-RES-002')]
    #[Group('PAR-RES-003')]
    #[Group('PAR-RES-004')]
    #[Group('PAR-RES-005')]
    #[Group('PAR-RES-006')]
    public function testNoneAndErrorsRemainDistinct(): void
    {
        $none = Result::none();
        self::assertNull($none->unwrap());
        self::assertTrue($none->is(null));
        self::assertTrue($none->either(false, null));
        self::assertFalse($none->notEmpty());
        self::assertSame('fallback', $none->unwrapOrFallback('fallback'));
        $error = new RuntimeException('failed');
        $result = Result::error($error);
        self::assertFalse($result->notEmpty());
        self::assertFalse($result->is(null));
        self::assertTrue($result->not(null));
        self::assertFalse($result->either(null, false));
        self::assertSame('fallback', $result->unwrapOrFallback('fallback'));
        $this->expectExceptionObject($error);
        $result->unwrap();
    }

    #[Group('PAR-CFG-011')]
    public function testNestedPromisesResolveOnce(): void
    {
        $calls = 0;
        $result = Result::promise(static function () use (&$calls): Result {
            $calls++;

            return Result::promise(static fn (): Result => Result::ok(42));
        });
        self::assertSame(0, $calls);
        self::assertSame(42, Result::ok($result)->unwrap());
        self::assertSame(42, $result->unwrap());
        self::assertSame(1, $calls);
        self::assertFalse(Result::promise(static fn (): Result => Result::errored('nested'))->notEmpty());
        self::assertFalse(Result::ok(new RuntimeException('error value'))->notEmpty());
        self::assertFalse(Result::error()->notEmpty());
    }

    #[Group('PAR-CFG-011')]
    public function testPromiseFailureIsRemembered(): void
    {
        $calls = 0;
        $result = Result::promise(static function () use (&$calls): never {
            $calls++;
            throw new RuntimeException('failed promise');
        });
        self::assertSame('fallback', $result->unwrapOrFallback('fallback'));
        self::assertFalse($result->notEmpty());
        self::assertSame(1, $calls);
        $this->expectExceptionMessage('failed promise');
        $result->unwrap();
    }

    #[Group('PAR-RES-004')]
    #[Group('PAR-RES-006')]
    public function testComparisonsAreStrict(): void
    {
        self::assertFalse(Result::ok(0)->is(false));
        self::assertFalse(Result::ok(0)->either(false, '0', null));
    }
}
