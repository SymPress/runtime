<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Composer;

/** @internal */
final class AutomaticSetup
{
    public function required(Composer $composer, string $root): bool
    {
        $extra = $composer->getPackage()->getExtra();
        foreach (['sympress-runtime', 'wpstarter', 'wordpress-install-dir', 'wordpress-content-dir'] as $key) {
            if (array_key_exists($key, $extra)) {
                return true;
            }
        }
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            if (
                in_array($package->getType(), ['wordpress-core', 'sympress-runtime-extension', 'wpstarter-extension'], true)
                || array_key_exists('sympress-runtime', $package->getExtra())
            ) {
                return true;
            }
        }
        // Presence is enough: malformed config and damaged existing sites must still be validated.
        $markers = [
            'sympress-runtime.json', 'wpstarter.json', 'sympress-runtime-autoload.php', 'wpstarter-autoload.php',
            'wp-config.php', 'wordpress', 'wp-content', 'wp-includes', 'var/runtime', 'sympress-runtime.lock',
        ];
        foreach ($markers as $path) {
            if (file_exists($root . '/' . $path) || is_link($root . '/' . $path)) {
                return true;
            }
        }

        return false;
    }
}
