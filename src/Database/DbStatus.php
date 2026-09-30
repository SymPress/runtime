<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

/** @api */
final readonly class DbStatus
{
    /** @internal */
    public function __construct(public bool $envValid, public ?bool $exists, public ?bool $installed, public string $reason)
    {
    }
}
