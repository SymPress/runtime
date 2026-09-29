<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Package\PackageInterface;
use InvalidArgumentException;

/** Mirrors the standard WordPress installer path rules without activating plugins. */
final class PackageDestination
{
    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $installedNames
     */
    public function forPackage(PackageInterface $package, array $manifest, array $installedNames): ?string
    {
        $extra = $manifest['extra'] ?? [];
        if (!is_array($extra)) {
            throw new InvalidArgumentException('Composer extra must be an object.');
        }
        if ($package->getType() === 'wordpress-core') {
            return in_array('johnpbloch/wordpress-core-installer', $installedNames, true)
                ? $this->path($extra['wordpress-install-dir'] ?? 'wordpress') : null;
        }
        $defaults = [
            'wordpress-plugin' => 'wp-content/plugins/{$name}/',
            'wordpress-muplugin' => 'wp-content/mu-plugins/{$name}/',
            'wordpress-theme' => 'wp-content/themes/{$name}/',
            'wordpress-dropin' => 'wp-content/{$name}/',
        ];
        if (!isset($defaults[$package->getType()]) || !in_array('composer/installers', $installedNames, true)) {
            return null;
        }
        $disabled = $extra['installer-disable'] ?? false;
        foreach (is_array($disabled) ? $disabled : [$disabled] as $item) {
            if (in_array($item, [true, 'all', '*', 'wordpress'], true)) {
                return null;
            }
        }
        $name = $package->getPrettyName();
        [$vendor, $leaf] = str_contains($name, '/') ? explode('/', $name, 2) : ['', $name];
        $template = $defaults[$package->getType()];
        $custom = $extra['installer-paths'] ?? [];
        if (!is_array($custom)) {
            throw new InvalidArgumentException('installer-paths must be an object.');
        }
        foreach ($custom as $path => $selectors) {
            foreach (is_array($selectors) ? $selectors : [$selectors] as $selector) {
                if (in_array($selector, [$name, 'vendor:' . $vendor, 'type:' . $package->getType()], true)) {
                    $template = $this->path($path);
                    break 2;
                }
            }
        }
        $leaf = $package->getExtra()['installer-name'] ?? $leaf;

        return strtr($template, ['{$name}' => $this->path($leaf), '{$vendor}' => $vendor, '{$type}' => $package->getType()]);
    }

    private function path(mixed $path): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Installer paths must be non-empty strings.');
        }

        return $path;
    }
}
