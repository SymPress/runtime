<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Database\DbCredentials;
use SymPress\Runtime\Database\DbHost;
use SymPress\Runtime\Database\MysqliProbe;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Process\SystemProcess;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\ExecutableFinder;
use mysqli;

final class DatabaseTest extends TestCase
{
    #[Group('database')]
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testRealDatabaseMissingSchemaEmptySchemaUsersTableAndDeniedCredentials(): void
    {
        $host = getenv('RUNTIME_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('Set RUNTIME_TEST_DB_HOST for the isolated database fixture.');
        }
        $endpoint = DbHost::parse($host);
        $user = getenv('RUNTIME_TEST_DB_USER') ?: 'root';
        $password = getenv('RUNTIME_TEST_DB_PASSWORD') ?: '';
        $database = 'runtime_fixture_' . bin2hex(random_bytes(8));
        $connection = new mysqli($endpoint->host, $user, $password, null, $endpoint->port, $endpoint->socket);
        $probe = new MysqliProbe();
        $credentials = new DbCredentials($endpoint, $database, $user, $password, 'fixture_');
        try {
            $missing = $probe->inspect($credentials);
            self::assertFalse($missing->exists);
            self::assertFalse($missing->installed);
            $connection->query('CREATE DATABASE `' . $database . '`');
            $empty = $probe->inspect($credentials);
            self::assertTrue($empty->exists);
            self::assertFalse($empty->installed);
            $connection->query('CREATE TABLE `' . $database . '`.`fixture_users` (ID BIGINT PRIMARY KEY)');
            $installed = $probe->inspect($credentials);
            self::assertTrue($installed->exists);
            self::assertTrue($installed->installed);
            $environment = new EnvReader();
            foreach (['DB_HOST' => $host, 'DB_NAME' => $database, 'DB_USER' => $user, 'DB_PASSWORD' => $password, 'DB_TABLE_PREFIX' => 'fixture_'] as $name => $value) {
                $environment->write($name, $value);
            }
            $input = new ArrayInput([]);
            $input->setInteractive(false);
            $output = new BufferedOutput();
            $io = new Io($input, $output);
            $checker = new DbChecker($environment, $io, new SystemProcess(new Paths(sys_get_temp_dir()), $io), new ExecutableFinder(), $probe);
            self::assertTrue($checker->mysqlcheck(), $output->fetch());
            $denied = $probe->inspect(new DbCredentials($endpoint, $database, 'no_such_runtime_user', 'synthetic-secret', 'fixture_'));
            self::assertNull($denied->exists);
            self::assertNull($denied->installed);
            self::assertSame('connection-unavailable', $denied->reason);
        } finally {
            $connection->query('DROP DATABASE IF EXISTS `' . $database . '`');
            $connection->close();
        }
    }
}
