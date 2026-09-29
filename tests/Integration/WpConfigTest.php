<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\SectionMerger;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Tests\Contract\ConstantCatalogTest;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class WpConfigTest extends TemporaryProject
{
    /** @param array<string, mixed> $settings */
    private function fixture(array $settings = []): void
    {
        $this->write('composer.json', json_encode(['extra' => ['wordpress-install-dir' => 'public/wp', 'wordpress-content-dir' => 'public/content', 'sympress-runtime' => array_replace(['require-wp' => false, 'db-check' => false, 'cache-env' => false], $settings)]], JSON_THROW_ON_ERROR));
        $this->write('public/content/keep', 'content');
        $this->write('public/wp/wp-settings.php', '<?php $GLOBALS["settings_count"] = ($GLOBALS["settings_count"] ?? 0) + 1;');
        $this->write('public/wp/wp-includes/plugin.php', <<<'PHP'
<?php
function add_filter($name, $callback, $priority = 10, $accepted = 1) { $GLOBALS['filters'][$name][$priority][] = [$callback, $accepted]; }
function add_action($name, $callback, $priority = 10, $accepted = 1) { add_filter($name, $callback, $priority, $accepted); }
function has_filter($name) { return !empty($GLOBALS['filters'][$name]); }
function apply_filters($name, $value, ...$args) { $items = $GLOBALS['filters'][$name] ?? []; ksort($items); foreach ($items as $callbacks) { foreach ($callbacks as [$callback, $accepted]) { $value = $callback(...array_slice([$value, ...$args], 0, $accepted)); } } return $value; }
function do_action($name, ...$args) { apply_filters($name, $args[0] ?? null, ...array_slice($args, 1)); }
function register_theme_directory($path) { $GLOBALS['theme_paths'][] = $path; }
function _deprecated_function($name, $version, $replacement) { $GLOBALS['deprecated'][] = $name; }
PHP);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|false> $environment
     */
    private function generate(array $arguments = ['wpconfig'], array $environment = []): Process
    {
        $package = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', '-n', ...$arguments], $this->root, array_replace(['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false], $environment));
        $process->run();

        return $process;
    }

    /**
     * @param array<string, string|false> $environment
     * @return array<array-key, mixed>
     */
    private function boot(string $after, string $before = '', array $environment = []): array
    {
        $this->write('probe.php', '<?php ' . $before . '; require __DIR__ . "/public/wp-config.php"; ' . $after);
        $defaults = array_fill_keys(['WP_ENV', 'WP_ENVIRONMENT_TYPE', 'WORDPRESS_ENV', 'SYMPRESS_RUNTIME_ENV_LOADED', 'WPSTARTER_ENV_LOADED', 'SYMFONY_DOTENV_VARS', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'AUTH_KEY', 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_HOME', 'WP_SITEURL', 'WP_CONTENT_URL', 'WP_DEVELOPMENT_MODE'], false);
        $process = new Process([PHP_BINARY, $this->root . '/probe.php'], $this->root, array_replace($defaults, $environment));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Group('PAR-SYM-009')]
    #[Group('PAR-ENV-021')]
    public function testHttpDeprecationsAreOnceOnlyAndNeverContaminateTheResponse(): void
    {
        $this->fixture();
        $this->write('.env', "DB_PASSWORD='private-http-test-value'\n");
        $generated = $this->generate();
        self::assertSame(0, $generated->getExitCode(), $generated->getErrorOutput());
        $this->write('probe.php', <<<'PHP'
<?php
require __DIR__ . '/public/wp-config.php';
$GLOBALS['deprecations'] = 0;
add_action('deprecated_function_run', static function (): void { ++$GLOBALS['deprecations']; });
wpstarter_getenv('DB_PASSWORD');
wpstarter_getenv('DB_PASSWORD');
echo json_encode(['ok' => true, 'deprecations' => $GLOBALS['deprecations']]);
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-d', 'display_errors=1', '-S', $address, '-t', $this->root], $this->root);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, 'Development Server')));
            $response = file_get_contents('http://' . $address . '/probe.php');
            self::assertSame('{"ok":true,"deprecations":1}', $response);
            self::assertSame(1, substr_count($server->getErrorOutput(), 'wpstarter_getenv is deprecated'));
            self::assertStringNotContainsString('private-http-test-value', $server->getErrorOutput());
        } finally {
            $server->stop();
        }
    }

    #[Group('PAR-STEP-002')]
    #[Group('PAR-WP-001')]
    #[Group('PAR-WP-002')]
    #[Group('PAR-WP-003')]
    #[Group('PAR-WP-008')]
    #[Group('PAR-WP-013')]
    #[Group('PAR-WP-016')]
    public function testGeneratedConfigurationBootsWithoutVendorAndPreservesOrderingAndSecrets(): void
    {
        $this->fixture(['env-dir' => 'configuration', 'early-hook-file' => 'early.php']);
        $this->write('configuration/.env', "WP_ENV=dev-custom\nDB_NAME=fixture\nDB_USER=fixture\nDB_PASSWORD='synthetic <secret>&value'\nAUTH_KEY='environment-key'\nDB_TABLE_PREFIX=custom_\n");
        $this->write('configuration/dev-custom.php', '<?php $GLOBALS["order"][] = defined("DB_NAME") && !defined("DB_CHARSET") && !defined("WP_DEBUG"); add_filter("fixture", fn () => "hook-ready");');
        $this->write('early.php', '<?php $GLOBALS["order"][] = defined("DB_CHARSET") && defined("AUTH_KEY") && !defined("WP_DEBUG"); define("WP_DEBUG", false);');
        $generated = $this->generate();
        self::assertSame(0, $generated->getExitCode(), $generated->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/vendor/autoload.php');
        $actual = $this->boot('echo json_encode([$GLOBALS["order"], apply_filters("fixture", null), DB_PASSWORD, AUTH_KEY, WP_ENV, WP_ENVIRONMENT_TYPE, WP_DEBUG, $table_prefix, WP_HOME, WP_SITEURL, WP_CONTENT_URL, $GLOBALS["settings_count"], isset($envLoader), isset($debugInfo), class_exists("Composer\\\\Autoload\\\\ClassLoader", false)]);', '$_SERVER["SERVER_NAME"] = "example.test"; $_SERVER["SERVER_PORT"] = 8080');
        self::assertSame([[true, true], 'hook-ready', 'synthetic <secret>&value', 'environment-key', 'dev-custom', 'development', false, 'custom_', 'http://example.test:8080', 'http://example.test:8080/wp', 'http://example.test:8080/content', 1, false, false, false], $actual);
        self::assertSame(0600, fileperms($this->root . '/wp-config.php') & 0777);
    }

    #[Group('PAR-SYM-005')]
    #[Group('PAR-SYM-006')]
    public function testGeneratedConfigurationRegistersRuntimeWithActualKernelAndWordPressHooks(): void
    {
        $core = getenv('RUNTIME_TEST_WORDPRESS_DIR');
        if (!is_string($core) || !is_file($core . '/wp-includes/plugin.php')) {
            self::markTestSkipped('Requires an isolated WordPress core source fixture.');
        }
        $this->fixture();
        $this->write('public/wp/wp-includes/plugin.php', '<?php require ' . var_export($core . '/wp-includes/plugin.php', true) . ';');
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nSYMPRESS_KERNEL_BUILD_ID=real-hook-build\n");
        $generated = $this->generate();
        self::assertSame(0, $generated->getExitCode(), $generated->getErrorOutput());
        $actual = $this->boot('require "vendor/autoload.php"; $kernel = new SymPress\\Kernel\\Kernel\\SiteKernel(__DIR__); $bundles = $kernel->discoverBundles(); echo json_encode([array_map(static fn ($entry) => $entry->bundle()->id(), $bundles->all()), $kernel->getEnvironment(), SYMPRESS_KERNEL_BUILD_ID]);');
        self::assertContains('SymPress\\Runtime\\Bridge\\Kernel\\RuntimeBundle', $actual[0]);
        self::assertSame('production', $actual[1]);
        self::assertSame('real-hook-build', $actual[2]);
    }

    #[Group('PAR-WP-014')]
    public function testSeparateGenerationsRetainSaltsSectionEditsAndUnchangedBytes(): void
    {
        $this->fixture();
        $first = $this->generate();
        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $before = file_get_contents($this->root . '/wp-config.php');
        touch($this->root . '/wp-config.php', 1000000000);
        $second = $this->generate();
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        self::assertSame($before, file_get_contents($this->root . '/wp-config.php'));
        clearstatcache();
        self::assertSame(1000000000, filemtime($this->root . '/wp-config.php'));
        $paths = new Paths($this->root, wp: 'public/wp', content: 'public/content');
        $editor = new WpConfigSectionEditor($paths, new Config([], new Validator($paths)), new Filesystem());
        $editor->append('BEFORE_BOOTSTRAP', 'define("CUSTOM_SECTION_VALUE", "keep");');
        $third = $this->generate();
        self::assertSame(0, $third->getExitCode(), $third->getErrorOutput());
        self::assertStringContainsString('Preserved edited configuration section: BEFORE_BOOTSTRAP', $third->getOutput());
        self::assertSame(['keep'], $this->boot('echo json_encode([CUSTOM_SECTION_VALUE]);'));
        $edited = file_get_contents($this->root . '/wp-config.php');
        self::assertSame(0, $this->generate()->getExitCode());
        self::assertSame($edited, file_get_contents($this->root . '/wp-config.php'));
    }

    #[Group('PAR-STEP-002')]
    public function testProtectedProxyBlocksAllConfigWritesAndForceKeepsExistingSalts(): void
    {
        $this->fixture();
        $this->write('public/wp-config.php', '<?php // user-owned');
        $blocked = $this->generate();
        self::assertNotSame(0, $blocked->getExitCode());
        self::assertFileDoesNotExist($this->root . '/wp-config.php');
        self::assertDirectoryDoesNotExist($this->root . '/var/runtime');
        self::assertSame('<?php // user-owned', file_get_contents($this->root . '/public/wp-config.php'));
        $this->write('wp-config.php', '<?php define("AUTH_KEY", "keep-through-force");');
        $forced = $this->generate(['wpconfig', '--force']);
        self::assertSame(0, $forced->getExitCode(), $forced->getErrorOutput());
        self::assertSame(['keep-through-force'], $this->boot('echo json_encode([AUTH_KEY]);'));
    }

    #[Group('PAR-WP-014')]
    public function testEditedDynamicSaltSectionIsPreservedAndMissingKeysAreRepaired(): void
    {
        $this->fixture();
        self::assertSame(0, $this->generate()->getExitCode());
        $paths = new Paths($this->root, wp: 'public/wp', content: 'public/content');
        $editor = new WpConfigSectionEditor($paths, new Config([], new Validator($paths)), new Filesystem());
        $editor->replace('KEYS', 'define("AUTH_KEY", str_repeat("dynamic", 3));');
        $regenerated = $this->generate();
        self::assertSame(0, $regenerated->getExitCode(), $regenerated->getErrorOutput());
        self::assertSame(['dynamicdynamicdynamic', 64, 64], $this->boot('echo json_encode([AUTH_KEY, strlen(NONCE_KEY), strlen(NONCE_SALT)]);'));
        $before = file_get_contents($this->root . '/wp-config.php');
        self::assertSame(0, $this->generate()->getExitCode());
        self::assertSame($before, file_get_contents($this->root . '/wp-config.php'));
    }

    /** @return iterable<string, array{string, array<bool|string|null>}> */
    public static function defaults(): iterable
    {
        yield 'local' => ['local', [true, true, false, true, true, true, true, 'all']];
        yield 'development' => ['dev', [true, true, false, true, true, true, null, null]];
        yield 'staging' => ['preprod-eu', [true, false, true, false, true, null, null, null]];
        yield 'production' => ['unknown', [false, false, false, false, false, null, null, null]];
    }

    #[DataProvider('defaults')]
    #[Group('PAR-WP-004')]
    #[Group('PAR-WP-005')]
    #[Group('PAR-WP-006')]
    #[Group('PAR-WP-007')]
    /** @param array<bool|string|null> $expected */
    public function testCanonicalEnvironmentDefaults(string $environment, array $expected): void
    {
        $this->fixture();
        $this->write('.env', 'WP_ENV=' . $environment);
        self::assertSame(0, $this->generate()->getExitCode());
        $actual = $this->boot('echo json_encode(array_map(static fn ($name) => defined($name) ? constant($name) : null, ["WP_DEBUG", "WP_DEBUG_DISPLAY", "WP_DEBUG_LOG", "SAVEQUERIES", "SCRIPT_DEBUG", "WP_DISABLE_FATAL_ERROR_HANDLER", "WP_LOCAL_DEV", "WP_DEVELOPMENT_MODE"]));');
        self::assertSame($expected, $actual);
    }

    #[Group('PAR-WP-009')]
    #[Group('PAR-WP-015')]
    #[Group('PAR-ENV-021')]
    public function testRuntimeHooksCompatibilityAndHealthAllowlist(): void
    {
        $this->fixture(['register-theme-folder' => true]);
        $this->write('.env', "DB_PASSWORD=synthetic-secret-not-for-health\nWP_ADMIN_COLOR=coffee\nWP_FORCE_SSL_FORWARDED_PROTO=true\nRTV_CUSTOM=visible-to-getter\n");
        self::assertSame(0, $this->generate()->getExitCode());
        $actual = $this->boot('do_action("plugins_loaded"); wpstarter_getenv("RTV_CUSTOM"); wpstarter_getenv("RTV_CUSTOM"); echo json_encode([WP_HOME, apply_filters("get_user_option_admin_color", "fresh"), sympress_runtime_getenv("RTV_CUSTOM"), apply_filters("getenv", "RTV_CUSTOM"), $GLOBALS["deprecated"], count($GLOBALS["theme_paths"]), str_contains(json_encode(apply_filters("debug_information", [])), "synthetic-secret-not-for-health")]);', '$_SERVER["HTTP_X_FORWARDED_PROTO"] = "HTTPS"; $_SERVER["SERVER_NAME"] = "example.test"');
        self::assertSame(['https://example.test', 'coffee', 'visible-to-getter', 'visible-to-getter', ['wpstarter_getenv', 'getenv filter'], 1, false], $actual);
    }

    #[Group('PAR-ENV-018')]
    #[Group('PAR-ENV-019')]
    #[Group('PAR-ENV-020')]
    public function testShutdownCacheRestoresInFreshRequestAndActualEnvironmentWins(): void
    {
        $this->fixture(['cache-env' => true]);
        $this->write('.env', "WP_ENV=production\nDB_NAME=fixture\nDB_USER=fixture\nRTV_CACHE=from-file\n");
        self::assertSame(0, $this->generate()->getExitCode());
        self::assertSame(['from-file'], $this->boot('echo json_encode([sympress_runtime_getenv("RTV_CACHE")]);'));
        self::assertFileExists($this->root . '/.env.cached.php');
        self::assertSame(0600, fileperms($this->root . '/.env.cached.php') & 0777);
        $this->write('.env', 'malformed and bypassed');
        self::assertSame(['actual', 'Yes'], $this->boot('echo json_encode([sympress_runtime_getenv("RTV_CACHE"), apply_filters("debug_information", [])["sympress-runtime"]["fields"]["cached-env"]["value"]]);', environment: ['RTV_CACHE' => 'actual']));
    }

    #[Group('PAR-WP-017')]
    public function testProxyWpCliGuardSkipsWordPressSettings(): void
    {
        $this->fixture(['compatibility' => false]);
        self::assertSame(0, $this->generate()->getExitCode());
        self::assertSame([false, false, false], $this->boot('echo json_encode([isset($GLOBALS["settings_count"]), function_exists("wpstarter_getenv"), defined("WPSTARTER_PATH")]);', 'define("WP_CLI", true)'));
    }

    #[Group('PAR-ENV-023')]
    public function testExplicitBuildDumpRunsReadOnlyAndRetainsActualEnvironmentPrecedence(): void
    {
        $this->fixture(['env-dir' => 'configuration', 'cache-env' => true]);
        $this->write('configuration/.env', "WP_ENV=development\nDB_NAME=fixture\nDB_USER=fixture\nRTV_DUMP=base\n");
        $this->write('configuration/.env.production', "RTV_DUMP=production-file\n");
        self::assertSame(0, $this->generate()->getExitCode());
        $this->write('sympress-runtime-autoload.php', '<?php throw new RuntimeException("Run-only autoload executed");');
        $dump = $this->generate(['dump-env', 'production']);
        self::assertSame(0, $dump->getExitCode(), $dump->getErrorOutput());
        $file = $this->root . '/configuration/.env.dump.php';
        self::assertFileExists($file);
        self::assertSame(0600, fileperms($file) & 0777);
        $this->write('configuration/.env', 'malformed; must not load');
        chmod($file, 0400);
        chmod(dirname($file), 0500);
        try {
            $actual = $this->boot('echo json_encode([WP_ENV, WP_ENVIRONMENT_TYPE, sympress_runtime_getenv("RTV_DUMP")]);', environment: ['RTV_DUMP' => 'actual']);
            self::assertSame(['production', 'production', 'actual'], $actual);
            self::assertFileDoesNotExist($this->root . '/configuration/.env.cached.php');
            self::assertSame(0400, fileperms($file) & 0777);
        } finally {
            chmod(dirname($file), 0700);
            chmod($file, 0600);
        }
    }

    #[Group('PAR-ENV-023')]
    public function testDumpRequiresExplicitSafeEnvironmentAndPreservesUnmanagedTarget(): void
    {
        $this->fixture();
        self::assertNotSame(0, $this->generate(['dump-env'])->getExitCode());
        self::assertNotSame(0, $this->generate(['dump-env', '../escape'])->getExitCode());
        $this->write('.env.dump.php', '<?php // user-owned');
        $dump = $this->generate(['dump-env', 'production']);
        self::assertNotSame(0, $dump->getExitCode());
        self::assertSame('<?php // user-owned', file_get_contents($this->root . '/.env.dump.php'));
    }

    #[Group('PAR-ENV-023')]
    public function testDumpRejectsConflictingActualEnvironmentBeforeWriting(): void
    {
        $this->fixture();
        $dump = $this->generate(['dump-env', 'production'], ['WP_ENVIRONMENT_TYPE' => 'staging']);
        self::assertNotSame(0, $dump->getExitCode());
        self::assertStringContainsString('conflicts', $dump->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/.env.dump.php');
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
    }

    #[Group('PAR-ENV-020')]
    public function testLocalCacheDefaultCanBeOverriddenByTheNativeFilter(): void
    {
        $this->fixture(['cache-env' => true, 'early-hook-file' => 'early.php']);
        $this->write('early.php', '<?php');
        $this->write('.env', "WP_ENV=local\nDB_NAME=fixture\nDB_USER=fixture\n");
        self::assertSame(0, $this->generate()->getExitCode());
        self::assertSame(['all'], $this->boot('echo json_encode([WP_DEVELOPMENT_MODE]);'));
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
        $this->write('early.php', '<?php add_filter("sympress.runtime.skip-cache-env", static function ($skip, $raw) { return $raw !== "local"; }, 10, 2);');
        self::assertSame(['all'], $this->boot('echo json_encode([WP_DEVELOPMENT_MODE]);'));
        self::assertFileExists($this->root . '/.env.cached.php');
    }

    #[Group('PAR-WP-014')]
    public function testKeysSectionDoesNotPermitDiscardingDynamicDefinitionsOutsideIt(): void
    {
        $this->fixture();
        $source = "<?php define('AUTH_KEY', secret_provider());\nKEYS : {\n} #@@/KEYS\n";
        $this->write('wp-config.php', $source);
        $generated = $this->generate(['wpconfig', '--force']);
        self::assertNotSame(0, $generated->getExitCode());
        self::assertStringContainsString('nonliteral salt definition: AUTH_KEY', $generated->getErrorOutput());
        self::assertSame($source, file_get_contents($this->root . '/wp-config.php'));
        self::assertFileDoesNotExist($this->root . '/public/wp-config.php');
        self::assertDirectoryDoesNotExist($this->root . '/var/runtime');
    }

    public function testGeneratedFileContainsEveryInventoriedSection(): void
    {
        $this->fixture();
        self::assertSame(0, $this->generate()->getExitCode());
        $sections = (new SectionMerger())->sections((string) file_get_contents($this->root . '/wp-config.php'));
        $inventory = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/upstream-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_unique([...array_column($inventory['baselines']['release']['sections'], 'name'), ...array_column($inventory['baselines']['dev']['sections'], 'name')]);
        self::assertCount(19, $sections);
        self::assertEqualsCanonicalizing(array_values($names), array_keys($sections));
    }

    /** @return iterable<string, array{string}> */
    public static function sections(): iterable
    {
        $inventory = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/upstream-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_unique([...array_column($inventory['baselines']['release']['sections'], 'name'), ...array_column($inventory['baselines']['dev']['sections'], 'name')]);
        foreach ($names as $name) {
            yield 'PAR-SECTION-' . $name => [$name];
        }
    }

    #[DataProvider('sections')]
    public function testEverySectionAcceptsExecutableEditsAndRetainsThemAcrossGeneration(string $name): void
    {
        $this->fixture();
        self::assertSame(0, $this->generate()->getExitCode());
        $paths = new Paths($this->root, wp: 'public/wp', content: 'public/content');
        $editor = new WpConfigSectionEditor($paths, new Config([], new Validator($paths)), new Filesystem());
        $editor->append($name, '$GLOBALS["section_probe"] = ' . var_export($name, true) . ';');
        $regenerated = $this->generate();
        self::assertSame(0, $regenerated->getExitCode(), $regenerated->getErrorOutput());
        self::assertSame([$name], $this->boot('echo json_encode([$GLOBALS["section_probe"] ?? null]);'));
    }

    /** @return iterable<string, array{string, string, bool|int|float|string}> */
    public static function constantCases(): iterable
    {
        foreach (ConstantCatalogTest::constants() as $id => [$name, , $raw, $value]) {
            yield $id => [$name, $raw, $value];
        }
    }

    #[DataProvider('constantCases')]
    public function testEveryConstantThroughGeneratedConfigurationCacheAndBuildDump(string $name, string $raw, bool|int|float|string $expected): void
    {
        $this->fixture(['cache-env' => true, 'early-hook-file' => 'early.php']);
        $this->write('early.php', '<?php add_filter("sympress.runtime.skip-cache-env", static fn () => false);');
        $this->write('.env', "WP_ENV=production\nDB_NAME=fixture\nDB_USER=fixture\n");
        self::assertSame(0, $this->generate()->getExitCode());
        $key = var_export($name, true);
        $probe = '$value = sympress_runtime_getenv(' . $key . '); echo json_encode([$value, constant(' . $key . '), get_debug_type($value)], JSON_PRESERVE_ZERO_FRACTION);';
        $constant = match ($name) {
            'ABSPATH' => $this->root . '/public/wp/',
            'FTP_ASCII', 'FTP_BINARY' => constant($name),
            default => $expected,
        };
        $record = [$expected, $constant, get_debug_type($expected)];
        self::assertSame($record, $this->boot($probe, environment: [$name => $raw]));
        self::assertFileExists($this->root . '/.env.cached.php');
        self::assertSame($record, $this->boot($probe, environment: [$name => false]));
        $dump = $this->generate(['dump-env', 'production'], [$name => $raw]);
        self::assertSame(0, $dump->getExitCode(), $dump->getErrorOutput());
        self::assertFileExists($this->root . '/.env.dump.php');
        unlink($this->root . '/.env.cached.php');
        $this->write('.env', 'malformed and must not load');
        self::assertSame($record, $this->boot($probe, environment: [$name => false]));
        self::assertFileDoesNotExist($this->root . '/.env.cached.php');
    }
}
