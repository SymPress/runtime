<?php

declare(strict_types=1);

// This fixture must also run on the pinned oracle's PHP 8.2 runtime.
require $argv[1];
$input = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$names = $input['names'];
foreach (array_unique([...$names, 'WP_ENV', 'WORDPRESS_ENV', 'WP_ENVIRONMENT_TYPE', 'WPSTARTER_ENV_LOADED', 'SYMPRESS_RUNTIME_ENV_LOADED', 'SYMFONY_DOTENV_VARS']) as $name) {
    unset($_ENV[$name], $_SERVER[$name]);
    putenv($name);
}
foreach ($input['actual'] ?? [] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name . '=' . $value);
}
foreach ($input['predefined'] ?? [] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
$reader = $argv[3] === 'oracle'
    ? new WeCodeMore\WpStarter\Env\WordPressEnvBridge()
    : new SymPress\Runtime\Env\EnvReader(profile: $argv[3]);
$reader->load('.env', dirname($argv[2]));
$environment = $reader->determineEnvType();
$reader->loadAppended('.env.' . $environment, dirname($argv[2]));
$reader->setupConstants();
$result = [];
foreach ($names as $name) {
    $value = $reader->read($name);
    $constant = defined($name) ? constant($name) : null;
    $result[$name] = [
        'value' => $value,
        'type' => get_debug_type($value),
        'defined' => defined($name),
        'constant' => $constant,
        'constant_type' => get_debug_type($constant),
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
