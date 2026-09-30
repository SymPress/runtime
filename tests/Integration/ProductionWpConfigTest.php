<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Database\DbHost;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;
use mysqli;

final class ProductionWpConfigTest extends TemporaryProject
{
    /** @param array<string, mixed> $settings */
    private function fixture(array $settings, string $environment = 'production', string $extraEnv = ''): void
    {
        $this->write('composer.json', json_encode([
        'extra' => [
            'wordpress-install-dir' => 'public/wp',
            'wordpress-content-dir' => 'public/content',
            'sympress-runtime' => array_replace(['require-wp' => false, 'db-check' => false, 'cache-env' => false, 'compatibility' => false], $settings),
        ],
        ], JSON_THROW_ON_ERROR));
        $this->write('.env', 'WP_ENVIRONMENT_TYPE=' . $environment . "\nDB_NAME=fixture\nDB_USER=fixture\nWP_HOME=https://fixture.invalid\nRTV_VALUE=initial\n" . $extraEnv);
        $this->write('public/wp/wp-settings.php', '<?php $GLOBALS["settings_loaded"] = true;');
        $this->write('public/wp/wp-includes/plugin.php', <<<'PHP'
<?php
function add_filter($name, $callback, $priority = 10, $accepted = 1) { $GLOBALS['filters'][$name][$priority][] = [$callback, $accepted]; }
function add_action($name, $callback, $priority = 10, $accepted = 1) { add_filter($name, $callback, $priority, $accepted); }
function has_filter($name) { return !empty($GLOBALS['filters'][$name]); }
function apply_filters($name, $value, ...$args) { $items = $GLOBALS['filters'][$name] ?? []; ksort($items); foreach ($items as $callbacks) { foreach ($callbacks as [$callback, $accepted]) { $value = $callback(...array_slice([$value, ...$args], 0, $accepted)); } } return $value; }
PHP);
        $package = dirname(__DIR__, 2);
        $generate = new Process([PHP_BINARY, $package . '/bin/runtime', '-n', 'wpconfig'], $this->root, array_replace($this->environment(), ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]));
        $generate->run();
        self::assertSame(0, $generate->getExitCode(), $generate->getOutput() . $generate->getErrorOutput());
    }

    /** @return array<string, false> */
    private function environment(): array
    {
        return array_fill_keys(['WP_ENV', 'WP_ENVIRONMENT_TYPE', 'WORDPRESS_ENV', 'SYMFONY_DOTENV_VARS', 'WPSTARTER_ENV_LOADED', 'SYMPRESS_RUNTIME_ENV_LOADED', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'WP_HOME', 'WP_DEBUG', 'WP_DEVELOPMENT_MODE', 'DISALLOW_FILE_MODS', 'AUTOMATIC_UPDATER_DISABLED', 'WP_AUTO_UPDATE_CORE', 'FORCE_SSL_ADMIN'], false);
    }

    /** @return array<array-key, mixed> */
    private function boot(string $report, string $before = ''): array
    {
        $this->write('probe.php', '<?php ' . $before . '; require __DIR__ . "/public/wp-config.php"; ' . $report);
        $process = new Process([PHP_BINARY, $this->root . '/probe.php'], $this->root, $this->environment());
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    /** @return iterable<string, array{bool|string, string, bool}> */
    public static function managedModes(): iterable
    {
        yield 'true protects local' => [true, 'local', true];
        yield 'false disables production defaults' => [false, 'production', false];
        yield 'auto protects production' => ['auto', 'production', true];
        yield 'auto protects staging' => ['auto', 'staging', true];
        yield 'auto leaves development unchanged' => ['auto', 'development', false];
        yield 'auto leaves local unchanged' => ['auto', 'local', false];
    }

    #[DataProvider('managedModes')]
    public function testComposerManagedModesSetGeneratedBootstrapDefaults(bool|string $mode, string $environment, bool $protected): void
    {
        $this->fixture(['composer-managed' => $mode], $environment);
        $result = $this->boot('echo json_encode([defined("DISALLOW_FILE_MODS") ? DISALLOW_FILE_MODS : null, defined("AUTOMATIC_UPDATER_DISABLED") ? AUTOMATIC_UPDATER_DISABLED : null, defined("WP_AUTO_UPDATE_CORE") ? WP_AUTO_UPDATE_CORE : null, defined("FORCE_SSL_ADMIN") ? FORCE_SSL_ADMIN : null, $GLOBALS["settings_loaded"]]);');
        self::assertSame([$protected ? true : null, $protected ? true : null, $protected ? false : null, $environment === 'production' ? true : null, true], $result);
    }

    public function testExplicitEnvironmentAndPredefinedConstantsOverrideManagedDefaults(): void
    {
        $this->fixture(['composer-managed' => true], extraEnv: "DISALLOW_FILE_MODS=false\nAUTOMATIC_UPDATER_DISABLED=false\nWP_AUTO_UPDATE_CORE=true\nFORCE_SSL_ADMIN=false\n");
        self::assertSame([false, false, true, false], $this->boot('echo json_encode([DISALLOW_FILE_MODS, AUTOMATIC_UPDATER_DISABLED, WP_AUTO_UPDATE_CORE, FORCE_SSL_ADMIN]);'));
        self::assertSame([false, false, true, false], $this->boot('echo json_encode([DISALLOW_FILE_MODS, AUTOMATIC_UPDATER_DISABLED, WP_AUTO_UPDATE_CORE, FORCE_SSL_ADMIN]);', 'define("DISALLOW_FILE_MODS", false); define("AUTOMATIC_UPDATER_DISABLED", false); define("WP_AUTO_UPDATE_CORE", true); define("FORCE_SSL_ADMIN", false);'));
    }

    public function testComposerManagedSectionCanBeRemovedThroughEditor(): void
    {
        $this->fixture(['composer-managed' => true]);
        $paths = new Paths($this->root, wp: 'public/wp', content: 'public/content');
        $editor = new WpConfigSectionEditor($paths, new Config([], new Validator($paths)), new Filesystem());
        self::assertStringContainsString('DISALLOW_FILE_MODS', $editor->sectionContent('COMPOSER_MANAGED'));
        $editor->delete('COMPOSER_MANAGED');
        self::assertSame('', $editor->sectionContent('COMPOSER_MANAGED'));
        self::assertSame([false, false, false, false, true], $this->boot('echo json_encode([defined("DISALLOW_FILE_MODS"), defined("AUTOMATIC_UPDATER_DISABLED"), defined("WP_AUTO_UPDATE_CORE"), defined("FORCE_SSL_ADMIN"), $GLOBALS["settings_loaded"]]);'));
    }

    public function testBundledProductionBootCreatesGroupReadableCacheAndWarmBootAvoidsParser(): void
    {
        $this->fixture(['composer-managed' => 'auto', 'bundle-bootstrap' => true, 'generated-file-mode' => '0640', 'cache-env' => 'auto']);
        foreach (['wp-config.php', 'public/wp-config.php'] as $file) {
            self::assertSame(0640, fileperms($this->root . '/' . $file) & 0777);
        }
        $report = <<<'PHP'
$debug = apply_filters('debug_information', []);
$files = get_included_files();
$parsed = count(array_filter($files, static fn (string $file): bool => str_ends_with($file, '/Dotenv/Dotenv.php'))) > 0;
$loadedEnvironmentFiles = count(array_filter($files, static fn (string $file): bool => str_contains($file, '/Env/')));
echo json_encode([$debug['sympress-runtime']['fields']['cached-env']['debug'], sympress_runtime_getenv('RTV_VALUE'), $parsed, $loadedEnvironmentFiles, DISALLOW_FILE_MODS, FORCE_SSL_ADMIN, class_exists('Composer\Autoload\ClassLoader', false), $GLOBALS['settings_loaded']]);
PHP;
        self::assertSame([false, 'initial', true, 0, true, true, false, true], $this->boot($report));
        self::assertFileExists($this->root . '/.env.cached.php');
        self::assertSame(0640, fileperms($this->root . '/.env.cached.php') & 0777);
        self::assertSame([true, 'initial', false, 0, true, true, false, true], $this->boot($report));
        $this->write('.env.production.local', "RTV_VALUE=after-deployment\n");
        self::assertSame([false, 'after-deployment', true, 0, true, true, false, true], $this->boot($report));
        self::assertSame([true, 'after-deployment', false, 0, true, true, false, true], $this->boot($report));
    }

    #[Group('wordpress')]
    #[Group('database')]
    public function testRealWordPressShortInitBootsColdAndWarmFromBundledProductionCache(): void
    {
        $core = getenv('RUNTIME_TEST_WORDPRESS_DIR');
        $host = getenv('RUNTIME_TEST_DB_HOST');
        if (!$core || !is_file($core . '/wp-settings.php') || !$host) {
            self::markTestSkipped('Set the WordPress and isolated database integration fixtures.');
        }
        $endpoint = DbHost::parse($host);
        $user = getenv('RUNTIME_TEST_DB_USER') ?: 'root';
        $password = getenv('RUNTIME_TEST_DB_PASSWORD') ?: '';
        $database = 'runtime_production_' . bin2hex(random_bytes(8));
        $connection = new mysqli($endpoint->host, $user, $password, null, $endpoint->port, $endpoint->socket);
        try {
            $connection->query('CREATE DATABASE `' . $database . '`');
            self::assertTrue(symlink($core, $this->root . '/wp'));
            $this->write('content/keep', 'fixture');
            $this->write('composer.json', json_encode([
            'extra' => [
                'wordpress-install-dir' => 'wp',
                'wordpress-content-dir' => 'content',
                'sympress-runtime' => ['require-wp' => false, 'db-check' => false, 'cache-env' => 'auto', 'bundle-bootstrap' => true, 'composer-managed' => 'auto', 'generated-file-mode' => '0640', 'compatibility' => false],
            ],
            ], JSON_THROW_ON_ERROR));
            $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nRTV_VALUE=real-wordpress\n");
            $package = dirname(__DIR__, 2);
            $generate = new Process([PHP_BINARY, $package . '/bin/runtime', '-n', 'wpconfig'], $this->root, array_replace($this->environment(), ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]));
            $generate->mustRun();
            $this->write('real-probe.php', <<<'PHP'
<?php
define('SHORTINIT', true);
require __DIR__ . '/wp-config.php';
$debug = apply_filters('debug_information', []);
$parsed = count(array_filter(get_included_files(), static fn (string $file): bool => str_ends_with($file, '/Dotenv/Dotenv.php'))) > 0;
echo json_encode([$debug['sympress-runtime']['fields']['cached-env']['debug'], $parsed, (string) $wpdb->get_var('SELECT 1'), WP_ENVIRONMENT_TYPE, DISALLOW_FILE_MODS, FORCE_SSL_ADMIN, class_exists('Composer\Autoload\ClassLoader', false), sympress_runtime_getenv('RTV_VALUE')]);
PHP);
            $environment = array_replace($this->environment(), ['DB_HOST' => $host, 'DB_USER' => $user, 'DB_PASSWORD' => $password, 'DB_NAME' => $database, 'WP_HOME' => 'https://fixture.invalid']);
            foreach ([false, true] as $warm) {
                $probe = new Process([PHP_BINARY, $this->root . '/real-probe.php'], $this->root, $environment);
                $probe->run();
                self::assertSame(0, $probe->getExitCode(), $probe->getErrorOutput());
                self::assertSame([$warm, !$warm, '1', 'production', true, true, false, 'real-wordpress'], json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR));
                self::assertFileExists($this->root . '/.env.cached.php');
                self::assertSame(0640, fileperms($this->root . '/.env.cached.php') & 0777);
            }
        } finally {
            $connection->query('DROP DATABASE IF EXISTS `' . $database . '`');
            $connection->close();
        }
    }
}
