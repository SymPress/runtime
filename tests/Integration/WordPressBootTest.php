<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SymPress\Runtime\Database\DbHost;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;
use mysqli;

final class WordPressBootTest extends TemporaryProject
{
    #[Group('wordpress')]
    #[Group('database')]
    public function testNativeMuPluginsLoadThroughRealWordPressAndAppearInAdminMetadata(): void
    {
        $core = getenv('RUNTIME_TEST_WORDPRESS_DIR');
        $host = getenv('RUNTIME_TEST_DB_HOST');
        if (!$core || !is_file($core . '/wp-settings.php') || !$host) {
            self::markTestSkipped('Set WordPress and database fixtures for the native MU integration.');
        }
        $endpoint = DbHost::parse($host);
        $user = getenv('RUNTIME_TEST_DB_USER') ?: 'root';
        $password = getenv('RUNTIME_TEST_DB_PASSWORD') ?: '';
        $database = 'runtime_mu_' . bin2hex(random_bytes(8));
        $connection = new mysqli($endpoint->host, $user, $password, null, $endpoint->port, $endpoint->socket);
        try {
            $connection->query('CREATE DATABASE `' . $database . '`');
            self::assertTrue(symlink($core, $this->root . '/wp'));
            $this->write('composer.json', '{"extra":{"wordpress-install-dir":"wp","wordpress-content-dir":"content","sympress-runtime":{"require-wp":false,"db-check":false,"cache-env":false,"compatibility":false}}}');
            $this->write('content/mu-plugins/z-last/main.php', "<?php\n/* Plugin Name: Last */\n" . '$GLOBALS["fixture_boots"][] = "last"; $file = "changed";');
            $this->write('content/mu-plugins/a-first/main.php', "<?php\n/* Plugin Name: First */\n" . '$GLOBALS["fixture_boots"][] = "first"; $fixtureGlobalDb = $wpdb instanceof wpdb;');
            $package = dirname(__DIR__, 2);
            $setup = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', '-n', 'wpconfig', 'muloader'], $this->root, ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]);
            $setup->mustRun();
            $loader = $this->root . '/content/mu-plugins/sympress-runtime-mu-loader.php';
            rename($loader, $this->root . '/linked-loader.php');
            self::assertTrue(symlink($this->root . '/linked-loader.php', $loader));
            $this->write('probe.php', <<<'PHP'
<?php
define('WP_INSTALLING', true);
require __DIR__ . '/wp/wp-includes/plugin.php';
add_action('mu_plugin_loaded', static function ($file) {
    $GLOBALS['fixture_hooks'][] = basename(dirname($file));
});
require __DIR__ . '/wp-config.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-plugins-list-table.php';
$rows = apply_filters('plugins_list', ['mustuse' => get_mu_plugins()]);
echo json_encode([$GLOBALS['fixture_boots'], $GLOBALS['fixture_hooks'], array_keys($rows['mustuse']), $rows['mustuse']['a-first/main.php']['Name'], $fixtureGlobalDb]);
PHP);
            $probe = new Process([PHP_BINARY, $this->root . '/probe.php'], $this->root, [
                'DB_HOST' => $host,
            'DB_USER' => $user,
            'DB_PASSWORD' => $password,
            'DB_NAME' => $database,
                'WP_HOME' => 'https://fixture.invalid',
            'WP_SITEURL' => 'https://fixture.invalid',
                'WP_ENVIRONMENT_TYPE' => 'production',
            'SHORTINIT' => false,
            ]);
            $probe->mustRun();
            self::assertSame('', $probe->getErrorOutput());
            $result = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['first', 'last'], $result[0]);
            self::assertSame(['a-first', 'z-last', 'mu-plugins'], $result[1]);
            self::assertSame(['a-first/main.php', 'z-last/main.php', 'sympress-runtime-mu-loader.php'], $result[2]);
            self::assertSame('First', $result[3]);
            self::assertTrue($result[4]);
        } finally {
            $connection->query('DROP DATABASE IF EXISTS `' . $database . '`');
            $connection->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function bootModes(): iterable
    {
        yield 'environment' => [false];
        yield 'read-only build dump' => [true];
    }

    #[DataProvider('bootModes')]
    #[Group('wordpress')]
    #[Group('database')]
    #[Group('PAR-WP-002')]
    public function testRealWordPressShortInitConnectsToAnIsolatedDatabaseWithoutComposer(bool $readOnlyDump): void
    {
        $core = getenv('RUNTIME_TEST_WORDPRESS_DIR');
        $host = getenv('RUNTIME_TEST_DB_HOST');
        if (!$core || !is_file($core . '/wp-settings.php') || !$host) {
            self::markTestSkipped('Set RUNTIME_TEST_WORDPRESS_DIR and a dedicated RUNTIME_TEST_DB_HOST for the real WordPress smoke.');
        }
        if ($readOnlyDump && (!function_exists('posix_geteuid') || !function_exists('posix_setuid'))) {
            self::markTestSkipped('Read-only WordPress verification requires POSIX process identities.');
        }
        $endpoint = DbHost::parse($host);
        $user = getenv('RUNTIME_TEST_DB_USER') ?: 'root';
        $password = getenv('RUNTIME_TEST_DB_PASSWORD') ?: '';
        $database = 'runtime_wp_' . bin2hex(random_bytes(8));
        $connection = new mysqli($endpoint->host, $user, $password, null, $endpoint->port, $endpoint->socket);
        try {
            $connection->query('CREATE DATABASE `' . $database . '`');
            self::assertTrue(symlink($core, $this->root . '/wp'));
            $this->write('content/keep', 'isolated content');
            $this->write('composer.json', '{"extra":{"wordpress-install-dir":"wp","wordpress-content-dir":"content","sympress-runtime":{"require-wp":false,"db-check":false,"cache-env":false}}}');
            $package = dirname(__DIR__, 2);
            $generate = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', 'wpconfig', '-n'], $this->root, ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]);
            $generate->run();
            self::assertSame(0, $generate->getExitCode(), $generate->getErrorOutput());
            self::assertFileDoesNotExist($this->root . '/vendor/autoload.php');
            $this->write('probe.php', <<<'PHP'
<?php
if ($argv[1] === 'readonly' && posix_geteuid() === 0 && !posix_setuid(65534)) { throw new RuntimeException('Cannot drop test identity.'); }
require __DIR__ . '/wp-config.php';
echo json_encode([SHORTINIT, $wpdb instanceof wpdb, (string) $wpdb->get_var('SELECT 1'), WP_ENVIRONMENT_TYPE, class_exists('Composer\Autoload\ClassLoader', false), defined('WPINC'), realpath(WP_CONTENT_DIR) === __DIR__ . '/content', is_writable(__DIR__)]);
PHP);
            $environment = [
                'DB_HOST' => $host,
            'DB_USER' => $user,
            'DB_PASSWORD' => $password,
            'DB_NAME' => $database,
                'SHORTINIT' => 'true',
            'WP_ENVIRONMENT_TYPE' => 'production',
            'WP_HOME' => 'https://fixture.invalid',
                'WP_ENV' => false,
            'WORDPRESS_ENV' => false,
            'WP_DEBUG' => 'false',
            'WP_CONTENT_DIR' => false,
                'SYMPRESS_RUNTIME_ENV_LOADED' => false,
            'WPSTARTER_ENV_LOADED' => false,
            'SYMFONY_DOTENV_VARS' => false,
            ];
            $hashes = [];
            if ($readOnlyDump) {
                $dump = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', '-n', 'dump-env', 'production'], $this->root, $environment + ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]);
                $dump->mustRun();
                $this->write('.env', 'INVALID="must not be parsed');
                $environment = array_fill_keys(array_keys($environment), false);
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                    if ($file->isLink()) {
                        continue;
                    }
                    if ($file->isFile()) {
                        $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
                    }
                    chmod($file->getPathname(), $file->isDir() ? 0555 : 0444);
                }
                chmod($this->root, 0555);
            }
            $boot = new Process([PHP_BINARY, $this->root . '/probe.php', $readOnlyDump ? 'readonly' : 'environment'], $this->root, $environment);
            $boot->run();
            self::assertSame(0, $boot->getExitCode(), $boot->getErrorOutput());
            self::assertSame([true, true, '1', 'production', false, true, true, !$readOnlyDump], json_decode($boot->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame('', $boot->getErrorOutput());
            foreach ($hashes as $file => $hash) {
                self::assertSame($hash, hash_file('sha256', $file));
            }
        } finally {
            chmod($this->root, 0755);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file->isLink() || !$file->isDir()) {
                    continue;
                }
                chmod($file->getPathname(), 0755);
            }
            $connection->query('DROP DATABASE IF EXISTS `' . $database . '`');
            $connection->close();
        }
    }
}
