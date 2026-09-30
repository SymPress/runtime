<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Download\DownloadLock;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class DownloadLockStepsTest extends TemporaryProject
{
    /** @param list<string> $arguments */
    private function execute(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '-n', ...$arguments], $this->root, ['COMPOSER_VENDOR_DIR' => false, 'COMPOSER' => false]);
        $process->run();

        return $process;
    }

    public function testDropinsAndRemoteEnvironmentExamplesRejectDriftAndUpdateSelectedUrls(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->write('http/dropin', 'first dropin');
        $this->write('http/example', 'FIRST=example');
        $dropin = 'http://' . $address . '/dropin';
        $example = 'http://' . $address . '/example';
        $this->write('composer.json', json_encode([
        'extra' => [
        'sympress-runtime' => [
            'require-wp' => false,
            'db-check' => false,
            'allow-insecure-downloads' => true,
            'dropins' => ['object-cache.php' => $dropin],
            'env-example' => $example,
        ],
        ],
        ], JSON_THROW_ON_ERROR));
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $server = new Process([PHP_BINARY, '-S', $address, '-t', $this->root . '/http'], $this->root);
        $server->start();
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $probe = @stream_socket_client('tcp://' . $address, timeout: 0.05);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            self::assertTrue($ready, $server->getErrorOutput());
            $first = $this->execute(['dropins', 'envexample']);
            self::assertSame(0, $first->getExitCode(), $first->getOutput() . $first->getErrorOutput());
            $paths = new Paths($this->root);
            $lock = new DownloadLock($paths, new ProjectBoundary($paths));
            self::assertCount(2, $lock->entries());
            $this->write('http/dropin', 'changed dropin');
            $this->write('http/example', 'CHANGED=example');
            $drift = $this->execute(['--force', 'dropins', 'envexample']);
            self::assertSame(1, $drift->getExitCode(), $drift->getOutput() . $drift->getErrorOutput());
            self::assertSame('first dropin', file_get_contents($paths->wpContent('object-cache.php')));
            self::assertStringContainsString('FIRST=example', (string) file_get_contents($this->root . '/.env.example'));
            $updated = $this->execute(['--update-lock', '--force', 'dropins']);
            self::assertSame(0, $updated->getExitCode(), $updated->getOutput() . $updated->getErrorOutput());
            self::assertSame('changed dropin', file_get_contents($paths->wpContent('object-cache.php')));
            self::assertSame(hash('sha256', 'changed dropin'), $lock->entries()[hash('sha256', $dropin)]);
            self::assertSame(hash('sha256', 'FIRST=example'), $lock->entries()[hash('sha256', $example)]);
            self::assertStringContainsString('FIRST=example', (string) file_get_contents($this->root . '/.env.example'));
        } finally {
            $server->stop(1);
        }
    }
}
