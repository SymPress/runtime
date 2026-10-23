<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\EnvReader;
use Symfony\Component\Filesystem\Filesystem;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- The reader owns the superglobal boundary under test.
final class EnvReaderLoadedNamesTest extends TestCase
{
    private string $root;
    /** @var array<string, mixed> */
    private array $server = [];
    /** @var array<string, mixed> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->env = $_ENV;
        unset($_SERVER['SYMFONY_DOTENV_VARS'], $_ENV['SYMFONY_DOTENV_VARS']);
        foreach (['RTV_FIRST', 'RTV_SECOND', 'RTV_THIRD'] as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $this->root = sys_get_temp_dir() . '/sympress-loaded-names-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
        $_SERVER = $this->server;
        $_ENV = $this->env;
    }

    public function testLoadedNamesArePublishedAfterParsingAndAfterCacheRestore(): void
    {
        file_put_contents($this->root . '/.env', "RTV_FIRST=1\nRTV_SECOND=2\n");
        file_put_contents($this->root . '/.env.production', "RTV_THIRD=3\n");

        $reader = new EnvReader();
        $reader->loadChain('.env', $this->root, environment: 'production');

        self::assertSame('RTV_FIRST,RTV_SECOND,RTV_THIRD', $_ENV['SYMFONY_DOTENV_VARS']);
        self::assertSame($_ENV['SYMFONY_DOTENV_VARS'], $_SERVER['SYMFONY_DOTENV_VARS']);
        self::assertTrue($reader->dumpCached($this->root . '/.env.cached.php'));

        unset($_SERVER['SYMFONY_DOTENV_VARS'], $_ENV['SYMFONY_DOTENV_VARS']);
        foreach (['RTV_FIRST', 'RTV_SECOND', 'RTV_THIRD'] as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }

        $restored = EnvReader::buildFromCacheDump($this->root . '/.env.cached.php', environment: 'production');

        self::assertTrue($restored->hasCachedValues());
        self::assertSame('3', $restored->read('RTV_THIRD'));
        self::assertSame('RTV_FIRST,RTV_SECOND,RTV_THIRD', $_ENV['SYMFONY_DOTENV_VARS']);
        self::assertSame($_ENV['SYMFONY_DOTENV_VARS'], $_SERVER['SYMFONY_DOTENV_VARS']);
    }
}
