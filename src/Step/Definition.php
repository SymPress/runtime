<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

/** @internal */
final readonly class Definition
{
    /** @param class-string<StepInterface>|null $class */
    public function __construct(
        public string $name,
        public ?string $class = null,
        public bool $custom = false,
        public bool $commandOnly = false,
        public int $priority = 0,
        public ?string $serviceId = null,
    ) {
    }
}
