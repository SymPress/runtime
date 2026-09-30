<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

/** @internal */
final readonly class RuntimeBundle
{
    public function __construct(public string $loader, public string $readerClass, public string $fingerprint)
    {
    }
}
