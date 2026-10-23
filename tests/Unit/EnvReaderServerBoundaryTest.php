<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\EnvReader;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- The reader owns the superglobal boundary under test.
final class EnvReaderServerBoundaryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];
    /** @var array<string, mixed> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->env;
    }

    /** @return iterable<string, array{string}> */
    public static function requestMetaVariables(): iterable
    {
        foreach (['REQUEST_URI', 'QUERY_STRING', 'PATH_INFO', 'REDIRECT_WP_HOME', 'HTTP_X_WP_HOME', 'REMOTE_ADDR', 'SERVER_NAME', 'SCRIPT_FILENAME', 'HTTPS'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('requestMetaVariables')]
    public function testServerRequestMetadataIsNotReadAsConfiguration(string $name): void
    {
        unset($_ENV[$name]);
        $_SERVER[$name] = 'from-the-request';
        $_SERVER['RTV_SERVER_CONFIG'] = 'from-server-config';

        $reader = new EnvReader();

        self::assertNull($reader->read($name));
        self::assertSame('from-server-config', $reader->read('RTV_SERVER_CONFIG'));
    }

    public function testRequestMetadataNamesAreStillAcceptedFromTheRealEnvironment(): void
    {
        $_ENV['REQUEST_TIMEOUT'] = '30';
        $_SERVER['REQUEST_TIMEOUT'] = '30';

        self::assertSame('30', (new EnvReader())->read('REQUEST_TIMEOUT'));
        self::assertTrue(EnvReader::isRequestMetaVariable('REQUEST_TIMEOUT'));
        self::assertFalse(EnvReader::isRequestMetaVariable('DB_PASSWORD'));
        self::assertFalse(EnvReader::isRequestMetaVariable('AUTH_KEY'));
    }
}
