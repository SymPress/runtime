<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Database\DbHost;
use SymPress\Runtime\Database\DbStatus;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Process\SystemProcess;
use SymPress\Runtime\Tests\Fixtures\DatabaseProbeStub;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\ExecutableFinder;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class DbCheckerTest extends TemporaryProject
{
    /**
     * @param array<string, string> $values
     * @return array{DbChecker, EnvReader, DatabaseProbeStub, BufferedOutput}
     */
    private function checker(array $values, DbStatus $result = new DbStatus(true, true, true, 'installed')): array
    {
        $environment = new EnvReader();
        foreach ($values as $name => $value) {
            $environment->write($name, $value);
        }
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $io = new Io($input, $output);
        $probe = new DatabaseProbeStub($result);
        $checker = new DbChecker($environment, $io, new SystemProcess(new Paths($this->root), $io), new ExecutableFinder(), $probe);

        return [$checker, $environment, $probe, $output];
    }

    #[Group('PAR-SVC-022')]
    public function testCheckIsLazyMemoizedAndWritesConsistentKnownFlagsOnce(): void
    {
        [$checker, $environment, $probe] = $this->checker(['DB_NAME' => 'fixture', 'DB_USER' => 'fixture']);
        self::assertSame(0, $probe->calls);
        self::assertTrue($checker->isInstalled());
        self::assertTrue($checker->dbExists());
        self::assertTrue($checker->isEnvValid());
        self::assertSame(1, $probe->calls);
        self::assertSame(['WPDB_ENV_VALID' => true, 'WPDB_EXISTS' => true, 'WP_INSTALLED' => true], $environment->readMany('WPDB_ENV_VALID', 'WPDB_EXISTS', 'WP_INSTALLED'));
    }

    #[Group('PAR-SVC-022')]
    public function testUnknownConnectionStateIsNotPublishedAsMissingDatabase(): void
    {
        [$checker, $environment] = $this->checker(['DB_NAME' => 'fixture', 'DB_USER' => 'fixture'], new DbStatus(true, null, null, 'connection-unavailable'));
        self::assertFalse($checker->dbExists());
        self::assertNull($checker->status()->exists);
        self::assertNull($checker->status()->installed);
        self::assertSame('connection-unavailable', $checker->status()->reason);
        self::assertNull($environment->read('WPDB_EXISTS'));
        self::assertTrue($environment->read('WPDB_ENV_VALID'));
    }

    #[Group('PAR-SVC-022')]
    public function testIncompleteCredentialsNeverAttemptConnection(): void
    {
        [$checker, $environment, $probe] = $this->checker([]);
        self::assertFalse($checker->isEnvValid());
        self::assertFalse($checker->isInstalled());
        self::assertSame(0, $probe->calls);
        self::assertFalse($environment->read('WPDB_EXISTS'));
    }

    #[Group('PAR-SVC-022')]
    public function testProvidedFlagsAreAuthoritativeAndPartialOrInvalidFlagsFail(): void
    {
        [$checker, $environment, $probe] = $this->checker(['WPDB_ENV_VALID' => '1', 'WPDB_EXISTS' => '1', 'WP_INSTALLED' => '0']);
        self::assertTrue($checker->dbExists());
        self::assertFalse($checker->isInstalled());
        self::assertSame(0, $probe->calls);
        $environment->write('WPDB_EXISTS', 'not-boolean');
        [$invalid, , $invalidProbe] = $this->checker([]);
        try {
            $invalid->check();
            self::fail('Invalid provided status must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('complete, boolean and consistent', $error->getMessage());
        }
        self::assertSame(0, $invalidProbe->calls);
    }

    #[Group('PAR-SVC-022')]
    public function testHealthCheckUsesPrivateOptionFileAndLiteralArguments(): void
    {
        $this->write('bin/mysqlcheck', <<<'PHP'
#!/usr/bin/env php
<?php
$file = substr($argv[1], strlen('--defaults-file='));
file_put_contents('health.json', json_encode([$argv, fileperms($file) & 0777, str_contains(file_get_contents($file), 'synthetic-secret'), $file]));
PHP);
        chmod($this->root . '/bin/mysqlcheck', 0700);
        putenv('PATH=' . $this->root . '/bin:' . getenv('PATH'));
        [$checker, , , $output] = $this->checker(['DB_NAME' => 'name; $(touch injected)', 'DB_USER' => 'fixture', 'DB_PASSWORD' => 'synthetic-secret', 'WPDB_ENV_VALID' => '1', 'WPDB_EXISTS' => '1', 'WP_INSTALLED' => '1']);
        self::assertTrue($checker->mysqlcheck());
        $record = json_decode((string) file_get_contents($this->root . '/health.json'), true);
        self::assertSame(0600, $record[1]);
        self::assertTrue($record[2]);
        self::assertStringNotContainsString('synthetic-secret', implode(' ', $record[0]));
        self::assertSame(['--', 'name; $(touch injected)'], array_slice($record[0], -2));
        self::assertFileDoesNotExist($record[3]);
        self::assertFileDoesNotExist($this->root . '/injected');
        self::assertStringNotContainsString('synthetic-secret', $output->fetch());
    }

    /** @return iterable<string, array{string, string, ?int, ?string}> */
    public static function hosts(): iterable
    {
        yield 'default' => ['', 'localhost', null, null];
        yield 'host and port' => ['db:3307', 'db', 3307, null];
        yield 'IPv6' => ['::1', '::1', null, null];
        yield 'IPv6 and port' => ['[::1]:3307', '::1', 3307, null];
        yield 'socket' => ['localhost:/tmp/mysql.sock', 'localhost', null, '/tmp/mysql.sock'];
        yield 'port and socket' => ['localhost:3307:/tmp/mysql.sock', 'localhost', 3307, '/tmp/mysql.sock'];
    }

    #[DataProvider('hosts')]
    public function testDatabaseHostForms(string $input, string $host, ?int $port, ?string $socket): void
    {
        $parsed = DbHost::parse($input);
        self::assertSame([$host, $port, $socket], [$parsed->host, $parsed->port, $parsed->socket]);
    }
}
