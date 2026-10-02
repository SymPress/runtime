<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Privileged;

use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

/** Run explicitly as root; ordinary test discovery does not include tools/tests. */
// phpcs:ignore SymPress.Namespaces.Psr4.InvalidPSR4 -- Privileged fixture deliberately lives outside default test discovery.
final class SharedGroupTest extends TemporaryProject
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

    public function testSharedGroupCanBootstrapPrivateArtifactsWhileOtherIdentityIsDenied(): void
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_setgid') || !function_exists('posix_setuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Shared-group bootstrap verification requires root with POSIX identity switching.');
        }
        self::assertTrue(chgrp($this->root, 65534));
        self::assertTrue(chmod($this->root, 02750));
        $this->fixture(['composer-managed' => 'auto', 'bundle-bootstrap' => true, 'generated-file-mode' => '0640', 'cache-env' => 'auto']);
        self::assertSame(['initial'], $this->boot('echo json_encode([sympress_runtime_getenv("RTV_VALUE")]);'));
        $payloads = glob($this->root . '/var/runtime/*/bootstrap.php');
        self::assertIsArray($payloads);
        self::assertCount(1, $payloads);
        foreach (['wp-config.php', 'public/wp-config.php', '.env.cached.php'] as $file) {
            self::assertSame(0640, fileperms($this->root . '/' . $file) & 0777);
            self::assertSame(65534, filegroup($this->root . '/' . $file));
        }
        $this->write('identity-probe.php', <<<'PHP'
<?php
$identity = (int) $argv[1];
if (!posix_setgid($identity) || !posix_setuid($identity)) { throw new RuntimeException('Cannot switch probe identity.'); }
clearstatcache();
$readable = [is_readable(__DIR__ . '/wp-config.php'), is_readable(__DIR__ . '/.env.cached.php'), is_readable($argv[2])];
if ($identity !== 65534) { echo json_encode($readable); return; }
require __DIR__ . '/public/wp-config.php';
$debug = apply_filters('debug_information', []);
echo json_encode([$readable, $debug['sympress-runtime']['fields']['cached-env']['debug'], sympress_runtime_getenv('RTV_VALUE'), DISALLOW_FILE_MODS, $GLOBALS['settings_loaded']]);
PHP);
        $group = new Process([PHP_BINARY, $this->root . '/identity-probe.php', '65534', $payloads[0]], $this->root, $this->environment());
        $group->run();
        self::assertSame(0, $group->getExitCode(), $group->getErrorOutput());
        self::assertSame([[true, true, true], true, 'initial', true, true], json_decode($group->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $other = new Process([PHP_BINARY, $this->root . '/identity-probe.php', '65533', $payloads[0]], $this->root, $this->environment());
        $other->run();
        self::assertSame(0, $other->getExitCode(), $other->getErrorOutput());
        self::assertSame([false, false, false], json_decode($other->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
    public function testAutomaticCacheUsesReadOnlyExistingFilesAndExplicitNativeCacheFailsBeforeShutdown(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Read-only bootstrap probe needs POSIX identity switching.');
        }
        chgrp($this->root, 65534);
        chmod($this->root, 02750);
        foreach (['auto', true] as $mode) {
            $this->fixture(['generated-file-mode' => '0640', 'cache-env' => $mode, 'bundle-bootstrap' => true]);
            $this->write('readonly-probe.php', <<<'PHP'
<?php
if (!posix_setgid(65534) || !posix_setuid(65534)) { throw new RuntimeException('Cannot switch probe identity.'); }
clearstatcache();
try { require __DIR__ . '/public/wp-config.php'; echo 'booted'; }
catch (RuntimeException $error) { echo str_contains($error->getMessage(), 'Explicit cache-env') ? 'explicit-denied' : $error->getMessage(); }
PHP);
            $process = new Process([PHP_BINARY, $this->root . '/readonly-probe.php'], $this->root, $this->environment());
            $process->mustRun();
            self::assertSame($mode === 'auto' ? 'booted' : 'explicit-denied', $process->getOutput());
            self::assertFileDoesNotExist($this->root . '/.env.cached.php');
        }
    }

}
