<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use RuntimeException;

/** Read-only path provider for Composer's metadata/autoload writers. */
final class LayoutInstaller extends LibraryInstaller
{
    /** @var array<string, string|null> */
    public array $paths = [];

    public function supports(string $packageType): bool
    {
        return true;
    }

    public function getInstallPath(PackageInterface $package): ?string
    {
        if (!array_key_exists($package->getName(), $this->paths)) {
            throw new RuntimeException('Package missing from the installed layout: ' . $package->getName());
        }

        return $this->paths[$package->getName()];
    }
}
