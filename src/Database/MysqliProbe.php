<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

use Throwable;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

/** @internal */
final class MysqliProbe implements DatabaseProbe
{
    public function inspect(DbCredentials $credentials): DbStatus
    {
        if (!extension_loaded('mysqli')) {
            return new DbStatus(true, null, null, 'driver-unavailable');
        }
        $connection = null;
        $stage = 'connect';
        try {
            $connection = mysqli_init();
            if (!$connection instanceof mysqli) {
                return new DbStatus(true, null, null, 'connection-unavailable');
            }
            $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            $endpoint = $credentials->endpoint;
            $host = str_contains($endpoint->host, ':') && extension_loaded('mysqlnd') ? '[' . $endpoint->host . ']' : $endpoint->host;
            if (!@$connection->real_connect($host, $credentials->user, $credentials->password, null, $endpoint->port, $endpoint->socket)) {
                return $this->failure($stage, $connection->connect_errno);
            }
            $stage = 'select';
            if (!@$connection->select_db($credentials->name)) {
                return $this->failure($stage, $connection->errno);
            }
            $stage = 'query';
            $table = str_replace('`', '``', $credentials->prefix . 'users');
            $result = @$connection->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            if (!$result instanceof mysqli_result) {
                return $this->failure($stage, $connection->errno);
            }
            $result->free();

            return new DbStatus(true, true, true, 'installed');
        } catch (mysqli_sql_exception $error) {
            return $this->failure($stage, (int) $error->getCode());
        } catch (Throwable) {
            return new DbStatus(true, null, null, 'probe-unavailable');
        } finally {
            if ($connection instanceof mysqli) {
                $connection->close();
            }
        }
    }

    private function failure(string $stage, int $code): DbStatus
    {
        if ($stage === 'select' && $code === 1049) {
            return new DbStatus(true, false, false, 'database-missing');
        }
        if ($stage === 'query') {
            return new DbStatus(true, true, $code === 1146 ? false : null, $code === 1146 ? 'wordpress-table-missing' : 'table-probe-unavailable');
        }

        return new DbStatus(true, null, null, 'connection-unavailable');
    }
}
