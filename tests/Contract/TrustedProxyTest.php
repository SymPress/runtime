<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\TrustedProxy;

final class TrustedProxyTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed, mixed, bool}> */
    public static function cases(): iterable
    {
        yield 'exact ipv4' => ['127.0.0.1', 'HTTPS', '127.0.0.1', true];
        yield 'ipv4 cidr partial' => ['192.0.2.129', 'https', '192.0.2.128/25', true];
        yield 'ipv4 cidr outside' => ['192.0.2.127', 'https', '192.0.2.128/25', false];
        yield 'ipv6 cidr' => ['2001:db8::a', 'https', '2001:db8::/32', true];
        yield 'ipv6 outside' => ['2001:db9::a', 'https', '2001:db8::/32', false];
        yield 'ipv6 exact' => ['::1', 'https', '127.0.0.1, ::1', true];
        yield 'mapped ipv4 peer' => ['::ffff:192.0.2.129', 'https', '192.0.2.128/25', true];
        yield 'mapped ipv4 network' => ['192.0.2.129', 'https', '::ffff:192.0.2.128/121', true];
        yield 'mapped ipv4 exact' => ['::ffff:127.0.0.1', 'https', '127.0.0.1', true];
        yield 'mapped ipv4 outside' => ['::ffff:192.0.2.127', 'https', '192.0.2.128/25', false];
        yield 'mapped network ambiguous width' => ['192.0.2.129', 'https', '::ffff:192.0.2.128/95', false];
        yield 'untrusted direct' => ['203.0.113.9', 'https', '127.0.0.1', false];
        yield 'no configured trust' => ['127.0.0.1', 'https', null, false];
        yield 'malformed mask' => ['127.0.0.1', 'https', '127.0.0.1/-1,127.0.0.1/33', false];
        yield 'extra cidr component' => ['127.0.0.1', 'https', '127.0.0.1/32/extra', false];
        yield 'family mismatch' => ['127.0.0.1', 'https', '::1/128', false];
        yield 'header list ambiguous' => ['127.0.0.1', 'https,http', '127.0.0.1', false];
        yield 'whitespace scheme' => ['127.0.0.1', ' https', '127.0.0.1', false];
        yield 'array injection' => ['127.0.0.1', ['https'], '127.0.0.1', false];
        yield 'no peer' => [null, 'https', '0.0.0.0/0', false];
        yield 'invalid address' => ['127.0.0.1:1234', 'https', '127.0.0.1', false];
    }

    #[DataProvider('cases')]
    public function testOnlyConfiguredProxyAddressesCanAssertHttps(mixed $peer, mixed $scheme, mixed $trusted, bool $expected): void
    {
        self::assertSame($expected, TrustedProxy::forwardsHttps($peer, $scheme, $trusted));
    }
}
