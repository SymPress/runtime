<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class MigrationTest extends TemporaryProject
{
    /** @param array<string, mixed> $extra */
    private function fixture(array $extra = []): void
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('composer.json', json_encode(['extra' => $extra], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $arguments */
    private function migrate(array $arguments = []): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', 'migrate', '--json', ...$arguments], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false]);
        $process->run();

        return $process;
    }

    #[Group('PAR-SYM-007')]
    #[Group('PAR-NATIVE-002')]
    #[Group('PAR-OPT-028')]
    public function testMigratesSourcePrecedenceDefaultsAndDeprecatedDatabaseFlagWithoutExecutingPhp(): void
    {
        $this->fixture(['wpstarter' => 'dev-ops/old.json']);
        $this->write('dev-ops/old.json', '{"env-file":"custom.env","skip-db-check":true,"content-dev-op":"copy"}');
        $this->write('wpstarter.json', '{"content-dev-op":"symlink"}');
        $this->write('wpstarter-autoload.php', '<?php file_put_contents(__DIR__ . "/executed", "wrong");');
        $original = file_get_contents($this->root . '/composer.json');
        $run = $this->migrate();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $report = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $config = json_decode((string) file_get_contents($this->root . '/sympress-runtime.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('written', $report['status']);
        self::assertTrue($report['ready']);
        self::assertSame('wpstarter.json', $report['provenance']['content-dev-op']);
        self::assertSame('release-3.0.1', $config['compatibility-profile']);
        self::assertSame('symlink', $config['content-dev-op']);
        self::assertSame('custom.env', $config['env-file']);
        self::assertSame('wpstarter-autoload.php', $config['autoload']);
        self::assertFalse($config['env-local-overrides']);
        self::assertTrue($config['wp-config-autoload']);
        self::assertSame('release-3.0.1 default', $report['provenance']['wp-config-autoload']);
        self::assertStringContainsString('wp-config-autoload', implode(' ', $report['next']));
        self::assertFalse($config['db-check']);
        self::assertArrayNotHasKey('skip-db-check', $config);
        self::assertSame($original, file_get_contents($this->root . '/composer.json'));
        self::assertSame('{"content-dev-op":"symlink"}', file_get_contents($this->root . '/wpstarter.json'));
        self::assertFileDoesNotExist($this->root . '/executed');
        self::assertSame(0600, fileperms($this->root . '/sympress-runtime.json') & 0777);
        touch($this->root . '/sympress-runtime.json', 1000000000);
        $again = $this->migrate();
        self::assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        self::assertSame('unchanged', json_decode($again->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
        clearstatcache();
        self::assertSame(1000000000, filemtime($this->root . '/sympress-runtime.json'));
    }

    #[Group('PAR-SYM-007')]
    public function testDryRunCollisionForceAndOriginalProtection(): void
    {
        $this->fixture(['wpstarter' => ['env-file' => 'private-config.env']]);
        $dry = $this->migrate(['--dry-run', '--output=output/new.json']);
        self::assertSame(0, $dry->getExitCode(), $dry->getErrorOutput());
        self::assertStringNotContainsString('private-config.env', $dry->getOutput());
        self::assertDirectoryDoesNotExist($this->root . '/output');
        $this->write('output/new.json', '{"keep":true}');
        self::assertNotSame(0, $this->migrate(['--output=output/new.json'])->getExitCode());
        self::assertSame('{"keep":true}', file_get_contents($this->root . '/output/new.json'));
        self::assertSame(0, $this->migrate(['--output=output/new.json', '--force'])->getExitCode());
        self::assertNotSame(0, $this->migrate(['--output=composer.json', '--force'])->getExitCode());
        self::assertNotSame(0, $this->migrate(['--output=../escape.json', '--force'])->getExitCode());
        symlink('output/new.json', $this->root . '/linked.json');
        self::assertNotSame(0, $this->migrate(['--output=linked.json', '--force'])->getExitCode());
        self::assertTrue(is_link($this->root . '/linked.json'));
    }

    public function testMigrationPreservesExplicitProjectAutoloadOptOut(): void
    {
        $this->fixture(['wpstarter' => ['wp-config-autoload' => false]]);
        $run = $this->migrate();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $config = json_decode((string) file_get_contents($this->root . '/sympress-runtime.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($config['wp-config-autoload']);
    }

    #[Group('PAR-SYM-008')]
    public function testStaticFindingsIdentifyLegacyAndComposerImportsWithoutRunningProviders(): void
    {
        $this->fixture(['wpstarter' => []]);
        $this->write('steps/Custom.php', <<<'PHP'
<?php
use WeCodeMore\WpStarter\Util\Locator;
use Composer\Composer;
use WeCodeMore\WpStarter\Step\Steps;
// WeCodeMore\WpStarter\Ignored\Comment
file_put_contents(__DIR__ . '/executed', 'wrong');
wpstarter_getenv('PRIVATE_PASSWORD');
PHP);
        $this->write('vendor/composer/installed.php', '<?php file_put_contents(__DIR__ . "/executed", "wrong"); return [];');
        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"fixture/extension","type":"wpstarter-extension","install-path":"../fixture/extension"}]}');
        $this->write('vendor/fixture/extension/Step.php', '<?php use WeCodeMore\WpStarter\Step\Step;');
        $run = $this->migrate(['--dry-run']);
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $report = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(5, $report['findings']);
        self::assertFalse($report['ready']);
        self::assertSame(2, $report['findings'][0]['line']);
        self::assertSame('SymPress\Runtime\Services', $report['findings'][0]['replacement']);
        self::assertStringContainsString('Manual rewrite', $report['findings'][2]['guidance']);
        self::assertSame('sympress_runtime_getenv', $report['findings'][3]['replacement']);
        self::assertStringNotContainsString('PRIVATE_PASSWORD', $run->getOutput());
        self::assertFileDoesNotExist($this->root . '/steps/executed');
        self::assertFileDoesNotExist($this->root . '/vendor/composer/executed');
    }

    #[Group('PAR-SYM-008')]
    public function testLegacyNamespaceRelativeReferencesInExternalProviderAreExplained(): void
    {
        $this->fixture(['wpstarter' => ['wp-cli-commands' => 'vendor/fixture/provider.php']]);
        $this->write('vendor/fixture/provider.php', '<?php namespace WeCodeMore\WpStarter; $env = new Env\WordPressEnvBridge(); $env->read(Util\DbChecker::WPDB_EXISTS); return [];');
        $run = $this->migrate(['--dry-run']);
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $report = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['ready']);
        self::assertSame(['', 'SymPress\Runtime\Env\EnvReader', 'SymPress\Runtime\Database\DbChecker'], array_column($report['findings'], 'replacement'));
    }
}
