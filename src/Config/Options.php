<?php

declare(strict_types=1);

namespace SymPress\Runtime\Config;

final class Options
{
    public const array DEFAULTS = [
        'autoload' => 'sympress-runtime-autoload.php',
        'cache-env' => 'auto',
        'generated-file-mode' => '0600',
        'composer-managed' => 'auto',
        'required-env' => [],
        'download-lock' => true,
        'update-lock' => false,
        'wp-cli-version' => null,
        'wp-cli-sha256' => null,
        'bundle-bootstrap' => false,
        'check-vcs-ignore' => true,
        'command-steps' => null,
        'composer-updated-packages' => [],
        'content-dev-dir' => 'content-dev',
        'content-dev-op' => 'auto',
        'create-vcs-ignore-file' => true,
        'custom-steps' => null,
        'steps' => null,
        'db-check' => true,
        'dropins' => null,
        'dropins-op' => 'auto',
        'early-hook-file' => '',
        'env-bootstrap-dir' => null,
        'env-dir' => null,
        'env-example' => true,
        'env-file' => '.env',
        'install-wp-cli' => true,
        'is-composer-install' => null,
        'is-composer-update' => null,
        'is-wpstarter-command' => null,
        'is-wpstarter-selected-command' => null,
        'is-runtime-command' => null,
        'is-runtime-selected-command' => null,
        'move-content' => false,
        'prevent-overwrite' => null,
        'register-theme-folder' => false,
        'require-wp' => true,
        'scripts' => [],
        'skip-db-check' => false,
        'skip-steps' => null,
        'templates-dir' => null,
        'unknown-dropins' => false,
        'wp-cli-commands' => [],
        'wp-cli-files' => [],
        'wp-config-autoload' => false,
        'wp-version' => null,
        'compatibility' => true,
        'compatibility-profile' => 'native',
        'env-local-overrides' => true,
        'allow-insecure-downloads' => false,
        'download-checksums' => [],
        'download-max-bytes' => 16777216,
        'require-download-checksums' => false,
        'kernel-boot' => false,
        'kernel-build-id' => null,
    ];

    public const array INTERNAL = [
        'composer-updated-packages', 'is-composer-install', 'is-composer-update',
        'is-wpstarter-command', 'is-wpstarter-selected-command',
        'is-runtime-command', 'is-runtime-selected-command', 'wp-config-php-path', 'update-lock',
    ];

    /** @return array<string, mixed> */
    public static function defaults(string $profile): array
    {
        $defaults = self::DEFAULTS;
        $defaults['compatibility-profile'] = $profile;
        if ($profile !== 'native') {
            $defaults['cache-env'] = true;
            $defaults['composer-managed'] = false;
            $defaults['download-lock'] = false;
            $defaults['autoload'] = 'wpstarter-autoload.php';
            $defaults['env-local-overrides'] = false;
            $defaults['wp-config-autoload'] = true;
        }
        if ($profile === 'release-3.0.1') {
            $defaults['content-dev-op'] = 'symlink';
            $defaults['skip-steps'] = [];
            $defaults['scripts'] = null;
            $defaults['wp-cli-commands'] = null;
            $defaults['wp-cli-files'] = null;
        }

        return $defaults;
    }
}
