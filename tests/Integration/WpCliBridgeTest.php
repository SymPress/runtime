<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class WpCliBridgeTest extends TemporaryProject
{
    /** @return iterable<string, array{list<string>}> */
    public static function commands(): iterable
    {
        yield 'validate before WordPress exists' => [['validate']];
        yield 'doctor JSON and exit status' => [['doctor', '--json']];
        yield 'unknown flag rejected' => [['validate', '--unknown-runtime-option']];
        yield 'negated unknown flag rejected' => [['validate', '--no-unknown-runtime-option']];
        yield 'negated output flag' => [['validate', '--no-ansi']];
        yield 'WP-CLI global quiet flag' => [['validate', '--quiet']];
        yield 'environment diff' => [['env:diff', '--json']];
        yield 'dump argument boundaries' => [['dump-env', 'environment with spaces']];
        yield 'list setup steps' => [['--list-steps']];
        yield 'list commands' => [['list', '--raw']];
        yield 'command help' => [['help', 'doctor']];
        yield 'preview prune' => [['prune', '--keep=1', '--dry-run', '--json']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('commands')]
    public function testWpCliRunsTheSameCommandWithoutBootingWordPress(array $arguments): void
    {
        $wpCli = getenv('RUNTIME_TEST_WPCLI_BOOTSTRAP');
        if (!is_string($wpCli) || !is_file($wpCli)) {
            self::markTestSkipped('Set RUNTIME_TEST_WPCLI_BOOTSTRAP to the real WP-CLI entrypoint.');
        }
        $package = dirname(__DIR__, 2);
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"require-wp":false,"db-check":false}}}');
        $this->write('.env.example', "WP_HOME=https://example.test\n");
        $environment = ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false, 'WP_CLI_ALLOW_ROOT' => '1', 'WP_CLI_CONFIG_PATH' => false];
        $setup = new Process([PHP_BINARY, $package . '/bin/runtime', '-n', 'wpcliconfig'], $this->root, $environment);
        $setup->mustRun();
        $binary = new Process([PHP_BINARY, $package . '/bin/runtime', ...$arguments, '--no-interaction'], $this->root, $environment);
        $binary->run();
        // A subdirectory invocation also uses the generated project's root.
        $this->write('nested/keep', '');
        $bridge = new Process([PHP_BINARY, '-d', 'display_errors=stderr', $wpCli, 'runtime', ...$arguments], $this->root . '/nested', $environment);
        $bridge->run();
        self::assertSame($binary->getExitCode(), $bridge->getExitCode(), $bridge->getOutput() . $bridge->getErrorOutput());
        self::assertSame($binary->getOutput(), $bridge->getOutput());
        self::assertStringNotContainsString('This does not seem to be a WordPress installation', $bridge->getErrorOutput());
    }
}
