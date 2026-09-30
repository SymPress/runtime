<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class LayoutJournalCrashTest extends TemporaryProject
{
    /** @return iterable<string, array{string}> */
    public static function phases(): iterable
    {
        foreach (['prepared', 'move-intent', 'moved', 'metadata', 'binaries', 'state', 'committed'] as $phase) {
            yield $phase => [$phase];
        }
    }

    private function fixture(): void
    {
        $this->write('composer.json', json_encode(['name' => 'fixture/site', 'version' => '1.0.0', 'require' => ['composer/installers' => '*', 'private/plugin' => '*'], 'extra' => ['sympress-runtime' => ['require-wp' => false, 'db-check' => false]]], JSON_THROW_ON_ERROR));
        $this->write('vendor/composer/installers/marker', 'installer');
        $this->write('vendor/private/plugin/user-data', 'precious');
        $this->write('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'composer/installers', 'version' => '1.0.0', 'type' => 'composer-plugin', 'install-path' => '../composer/installers'], ['name' => 'private/plugin', 'version' => '1.0.0', 'type' => 'wordpress-plugin', 'install-path' => '../private/plugin']], 'dev' => false, 'dev-package-names' => []], JSON_THROW_ON_ERROR));
    }

    private function runPreparation(?string $kill = null, string $manifest = 'composer.json'): Process
    {
        $callback = $kill === null ? '' : 'static function (string $phase): void { if ($phase === ' . var_export($kill, true) . ') { posix_kill(getmypid(), 9); } }';
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; (new SymPress\\Runtime\\Composer\\PackageLayout(' . $callback . '))->prepare(getcwd(), getcwd() . "/vendor", getcwd() . ' . var_export('/' . $manifest, true) . ');';
        $process = new Process([PHP_BINARY, '-r', $code], $this->root);
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessSignaledException) {
            // The child is deliberately terminated without PHP shutdown/exception handlers.
        }
        return $process;
    }

    #[DataProvider('phases')]
    public function testNextInvocationRecoversAfterRealSigkill(string $phase): void
    {
        $this->fixture();
        $killed = $this->runPreparation($phase);
        self::assertTrue($killed->hasBeenSignaled());
        self::assertFileExists($this->root . '/var/runtime/package-layout.pending.json');
        self::assertSame(0600, fileperms($this->root . '/var/runtime/package-layout.pending.json') & 0777);
        $recovered = $this->runPreparation();
        self::assertSame(0, $recovered->getExitCode(), $recovered->getErrorOutput());
        self::assertSame('precious', file_get_contents($this->root . '/wp-content/plugins/plugin/user-data'));
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.pending.json');
    }

    #[DataProvider('phases')]
    public function testNestedManifestAndCustomBinariesRecoverAfterSigkill(string $phase): void
    {
        $this->fixture();
        $manifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['config']['bin-dir'] = 'tools/bin';
        $this->write('config/site.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        unlink($this->root . '/composer.json');
        $metadata = json_decode((string) file_get_contents($this->root . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        $metadata['packages'][1]['bin'] = ['tool.php'];
        $this->write('vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->write('vendor/private/plugin/tool.php', '<?php echo "binary-data";');
        $killed = $this->runPreparation($phase, 'config/site.json');
        self::assertTrue($killed->hasBeenSignaled(), $killed->getErrorOutput());
        $recovered = $this->runPreparation(manifest: 'config/site.json');
        self::assertSame(0, $recovered->getExitCode(), $recovered->getErrorOutput());
        self::assertSame('precious', file_get_contents($this->root . '/wp-content/plugins/plugin/user-data'));
        self::assertFileExists($this->root . '/tools/bin/tool.php');
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.pending.json');
    }

    public function testRecoveryRejectsADifferentManifestBeforeMutation(): void
    {
        $this->fixture();
        $this->write('config/site.json', (string) file_get_contents($this->root . '/composer.json'));
        $this->runPreparation('moved', 'config/site.json');
        $rejected = $this->runPreparation();
        self::assertNotSame(0, $rejected->getExitCode());
        self::assertFileExists($this->root . '/wp-content/plugins/plugin/user-data');
        self::assertFileExists($this->root . '/var/runtime/package-layout.pending.json');
        self::assertSame(0, $this->runPreparation(manifest: 'config/site.json')->getExitCode());
    }

    public function testRollbackCanItselfBeKilledAndResumed(): void
    {
        $this->fixture();
        $this->runPreparation('metadata');
        self::assertTrue($this->runPreparation('rollback-moved')->hasBeenSignaled());
        $process = $this->runPreparation();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('precious', file_get_contents($this->root . '/wp-content/plugins/plugin/user-data'));
    }

    #[DataProvider('phases')]
    public function testInterruptedUpdateRetainsOriginalPackageBackup(string $phase): void
    {
        $this->fixture();
        self::assertSame(0, $this->runPreparation()->getExitCode());
        $metadata = json_decode((string) file_get_contents($this->root . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($metadata['packages'] as &$package) {
            if ($package['name'] !== 'private/plugin') {
                continue;
            }

            $package['version'] = '2.0.0';
            $package['install-path'] = '../private/plugin';
        }
        unset($package);
        $this->write('vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->write('vendor/private/plugin/user-data', 'updated');
        self::assertTrue($this->runPreparation($phase)->hasBeenSignaled());
        $recovered = $this->runPreparation();
        self::assertSame(0, $recovered->getExitCode(), $recovered->getErrorOutput());
        self::assertSame('updated', file_get_contents($this->root . '/wp-content/plugins/plugin/user-data'));
        $backups = glob($this->root . '/var/runtime/package-backups/*/user-data');
        self::assertCount(1, $backups);
        self::assertSame('precious', file_get_contents($backups[0]));
        self::assertFileExists(dirname($backups[0]) . '.json');
        self::assertSame(0700, fileperms(dirname($backups[0], 2)) & 0777);
        self::assertSame(0600, fileperms(dirname($backups[0]) . '.json') & 0777);
    }

    public function testInterruptionBetweenLinkCreationAndSourceRemovalIsRecoverable(): void
    {
        $this->fixture();
        rename($this->root . '/vendor/private/plugin', $this->root . '/source-plugin');
        symlink('../../source-plugin', $this->root . '/vendor/private/plugin');
        self::assertTrue($this->runPreparation('link-created')->hasBeenSignaled());
        self::assertTrue(is_link($this->root . '/vendor/private/plugin'));
        self::assertTrue(is_link($this->root . '/wp-content/plugins/plugin'));
        $process = $this->runPreparation();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('precious', file_get_contents($this->root . '/source-plugin/user-data'));
        self::assertSame(realpath($this->root . '/source-plugin'), realpath($this->root . '/wp-content/plugins/plugin'));
    }

    public function testMetadataRollbackCanBeInterruptedAndResumed(): void
    {
        $this->fixture();
        $original = file_get_contents($this->root . '/vendor/composer/installed.json');
        $this->runPreparation('metadata');
        self::assertTrue($this->runPreparation('rollback-metadata')->hasBeenSignaled());
        $process = $this->runPreparation();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertNotSame($original, file_get_contents($this->root . '/vendor/composer/installed.json'));
        self::assertSame('precious', file_get_contents($this->root . '/wp-content/plugins/plugin/user-data'));
    }

    public function testFailedPrivateModeStopsBeforeWritingSnapshotContents(): void
    {
        $this->fixture();
        $code = 'namespace SymPress\\Runtime\\Composer { function chmod(string $path, int $mode): bool { return false; } } namespace { require '
            . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; (new SymPress\\Runtime\\Composer\\PackageLayout())->prepare(getcwd(), getcwd() . "/vendor", getcwd() . "/composer.json"); }';
        $process = new Process([PHP_BINARY, '-r', $code], $this->root);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Cannot protect journal metadata before writing', $process->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.pending.json');
        foreach (glob($this->root . '/var/runtime/package-layout.pending.json.tmp-*') ?: [] as $temporary) {
            self::assertSame('', file_get_contents($temporary));
        }
        self::assertSame('precious', file_get_contents($this->root . '/vendor/private/plugin/user-data'));
    }

    public function testCorruptJournalFailsWithoutTouchingFiles(): void
    {
        $this->fixture();
        $this->write('var/runtime/package-layout.pending.json', '{broken');
        $process = $this->runPreparation();
        self::assertNotSame(0, $process->getExitCode());
        self::assertSame('precious', file_get_contents($this->root . '/vendor/private/plugin/user-data'));
        self::assertSame('{broken', file_get_contents($this->root . '/var/runtime/package-layout.pending.json'));
    }

    public function testJournalRejectsOutOfProjectSnapshotBeforeMutations(): void
    {
        $this->fixture();
        $this->runPreparation('moved');
        $file = $this->root . '/var/runtime/package-layout.pending.json';
        $envelope = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $record = json_decode($envelope['data'], true, flags: JSON_THROW_ON_ERROR);
        $record['snapshots']['/tmp/unrelated'] = ['contents' => null, 'link' => null, 'mode' => 0600];
        $data = json_encode($record, JSON_THROW_ON_ERROR);
        file_put_contents($file, json_encode(['data' => $data, 'sha256' => hash('sha256', $data)], JSON_THROW_ON_ERROR));
        self::assertNotSame(0, $this->runPreparation()->getExitCode());
        self::assertSame('precious', file_get_contents($this->root . '/wp-content/plugins/plugin/user-data'));
        self::assertFileExists($file);
    }
}
