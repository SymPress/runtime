<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

final readonly class DbStatus
{
    public function __construct(public bool $envValid, public ?bool $exists, public ?bool $installed, public string $reason)
    {
    }
}
