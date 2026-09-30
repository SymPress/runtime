<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

use InvalidArgumentException;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Process\SystemProcess;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

final class DbChecker
{
    public const string WP_INSTALLED = 'WP_INSTALLED';
    public const string WPDB_EXISTS = 'WPDB_EXISTS';
    public const string WPDB_ENV_VALID = 'WPDB_ENV_VALID';
    public const string HEALTH_CHECK = 'health';
    private ?DbStatus $status = null;

    public function __construct(private readonly EnvReader $env, private readonly Io $io, private readonly SystemProcess $process, private readonly ExecutableFinder $finder, private readonly DatabaseProbe $probe)
    {
    }

    public function dbExists(): bool
    {
        return $this->status()->exists === true;
    }

    public function isInstalled(): bool
    {
        return $this->status()->installed === true;
    }

    public function isEnvValid(): bool
    {
        return $this->status()->envValid;
    }

    public function status(): DbStatus
    {
        $this->check();

        return $this->status ?? throw new InvalidArgumentException('Database status is unavailable.');
    }

    public function check(): void
    {
        if ($this->status !== null) {
            return;
        }
        $flags = $this->env->readMany(self::WPDB_ENV_VALID, self::WPDB_EXISTS, self::WP_INSTALLED);
        if (array_any(array_keys($flags), fn (string $name): bool => $this->env->rawValue($name) !== null)) {
            $valid = $flags[self::WPDB_ENV_VALID];
            $exists = $flags[self::WPDB_EXISTS];
            $installed = $flags[self::WP_INSTALLED];
            if (!is_bool($valid) || !is_bool($exists) || !is_bool($installed) || ($installed && !$exists) || ($exists && !$valid)) {
                throw new InvalidArgumentException('Database status flags must be complete, boolean and consistent.');
            }
            $this->status = new DbStatus($valid, $exists, $installed, 'provided');

            return;
        }
        try {
            $credentials = DbCredentials::fromEnvironment($this->env);
        } catch (InvalidArgumentException) {
            $credentials = null;
        }
        $this->status = $credentials === null ? new DbStatus(false, false, false, 'environment-incomplete') : $this->probe->inspect($credentials);
        $results = [self::WPDB_ENV_VALID => $this->status->envValid, self::WPDB_EXISTS => $this->status->exists, self::WP_INSTALLED => $this->status->installed];
        foreach ($results as $name => $value) {
            if ($value === null) {
                continue;
            }

            $this->env->write($name, $value ? '1' : '0');
        }
        $this->io->verbose('Database status: ' . $this->status->reason . '.');
    }

    public function mysqlcheck(bool $quick = false): bool
    {
        if (!$this->dbExists()) {
            return false;
        }
        $executable = $this->finder->find('mysqlcheck');
        $credentials = DbCredentials::fromEnvironment($this->env);
        if ($executable === null || $credentials === null) {
            $this->io->error('Database health check requires mysqlcheck and complete credentials.');

            return false;
        }
        $file = tempnam(sys_get_temp_dir(), 'sympress-db-');
        if ($file === false) {
            return false;
        }
        try {
            if (!chmod($file, 0600)) {
                return false;
            }
            $options = ['host' => $credentials->endpoint->host, 'user' => $credentials->user, 'password' => $credentials->password];
            if ($credentials->endpoint->port !== null) {
                $options['port'] = (string) $credentials->endpoint->port;
            }
            if ($credentials->endpoint->socket !== null) {
                $options['socket'] = $credentials->endpoint->socket;
            }
            $content = "[client]\n";
            foreach ($options as $name => $value) {
                $content .= $name . '="' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . "\"\n";
            }
            if (file_put_contents($file, $content) === false) {
                return false;
            }
            $arguments = [$executable, '--defaults-file=' . $file, '--check'];
            if ($quick) {
                $arguments[] = '--quick';
            }
            return $this->process->executeSilently([...$arguments, '--default-character-set=utf8', '--', $credentials->name]);
        } catch (Throwable) {
            $this->io->error('Database health check failed.');

            return false;
        } finally {
            unlink($file);
        }
    }
}
