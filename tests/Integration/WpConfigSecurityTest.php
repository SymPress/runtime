<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class WpConfigSecurityTest extends TemporaryProject
{
    #[Group('PAR-WP-008')]
    public function testCanonicalHomeFailsClosedBeforeWordPressForStagingProductionAndUnknownEnvironments(): void
    {
        $this->fixture();
        self::assertSame(0, $this->generate()->getExitCode());
        foreach (['staging', 'production', 'unknown-production-name'] as $environment) {
            foreach (['', 'WP_HOME=\n', 'WP_HOME=/relative\n', 'WP_HOME=https://user:pass@example.test\n', 'WP_HOME=https://example.test/?query=value\n'] as $home) {
                $this->write('.env', 'WP_ENVIRONMENT_TYPE=' . $environment . "\n" . str_replace('\\n', "\n", $home));
                $this->write('probe.php', '<?php $_SERVER["SERVER_NAME"] = "attacker.test"; try { require "public/wp-config.php"; echo "unexpected"; } catch (RuntimeException $error) { echo json_encode([str_contains($error->getMessage(), "WP_HOME"), isset($GLOBALS["settings_count"])]); }');
                $process = new Process([PHP_BINARY, 'probe.php'], $this->root, array_fill_keys(['WP_HOME', 'WP_ENV', 'WP_ENVIRONMENT_TYPE', 'WORDPRESS_ENV', 'SYMFONY_DOTENV_VARS'], false));
                $process->mustRun();
                self::assertSame('[true,false]', $process->getOutput());
            }
            $this->write('.env', 'WP_ENVIRONMENT_TYPE=' . $environment . "\nWP_HOME=https://canonical.test/site\n");
            self::assertSame(['https://canonical.test/site'], $this->boot('echo json_encode([WP_HOME]);', '$_SERVER["SERVER_NAME"] = "attacker.test"'));
        }
    }

    #[Group('PAR-WP-009')]
    public function testDevelopmentFallbackRejectsInjectedHostAndUntrustedForwardedScheme(): void
    {
        $this->fixture();
        $this->write('.env', "WP_ENVIRONMENT_TYPE=development\nWP_FORCE_SSL_FORWARDED_PROTO=true\nSYMPRESS_RUNTIME_TRUSTED_PROXIES=192.0.2.0/24,2001:db8::/32\n");
        self::assertSame(0, $this->generate()->getExitCode());
        $probe = 'echo json_encode([WP_HOME, $_SERVER["HTTPS"] ?? null]);';
        foreach ([['203.0.113.9', 'https', false], ['192.0.2.9', 'https', true], ['2001:db8::a', 'HTTPS', true], ['192.0.2.9', 'https,http', false]] as [$peer, $scheme, $trusted]) {
            $before = '$_SERVER["SERVER_NAME"] = "local.test"; $_SERVER["REMOTE_ADDR"] = ' . var_export($peer, true) . '; $_SERVER["HTTP_X_FORWARDED_PROTO"] = ' . var_export($scheme, true);
            self::assertSame([$trusted ? 'https://local.test' : 'http://local.test', $trusted ? 'on' : null], $this->boot($probe, $before));
        }
        self::assertSame(['http://localhost', null], $this->boot($probe, '$_SERVER["SERVER_NAME"] = "example.test/path?injected"'));
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
