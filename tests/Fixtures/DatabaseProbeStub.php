<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Fixtures;

use SymPress\Runtime\Database\DatabaseProbe;
use SymPress\Runtime\Database\DbCredentials;
use SymPress\Runtime\Database\DbStatus;

final class DatabaseProbeStub implements DatabaseProbe
{
    public int $calls = 0;

    public function __construct(private readonly DbStatus $result)
    {
    }

    public function inspect(DbCredentials $credentials): DbStatus
    {
        ++$this->calls;

        return $this->result;
    }
}
