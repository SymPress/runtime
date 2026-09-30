<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class WpCliStepTest extends TemporaryProject
{
    /** @param array<string, mixed> $settings */
    private function fixture(array $settings): void
    {
        $this->write('composer.json', json_encode(['extra' => ['wordpress-install-dir' => 'public/wp', 'wordpress-content-dir' => 'public/content', 'sympress-runtime' => array_replace(['require-wp' => false, 'db-check' => false, 'install-wp-cli' => false], $settings)]], JSON_THROW_ON_ERROR));
        $this->write('wp-cli.phar', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/calls.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
exit(in_array('fail', $argv, true) ? 17 : 0);
PHP);
    }

    /** @param list<string> $arguments */
    private function command(array $arguments = ['wpcli']): Process
    {
        $package = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', '-n', ...$arguments], $this->root, ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]);
        $process->run();

        return $process;
    }

    /** @return list<list<string>> */
    private function calls(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->root . '/calls.jsonl', FILE_IGNORE_NEW_LINES));
    }

    #[Group('PAR-STEP-012')]
    #[Group('PAR-WPC-004')]
    #[Group('PAR-WPC-005')]
    #[Group('PAR-WPC-007')]
    public function testVersionFilesAndCommandsRunInOrderWithLiteralArgumentsAndOwnedPath(): void
    {
        $this->write("scripts/my 'file.PHP", '<?php');
        $arguments = ['space value', "quote'value", '\\literal', '$(touch injected)', '', '0', ' spaced '];
        $this->fixture(['wp-cli-files' => [['file' => "scripts/my 'file.PHP", 'args' => $arguments, 'skip-wordpress' => true]], 'wp-cli-commands' => ['wp option set title "quoted value" --path="wrong directory"', 'wp option get name --path other -- --path=literal-value']]);
        $run = $this->command();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $path = '--path=' . $this->root . '/public/wp';
        self::assertSame([
            ['cli', 'version', $path],
            ['eval-file', $this->root . "/scripts/my 'file.PHP", 'space value', "quote'value", '\\literal', '$(touch injected)', '0', ' spaced ', '--skip-wordpress', $path],
            ['option', 'set', 'title', 'quoted value', $path],
            ['option', 'get', 'name', $path, '--', '--path=literal-value'],
        ], $this->calls());
        self::assertFileDoesNotExist($this->root . '/injected');
    }

    #[Group('PAR-WPC-006')]
    public function testPhpProviderGetsScopedServicesRunsOnceAndListingDoesNotEvaluateIt(): void
    {
        $this->write('.env', "RTV_PROVIDER=available\n");
        $this->write('sympress-runtime-autoload.php', '<?php $GLOBALS["locator"] = "original";');
        $this->write('commands.php', <<<'PHP'
<?php
if ($locator !== $services || $GLOBALS['locator'] !== $services || $services->env()->read('RTV_PROVIDER') !== 'available') { throw new RuntimeException('provider scope failed'); }
file_put_contents(__DIR__ . '/provider-calls', 'called', FILE_APPEND);
register_shutdown_function(static function () { file_put_contents(__DIR__ . '/restored-scope', (string) ($GLOBALS['locator'] ?? 'absent')); });
return ['wp core version'];
PHP);
        $this->fixture(['wp-cli-commands' => 'commands.php']);
        self::assertSame(0, $this->command(['--list-steps'])->getExitCode());
        self::assertSame(0, $this->command(['validate'])->getExitCode());
        self::assertFileDoesNotExist($this->root . '/provider-calls');
        $run = $this->command();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame('called', file_get_contents($this->root . '/provider-calls'));
        self::assertSame('original', file_get_contents($this->root . '/restored-scope'));
        self::assertSame('core', $this->calls()[1][0]);
    }

    #[Group('PAR-WPC-006')]
    #[Group('PAR-WPC-007')]
    public function testJsonProviderStopsAtFirstFailureAndDoesNotExposeCommandArguments(): void
    {
        $this->write('commands.json', json_encode(['wp core version', 'wp fail --password=synthetic-private-value', 'wp never'], JSON_THROW_ON_ERROR));
        $this->fixture(['wp-cli-commands' => 'commands.json']);
        $run = $this->command();
        self::assertNotSame(0, $run->getExitCode());
        self::assertCount(3, $this->calls());
        self::assertStringNotContainsString('synthetic-private-value', $run->getOutput() . $run->getErrorOutput());
    }

    #[Group('PAR-WPC-006')]
    public function testProviderFailuresAreRedactedAndEmptyProvidersDoNotResolveTools(): void
    {
        $this->write('commands.php', '<?php throw new RuntimeException("synthetic-provider-secret");');
        $this->fixture(['wp-cli-commands' => 'commands.php']);
        $run = $this->command();
        self::assertNotSame(0, $run->getExitCode());
        self::assertStringContainsString('PHP command provider failed', $run->getErrorOutput());
        self::assertStringNotContainsString('synthetic-provider-secret', $run->getOutput() . $run->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/calls.jsonl');
        $this->write('commands.php', '<?php return [];');
        unlink($this->root . '/wp-cli.phar');
        self::assertSame(0, $this->command()->getExitCode());
        self::assertFileDoesNotExist($this->root . '/wp-cli.phar');
    }

    #[Group('PAR-WPC-005')]
    public function testFileRemovedByProviderIsReportedAndSkipped(): void
    {
        $this->write('removed.php', '<?php');
        $this->write('commands.php', '<?php unlink(__DIR__ . "/removed.php"); return ["wp core version"];');
        $this->fixture(['wp-cli-commands' => 'commands.php', 'wp-cli-files' => ['removed.php']]);
        $run = $this->command();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('eval-file source is missing', $run->getOutput());
        self::assertCount(2, $this->calls());
    }

    #[Group('PAR-WPC-007')]
    public function testRealPinnedWpCliExecutesEvalFileWithoutWordPressAndReadsCoreVersion(): void
    {
        $bootstrap = getenv('RUNTIME_TEST_WPCLI_BOOTSTRAP');
        $core = getenv('RUNTIME_TEST_WORDPRESS_DIR');
        if (!is_string($bootstrap) || !is_file($bootstrap) || !is_string($core) || !is_file($core . '/wp-includes/version.php')) {
            self::markTestSkipped('Set RUNTIME_TEST_WPCLI_BOOTSTRAP and RUNTIME_TEST_WORDPRESS_DIR for the pinned WP-CLI smoke.');
        }
        $this->write('scripts/probe.php', '<?php file_put_contents(__DIR__ . "/arguments.json", json_encode($args));');
        $this->fixture(['wp-cli-files' => [['file' => 'scripts/probe.php', 'args' => ["quoted ' value", '\\literal', '0'], 'skip-wordpress' => true]], 'wp-cli-commands' => ['wp cli version', 'wp core version']]);
        $this->write('public/content/keep', 'fixture');
        self::assertTrue(symlink($core, $this->root . '/public/wp'));
        $this->write('wp-cli.phar', '<?php require ' . var_export($bootstrap, true) . ';');
        $this->write('wp-cli-global.yml', '{}');
        $package = dirname(__DIR__, 2);
        $run = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', '-n', 'wpconfig', 'wpcliconfig', 'wpcli'], $this->root, ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false, 'WP_CLI_CONFIG_PATH' => $this->root . '/wp-cli-global.yml', 'WP_CLI_PACKAGES_DIR' => $this->root . '/wp-cli-packages']);
        $run->run();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('WP-CLI 2.12.0', $run->getOutput());
        self::assertStringContainsString('7.1.1', $run->getOutput());
        self::assertSame(["quoted ' value", '\\literal', '0'], json_decode(file_get_contents($this->root . '/scripts/arguments.json'), true, flags: JSON_THROW_ON_ERROR));
    }
}
