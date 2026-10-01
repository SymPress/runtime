<?php

declare(strict_types=1);

namespace SymPress\Runtime\Package;

/** @internal */
final readonly class Package
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        private string $name,
        private string $type,
        private string $version,
        private string $installPath,
        private array $extra = [],
        private bool $dev = false,
        private ?string $normalizedVersion = null,
    ) {
    }

    public function getName(): string
    {
        return strtolower($this->name);
    }

    public function getPrettyName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPrettyVersion(): string
    {
        return $this->version;
    }

    public function getVersion(): string
    {
        return $this->normalizedVersion ?? $this->version;
    }

    public function getInstallPath(): string
    {
        return $this->installPath;
    }

    /** @return array<string, mixed> */
    public function getExtra(): array
    {
        return $this->extra;
    }

    public function isDev(): bool
    {
        return $this->dev;
    }
}
