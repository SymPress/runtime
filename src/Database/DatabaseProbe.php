<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

interface DatabaseProbe
{
    public function inspect(DbCredentials $credentials): DbStatus;
}
