<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class OperationsCommandTest extends TemporaryProject
{
    /** @param array<string, mixed> $options */
    private function fixture(array $options = []): void
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('composer.json', json_encode(['extra' => ['sympress-runtime' => array_replace(['require-wp' => false, 'db-check' => false], $options)]], JSON_THROW_ON_ERROR));
        $this->write('wordpress/.keep', '');
    }

    private function command(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/sympress-runtime', '-n', ...$arguments], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false]);
        $process->run();

        return $process;
    }

    public function testDriftBaselineDetectsArtifactAndConfigurationChangesWithoutWriting(): void
    {
        $this->fixture();
        self::assertSame(2, $this->command('--check', 'index')->getExitCode());
        self::assertDirectoryDoesNotExist($this->root . '/var');
        $run = $this->command('index');
        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        $check = $this->command('--check', 'index');
        self::assertSame(0, $check->getExitCode(), $check->getOutput() . $check->getErrorOutput());
        $original = (string) file_get_contents($this->root . '/index.php');
        $this->write('index.php', '<?php // changed outside setup');
        $drift = $this->command('--check', 'index');
        self::assertSame(1, $drift->getExitCode(), $drift->getOutput());
        self::assertStringContainsString('artifact-changed', $drift->getOutput());
        self::assertSame('<?php // changed outside setup', file_get_contents($this->root . '/index.php'));
        self::assertSame(0, $this->command('--dry-run', 'index')->getExitCode());
        $this->write('index.php', $original);
        $this->fixture(['generated-file-mode' => '0640']);
        $changed = $this->command('--check', 'index');
        self::assertSame(1, $changed->getExitCode());
        self::assertStringContainsString('setup-inputs-changed', $changed->getOutput());
    }

    public function testPreviewNeverRunsProviderAndRejectsLockMutation(): void
    {
        $this->fixture(['autoload' => 'provider.php']);
        $this->write('provider.php', '<?php file_put_contents(__DIR__ . "/provider-executed", "bad");');
        $check = $this->command('--dry-run', 'index');
        self::assertSame(2, $check->getExitCode(), $check->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/provider-executed');
        self::assertNotSame(0, $this->command('--check', '--update-lock')->getExitCode());
    }

    public function testRequiredEnvironmentIsCheckedByValidateAndDumpWithoutExposingValues(): void
    {
        $this->fixture(['required-env' => ['SMTP_PORT' => 'int', 'PROJECT_NAME' => 'string']]);
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nSMTP_PORT=private-invalid-value\nPROJECT_NAME=fixture\n");
        foreach ([['validate'], ['dump-env', 'production']] as $arguments) {
            $run = $this->command(...$arguments);
            self::assertNotSame(0, $run->getExitCode());
            self::assertStringContainsString('SMTP_PORT', $run->getOutput() . $run->getErrorOutput());
            self::assertStringNotContainsString('private-invalid-value', $run->getOutput() . $run->getErrorOutput());
        }
        self::assertFileDoesNotExist($this->root . '/.env.dump.php');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nSMTP_PORT=587\nPROJECT_NAME=fixture\n");
        self::assertSame(0, $this->command('validate')->getExitCode());
        self::assertSame(0, $this->command('dump-env', 'production')->getExitCode());
    }

    public function testEnvironmentDiffReportsNamesWithoutExecutingValues(): void
    {
        $this->fixture();
        $this->write('.env.example', "DB_NAME=example\nSMTP_PORT=25\n");
        $this->write('.env', "DB_NAME=private-database\nEXTRA=\$(touch " . $this->root . "/executed)\n");
        $run = $this->command('env:diff', '--json');
        self::assertSame(1, $run->getExitCode(), $run->getErrorOutput());
        $report = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['SMTP_PORT'], $report['missing']);
        self::assertSame(['EXTRA'], $report['extra']);
        self::assertStringNotContainsString('private-database', $run->getOutput());
        self::assertFileDoesNotExist($this->root . '/executed');
    }

    public function testGeneratedModesAndRequirementTypesAreValidated(): void
    {
        foreach (['0644', '0777', 640] as $mode) {
            $this->fixture(['generated-file-mode' => $mode]);
            self::assertNotSame(0, $this->command('validate')->getExitCode());
        }
        $this->fixture(['required-env' => ['SMTP_PORT' => 'object']]);
        self::assertNotSame(0, $this->command('validate')->getExitCode());
    }
}
