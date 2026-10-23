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
        foreach (['REQUEST_URI', 'REQUEST_METHOD', 'QUERY_STRING', 'PATH_INFO', 'REDIRECT_WP_HOME', 'HTTP_X_WP_HOME', 'REMOTE_ADDR', 'SERVER_NAME', 'SCRIPT_FILENAME', 'DOCUMENT_ROOT', 'CONTENT_TYPE', 'HTTPS'] as $name) {
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
        unset($_SERVER['REQUEST_URI']);
        $_ENV['REQUEST_URI'] = '/from-the-environment';

        self::assertSame('/from-the-environment', (new EnvReader())->read('REQUEST_URI'));
    }

    public function testOperatorNamesWithCgiLikePrefixesRemainConfiguration(): void
    {
        foreach (['SERVER_ROLE', 'REQUEST_TIMEOUT', 'CONTENT_BUCKET', 'DOCUMENT_STORE'] as $name) {
            unset($_ENV[$name]);
            $_SERVER[$name] = 'operator-' . $name;
            self::assertFalse(EnvReader::isRequestMetaVariable($name));
            self::assertSame('operator-' . $name, (new EnvReader())->read($name));
        }
        self::assertFalse(EnvReader::isRequestMetaVariable('DB_PASSWORD'));
        self::assertFalse(EnvReader::isRequestMetaVariable('AUTH_KEY'));
    }

    public function testInterpolationDoesNotReadRequestMetadataFromServer(): void
    {
        unset($_ENV['REQUEST_URI']);
        putenv('REQUEST_URI');
        $_SERVER['REQUEST_URI'] = '/from-the-request';
        $root = sys_get_temp_dir() . '/sympress-server-boundary-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            file_put_contents($root . '/.env', 'RTV_BASE=${REQUEST_URI:-fallback}' . "\n");
            $reader = new EnvReader();
            $reader->loadFile($root . '/.env');

            self::assertSame('fallback', $reader->read('RTV_BASE'));
        } finally {
            unlink($root . '/.env');
            rmdir($root);
        }
    }
}
