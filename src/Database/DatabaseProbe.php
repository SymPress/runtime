<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

/** @internal */
interface DatabaseProbe
{
    public function inspect(DbCredentials $credentials): DbStatus;
}
