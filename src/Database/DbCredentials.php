<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

use SymPress\Runtime\Env\EnvReader;

final readonly class DbCredentials
{
    public function __construct(public DbHost $endpoint, public string $name, public string $user, public string $password, public string $prefix)
    {
    }

    public static function fromEnvironment(EnvReader $environment): ?self
    {
        $values = $environment->readMany('DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_TABLE_PREFIX');
        $name = $values['DB_NAME'];
        $user = $values['DB_USER'];
        if (!is_string($name) || $name === '' || !is_string($user) || $user === '') {
            return null;
        }
        $host = $values['DB_HOST'];
        $password = $values['DB_PASSWORD'];
        $prefix = $values['DB_TABLE_PREFIX'];

        return new self(DbHost::parse(is_string($host) && $host !== '' ? $host : 'localhost'), $name, $user, is_string($password) ? $password : '', is_string($prefix) && $prefix !== '' ? $prefix : 'wp_');
    }
}
