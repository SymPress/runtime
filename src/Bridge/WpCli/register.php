<?php

declare(strict_types=1);

namespace SymPress\Runtime\Bridge\WpCli;

// Loaded by WP-CLI's generated project config, without loading project autoload files.
return static function (string $root): void {
    if (!class_exists('WP_CLI', false)) {
        return;
    }
    require_once __DIR__ . '/Command.php';
    \WP_CLI::add_command('runtime', new Command(dirname(__DIR__, 3) . '/bin/runtime', $root), [
        'shortdesc' => 'Run SymPress Runtime setup and diagnostics.',
        'when' => 'before_wp_load',
        'synopsis' => [
            ['type' => 'positional', 'name' => 'arguments', 'optional' => true, 'repeating' => true],
            ['type' => 'generic', 'name' => 'options', 'optional' => true],
        ],
    ]);
};
