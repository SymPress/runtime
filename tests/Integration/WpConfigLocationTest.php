<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class WpConfigLocationTest extends TemporaryProject
{
    /** @return iterable<string, array{string, bool}> */
    public static function configurationLocations(): iterable
    {
        yield 'native' => ['native', false];
        yield 'release compatibility' => ['release-3.0.1', false];
        yield 'development compatibility' => ['upstream-dev', true];
    }

    #[DataProvider('configurationLocations')]
    #[Group('PAR-CFG-012')]
    #[Group('PAR-STEP-002')]
    public function testDefaultConfigurationLocationPreservesCompatibility(string $profile, bool $proxy): void
    {
        $this->fixture(['compatibility-profile' => $profile]);
        $generated = $this->generate();
        self::assertSame(0, $generated->getExitCode(), $generated->getErrorOutput());
        $public = (string) file_get_contents($this->root . '/public/wp-config.php');
        self::assertSame($proxy, is_file($this->root . '/wp-config.php'));
        self::assertStringContainsString($proxy ? 'wp-config-proxy:v1' : 'wp-config:v1', $public);
        self::assertSame([1], $this->boot('echo json_encode([$GLOBALS["settings_count"]]);'));
    }

    #[Group('PAR-WP-014')]
    public function testPreviousManagedRootConfigurationMigratesWithoutExecutingOrRotatingSalts(): void
    {
        $this->fixture(['compatibility-profile' => 'upstream-dev', 'compatibility' => false]);
        self::assertSame(0, $this->generate()->getExitCode());
        $previous = (string) file_get_contents($this->root . '/wp-config.php');
        $keys = $this->boot('echo json_encode([AUTH_KEY, NONCE_SALT]);');
        $this->write('wp-config.php', str_replace("<?php\n", "<?php\nthrow new RuntimeException(\"must not execute old configuration\");\n", $previous));
        $retained = (string) file_get_contents($this->root . '/wp-config.php');
        $this->fixture(['compatibility' => false]);
        $generated = $this->generate();
        self::assertSame(0, $generated->getExitCode(), $generated->getErrorOutput());
        self::assertStringContainsString('Migrating managed root configuration', $generated->getOutput());
        self::assertSame($keys, $this->boot('echo json_encode([AUTH_KEY, NONCE_SALT]);'));
        self::assertSame($retained, file_get_contents($this->root . '/wp-config.php'));
        self::assertStringNotContainsString('wp-config-proxy:v1', (string) file_get_contents($this->root . '/public/wp-config.php'));
    }

    public function testPreviousEditedRootConfigurationRequiresExplicitMigrationBeforeWrites(): void
    {
        $this->fixture(['compatibility-profile' => 'upstream-dev']);
        self::assertSame(0, $this->generate()->getExitCode());
        $paths = new Paths($this->root, wp: 'public/wp', content: 'public/content');
        $editor = new WpConfigSectionEditor($paths, new Config([], new Validator($paths), 'upstream-dev'), new Filesystem());
        $editor->append('BEFORE_BOOTSTRAP', 'require __DIR__ . "/custom-bootstrap.php";');
        $previous = file_get_contents($this->root . '/wp-config.php');
        $proxy = file_get_contents($this->root . '/public/wp-config.php');
        $this->fixture();
        $generated = $this->generate();
        self::assertNotSame(0, $generated->getExitCode());
        self::assertStringContainsString('Existing root configuration has edited sections', $generated->getErrorOutput());
        self::assertSame($previous, file_get_contents($this->root . '/wp-config.php'));
        self::assertSame($proxy, file_get_contents($this->root . '/public/wp-config.php'));
    }

    public function testNativeParentTargetCannotOverwriteUnmanagedConfiguration(): void
    {
        $this->fixture();
        $this->write('public/wp-config.php', '<?php // user-owned');
        $generated = $this->generate();
        self::assertSame(0, $generated->getExitCode());
        self::assertStringContainsString('Preserved protected target', $generated->getOutput());
        self::assertSame('<?php // user-owned', file_get_contents($this->root . '/public/wp-config.php'));
        self::assertFileDoesNotExist($this->root . '/wp-config.php');
        self::assertSame([], glob($this->root . '/var/runtime/*/bootstrap.php'));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function unsafeRootConfigurations(): iterable
    {
        yield 'unmanaged root' => [false, 'Existing root configuration must be migrated explicitly'];
        yield 'dynamic salts' => [true, 'nonliteral salt definition'];
    }

    #[DataProvider('unsafeRootConfigurations')]
    public function testUnsafeRootMigrationDoesNotReplaceEitherConfiguration(bool $dynamic, string $error): void
    {
        $this->fixture(['compatibility-profile' => 'upstream-dev']);
        self::assertSame(0, $this->generate()->getExitCode());
        if (!$dynamic) {
            $this->write('wp-config.php', '<?php // user-owned');
        }
        if ($dynamic) {
            $paths = new Paths($this->root, wp: 'public/wp', content: 'public/content');
            $editor = new WpConfigSectionEditor($paths, new Config([], new Validator($paths), 'upstream-dev'), new Filesystem());
            $editor->replace('KEYS', 'define("AUTH_KEY", str_repeat("dynamic", 3));');
        }
        $previous = file_get_contents($this->root . '/wp-config.php');
        $proxy = file_get_contents($this->root . '/public/wp-config.php');
        $artifacts = scandir($this->root . '/var/runtime');
        $this->fixture();
        $generated = $this->generate();
        self::assertNotSame(0, $generated->getExitCode());
        self::assertStringContainsString($error, $generated->getErrorOutput());
        self::assertSame($previous, file_get_contents($this->root . '/wp-config.php'));
        self::assertSame($proxy, file_get_contents($this->root . '/public/wp-config.php'));
        self::assertSame($artifacts, scandir($this->root . '/var/runtime'));
    }

    /** @param array<string, mixed> $settings */
    private function fixture(array $settings = []): void
    {
        $this->write('composer.json', json_encode(['extra' => ['wordpress-install-dir' => 'public/wp', 'wordpress-content-dir' => 'public/content', 'sympress-runtime' => array_replace(['require-wp' => false, 'db-check' => false, 'cache-env' => false], $settings)]], JSON_THROW_ON_ERROR));
        $this->write('.env', "WP_HOME=http://localhost\n");
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
        $process = new Process([PHP_BINARY, $package . '/bin/runtime', '-n', ...$arguments], $this->root, array_replace(['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false], $environment));
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
}
