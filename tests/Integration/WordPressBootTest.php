<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Database\DbHost;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;
use mysqli;

final class WordPressBootTest extends TemporaryProject
{
    #[Group('wordpress')]
    #[Group('database')]
    #[Group('PAR-WP-002')]
    public function testRealWordPressShortInitConnectsToAnIsolatedDatabaseWithoutComposer(): void
    {
        $core = getenv('RUNTIME_TEST_WORDPRESS_DIR');
        $host = getenv('RUNTIME_TEST_DB_HOST');
        if (!$core || !is_file($core . '/wp-settings.php') || !$host) {
            self::markTestSkipped('Set RUNTIME_TEST_WORDPRESS_DIR and a dedicated RUNTIME_TEST_DB_HOST for the real WordPress smoke.');
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
require __DIR__ . '/wp-config.php';
echo json_encode([SHORTINIT, $wpdb instanceof wpdb, (string) $wpdb->get_var('SELECT 1'), WP_ENVIRONMENT_TYPE, class_exists('Composer\Autoload\ClassLoader', false), defined('WPINC'), realpath(WP_CONTENT_DIR) === __DIR__ . '/content']);
PHP);
            $boot = new Process([PHP_BINARY, $this->root . '/probe.php'], $this->root, [
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
            ]);
            $boot->run();
            self::assertSame(0, $boot->getExitCode(), $boot->getErrorOutput());
            self::assertSame([true, true, '1', 'production', false, true, true], json_decode($boot->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame('', $boot->getErrorOutput());
        } finally {
            $connection->query('DROP DATABASE IF EXISTS `' . $database . '`');
            $connection->close();
        }
    }
}
