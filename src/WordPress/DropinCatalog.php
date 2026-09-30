<?php

declare(strict_types=1);

namespace SymPress\Runtime\WordPress;

/** @internal */
final class DropinCatalog
{
    /** Union of WP Starter and WordPress 7.1 _get_dropins(), including multisite entries. */
    public const array FILES = [
        'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php',
        'object-cache.php', 'php-error.php', 'fatal-error-handler.php',
        'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php',
    ];
}
