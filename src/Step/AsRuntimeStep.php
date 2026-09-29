<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsRuntimeStep
{
    public function __construct(public string $name, public int $priority = 0)
    {
    }
}
