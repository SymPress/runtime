<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use Attribute;

/** @api */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsRuntimeStep
{
    /** @api */
    public function __construct(public string $name, public int $priority = 0)
    {
    }
}
