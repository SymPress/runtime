<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class MuLoaderRuntimeTest extends TemporaryProject
{
    #[DataProvider('profiles')]
    public function testLinkedLoaderUsesWordPressDirectoryAndExposesNativeHooksAndAdminRows(string $profile): void
    {
        $this->write('composer.json', json_encode([
        'extra' => [
        'wordpress-install-dir' => 'wordpress',
        'wordpress-content-dir' => 'wp-content',
        'sympress-runtime' => [
            'require-wp' => false,
        'db-check' => false,
        'compatibility-profile' => $profile,
        ],
        ],
        ], JSON_THROW_ON_ERROR));
        $this->write('wp-content/mu-plugins/z-last/main.php', "<?php\n/* Plugin Name: Last */\n" . '$GLOBALS["boots"][] = "last"; $file = "changed";');
        $this->write('wp-content/mu-plugins/a-first/main.php', "<?php\n/* Plugin Name: First */\n" . '$GLOBALS["boots"][] = "first"; $GLOBALS["scope_read"] = $table_prefix; $fixtureGlobal = "plugin-global";');
        $package = dirname(__DIR__, 2);
        $setup = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', '-n', 'muloader'], $this->root, ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]);
        $setup->mustRun();
        $loader = $this->root . '/wp-content/mu-plugins/sympress-runtime-mu-loader.php';
        self::assertFileExists($loader, $setup->getOutput() . $setup->getErrorOutput());
        if ($profile === 'native') {
            $this->write('loader-storage/keep', '');
            rename($loader, $this->root . '/loader-storage/loader.php');
            symlink($this->root . '/loader-storage/loader.php', $loader);
        }
        $probe = <<<'PHP'
            <?php
            define('WPMU_PLUGIN_DIR', __DIR__ . '/wp-content/mu-plugins');
            function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
            function add_filter($name, $callback) { $GLOBALS['filters'][$name] = $callback; }
            function do_action($name, $file) { $GLOBALS['actions'][] = [$name, basename(dirname($file))]; }
            function get_plugin_data($file, $markup, $translate) {
                return ['Name' => str_contains($file, 'a-first') ? 'First' : ''];
            }
            function _sort_uname_callback($a, $b) { return strcasecmp($a['Name'], $b['Name']); }
            $table_prefix = 'fixture_';
            require WPMU_PLUGIN_DIR . '/sympress-runtime-mu-loader.php';
            $filter = $GLOBALS['filters']['plugins_list'] ?? null;
            $hidden = ['mustuse' => [], 'active' => ['existing']];
            echo json_encode([
                'boots' => $GLOBALS['boots'],
                'scope' => [$GLOBALS['scope_read'], $fixtureGlobal],
                'actions' => $GLOBALS['actions'] ?? [],
                'rows' => $filter ? $filter(['mustuse' => ['loader.php' => ['Name' => 'Loader']]]) : [],
                'hidden' => $filter ? $filter($hidden) === $hidden : true,
            ]);
            PHP;
        $this->write('probe.php', $probe);
        $process = new Process([PHP_BINARY, $this->root . '/probe.php'], $this->root);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['first', 'last'], $result['boots']);
        self::assertSame(['fixture_', 'plugin-global'], $result['scope']);
        self::assertTrue($result['hidden']);
        if ($profile !== 'native') {
            self::assertSame([], $result['actions']);
            self::assertSame([], $result['rows']);

            return;
        }
        self::assertSame([['mu_plugin_loaded', 'a-first'], ['mu_plugin_loaded', 'z-last']], $result['actions']);
        self::assertSame(['a-first/main.php', 'loader.php', 'z-last/main.php'], array_keys($result['rows']['mustuse']));
        self::assertSame('z-last', $result['rows']['mustuse']['z-last/main.php']['Name']);
    }

    /** @return iterable<string, array{string}> */
    public static function profiles(): iterable
    {
        foreach (['native', 'release-3.0.1', 'upstream-dev'] as $profile) {
            yield $profile => [$profile];
        }
    }
}
