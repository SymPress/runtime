<?php

declare(strict_types=1);

$root = $argv[1];
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['SERVER_PORT'] = 8080;
require $root . '/public/index.php';
$names = ['WP_ENV', 'WP_ENVIRONMENT_TYPE', 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES', 'WP_LOCAL_DEV', 'WP_DISABLE_FATAL_ERROR_HANDLER', 'WP_DEVELOPMENT_MODE', 'AUTOMATIC_UPDATER_DISABLED', 'DISALLOW_FILE_MODS', 'DISALLOW_FILE_EDIT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE', 'WP_HOME', 'WP_SITEURL', 'WP_CONTENT_URL', 'WP_CONTENT_DIR', 'ABSPATH', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
$report = [];
foreach ($names as $name) {
    $value = defined($name) ? constant($name) : null;
    if (in_array($name, ['ABSPATH', 'WP_CONTENT_DIR'], true) && is_string($value)) {
        $value = str_replace($root, '<project>', $value);
    }
    $report[$name] = ['defined' => defined($name), 'type' => get_debug_type($value), 'value' => $value];
}
$report['table_prefix'] = $GLOBALS['table_prefix'] ?? null;
$report['boots'] = $GLOBALS['boot_count'] ?? 0;
$report['composer_loaded'] = class_exists('Composer\\Autoload\\ClassLoader', false);
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
