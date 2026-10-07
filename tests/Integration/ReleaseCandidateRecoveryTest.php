<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ReleaseCandidateRecoveryTest extends TemporaryProject
{
    private function fixture(bool $bundled = false, bool $cache = false): void
    {
        $this->manifest($bundled, $cache);
        $this->write('.env', "WP_ENVIRONMENT_TYPE=stage\nDB_NAME=fixture\nDB_USER=fixture\nWP_HOME=https://fixture.invalid\nRTV_DEPLOYMENT=base\n");
        $this->write('.env.stage', "RTV_DEPLOYMENT=raw-stage\n");
        $this->write('.env.staging', "RTV_DEPLOYMENT=wrong-canonical-file\n");
        $this->write('.env.production', "RTV_DEPLOYMENT=production\n");
        $this->write('public/wp/wp-settings.php', '<?php $GLOBALS["rc_settings_loaded"] = true;');
        $this->write('public/wp/wp-includes/plugin.php', <<<'PHP'
<?php
function add_filter($name, $callback, $priority = 10, $accepted = 1) { $GLOBALS['filters'][$name][$priority][] = [$callback, $accepted]; }
function add_action($name, $callback, $priority = 10, $accepted = 1) { add_filter($name, $callback, $priority, $accepted); }
function has_filter($name) { return !empty($GLOBALS['filters'][$name]); }
function apply_filters($name, $value, ...$args) { $items = $GLOBALS['filters'][$name] ?? []; ksort($items); foreach ($items as $callbacks) { foreach ($callbacks as [$callback, $accepted]) { $value = $callback(...array_slice([$value, ...$args], 0, $accepted)); } } return $value; }
PHP);
    }

    private function manifest(bool $bundled, bool $cache): void
    {
        $this->write('composer.json', json_encode([
            'extra' => [
                'wordpress-install-dir' => 'public/wp',
                'wordpress-content-dir' => 'public/content',
                'sympress-runtime' => ['require-wp' => false, 'db-check' => false, 'compatibility' => false, 'bundle-bootstrap' => $bundled, 'cache-env' => $cache],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, false> */
    private function environment(): array
    {
        return array_fill_keys(['WP_ENV', 'WP_ENVIRONMENT_TYPE', 'WORDPRESS_ENV', 'SYMFONY_DOTENV_VARS', 'WPSTARTER_ENV_LOADED', 'SYMPRESS_RUNTIME_ENV_LOADED', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'WP_HOME', 'WP_DEBUG', 'WP_DEVELOPMENT_MODE', 'AUTH_KEY', 'RTV_DEPLOYMENT', 'COMPOSER'], false);
    }

    /** @param list<string> $arguments */
    private function runtime(array $arguments): Process
    {
        $package = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $package . '/bin/runtime', '--no-interaction', ...$arguments], $this->root, array_replace($this->environment(), ['COMPOSER_VENDOR_DIR' => $package . '/vendor']));
        $process->mustRun();
        return $process;
    }

    private function request(?string $environment = null): Process
    {
        $code = 'require "public/wp-config.php"; $debug = apply_filters("debug_information", []); echo json_encode([WP_ENV, WP_ENVIRONMENT_TYPE, sympress_runtime_getenv("RTV_DEPLOYMENT"), $debug["sympress-runtime"]["fields"]["runtime-payload"]["value"], AUTH_KEY, $GLOBALS["rc_settings_loaded"], class_exists("Composer\\\\Autoload\\\\ClassLoader", false)]);';
        $variables = $this->environment();
        if ($environment !== null) {
            $variables['WP_ENVIRONMENT_TYPE'] = $environment;
        }
        $process = new Process([PHP_BINARY, '-r', $code], $this->root, $variables);
        $process->run();
        return $process;
    }

    /** @return array<array-key, mixed> */
    private function successfulRequest(?string $environment = null): array
    {
        $process = $this->request($environment);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }

    public function testPreviousGeneratedConfigurationBootsAfterNewPayloadPublicationAndRollback(): void
    {
        $this->fixture();
        $this->runtime(['wpconfig']);
        $oldConfiguration = (string) file_get_contents($this->root . '/public/wp-config.php');
        self::assertFileDoesNotExist($this->root . '/wp-config.php');
        $before = $this->successfulRequest();
        self::assertSame(['stage', 'staging', 'raw-stage'], array_slice($before, 0, 3));
        self::assertSame([true, false], array_slice($before, 5));
        $manifests = glob($this->root . '/var/runtime/*/manifest.json');
        self::assertCount(1, $manifests);
        $oldManifest = (string) file_get_contents($manifests[0]);
        $oldFiles = json_decode($oldManifest, true, flags: JSON_THROW_ON_ERROR)['files'];

        // Switching the supported bootstrap mode creates a genuinely new immutable
        // payload without modifying checked-out source or installed dependencies.
        $this->manifest(true, false);
        $this->runtime(['wpconfig']);
        self::assertCount(2, glob($this->root . '/var/runtime/*/manifest.json'));
        self::assertNotSame($oldConfiguration, file_get_contents($this->root . '/public/wp-config.php'));
        $updated = $this->successfulRequest();
        self::assertNotSame($before[3], $updated[3]);
        self::assertSame($before[4], $updated[4], 'Regeneration must preserve authentication salts.');

        $this->write('public/wp-config.php', $oldConfiguration);
        self::assertSame($before, $this->successfulRequest());
        self::assertSame($oldManifest, file_get_contents($manifests[0]));
        foreach ($oldFiles as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', dirname($manifests[0]) . '/' . $path), $path);
        }
        self::assertCount(2, glob($this->root . '/var/runtime/*/manifest.json'));
    }

    public function testRawEnvironmentSwitchRequiresCacheFlushAndExplicitDumpRebuild(): void
    {
        $this->fixture(cache: true);
        $this->runtime(['wpconfig']);
        $stage = $this->successfulRequest();
        self::assertSame(['stage', 'staging', 'raw-stage'], array_slice($stage, 0, 3));
        self::assertSame($stage, $this->successfulRequest());
        $stageCache = (string) file_get_contents($this->root . '/.env.cached.php');
        $mismatch = $this->request('production');
        self::assertNotSame(0, $mismatch->getExitCode());
        self::assertStringContainsString('does not match the requested environment', $mismatch->getErrorOutput());
        self::assertSame($stageCache, file_get_contents($this->root . '/.env.cached.php'));

        $this->runtime(['flush-env-cache']);
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
        $production = $this->successfulRequest('production');
        self::assertSame(['production', 'production', 'production'], array_slice($production, 0, 3));
        self::assertSame($production, $this->successfulRequest('production'));
        self::assertNotSame($stageCache, file_get_contents($this->root . '/.env.cached.php'));

        $this->runtime(['dump-env', 'production']);
        $productionDump = (string) file_get_contents($this->root . '/.env.dump.php');
        $this->runtime(['flush-env-cache']);
        self::assertSame($productionDump, file_get_contents($this->root . '/.env.dump.php'));
        self::assertSame($production, $this->successfulRequest('production'));
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
        $mismatch = $this->request('stage');
        self::assertNotSame(0, $mismatch->getExitCode());
        self::assertStringContainsString('does not match the requested environment', $mismatch->getErrorOutput());
        self::assertSame($productionDump, file_get_contents($this->root . '/.env.dump.php'));

        $this->runtime(['dump-env', 'stage']);
        self::assertSame($stage, $this->successfulRequest('stage'));
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
        self::assertNotSame($productionDump, file_get_contents($this->root . '/.env.dump.php'));
    }
}
