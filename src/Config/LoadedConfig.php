<?php

declare(strict_types=1);

namespace SymPress\Runtime\Config;

/** @internal */
final readonly class LoadedConfig
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $provenance
     * @param list<string> $diagnostics
     */
    public function __construct(
        public array $values,
        public array $provenance,
        public array $diagnostics,
        public string $profile,
    ) {
    }
}
