<?php

declare(strict_types=1);

namespace SymPress\Runtime\WordPress;

use RuntimeException;
use SymPress\Runtime\Package\PackageFinder;

final readonly class VersionDiscovery
{
    public function __construct(private PackageFinder $packages)
    {
    }

    public static function normalize(string $version): string
    {
        if (!preg_match('/^[0-9][0-9.]*/', $version, $matches)) {
            return '';
        }
        $parts = array_slice(explode('.', trim($matches[0], '.')), 0, 3);

        return implode('.', array_map(intval(...), array_pad($parts, 3, '0')));
    }

    public function discover(?string $fallback = null): string
    {
        $cores = $this->packages->findByType('wordpress-core');
        if (count($cores) !== 1) {
            throw new RuntimeException('Exactly one installed wordpress-core package is required.');
        }
        $version = self::normalize($cores[0]->getVersion());
        if ($version === '') {
            $version = self::normalize($fallback ?? '');
        }
        if ($version === '') {
            throw new RuntimeException('WordPress version is not numeric; configure wp-version explicitly.');
        }
        if (version_compare($version, '4.8.0', '<')) {
            throw new RuntimeException('WordPress 4.8 or newer is required.');
        }

        return $version;
    }
}
