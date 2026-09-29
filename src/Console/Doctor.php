<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Database\MysqliProbe;
use SymPress\Runtime\Env\EnvFactory;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Kernel\BootOwnership;
use SymPress\Runtime\Kernel\KernelPaths;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Process\SystemProcess;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

final readonly class Doctor
{
    public function __construct(private Config $config, private Paths $paths, private RunContext $context, private Io $io)
    {
    }

    /** @return array{environment: ?string, checks: list<array{id: string, status: string, detail: string}>, exit: int} */
    public function inspect(): array
    {
        $checks = [];
        $record = static function (string $id, string $status, string $detail) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $status, 'detail' => $detail];
        };
        foreach (['autoload' => $this->paths->vendor('autoload.php'), 'wordpress' => $this->paths->wp('wp-load.php')] as $id => $file) {
            $record('paths.' . $id, is_file($file) && is_readable($file) ? 'pass' : 'fail', 'Readable ' . $id . ' entry point.');
        }
        $record('paths.content', is_dir($this->paths->wpContent()) ? 'pass' : 'fail', 'WordPress content directory exists.');
        $boundary = new ProjectBoundary($this->paths);
        foreach (['wordpress' => $this->paths->wp(), 'content' => $this->paths->wpContent()] as $id => $path) {
            try {
                $boundary->assertWritablePath($path);
                $record('symlinks.' . $id, 'pass', 'Path resolves within the project.');
            } catch (Throwable) {
                $record('symlinks.' . $id, 'fail', 'Path is broken or resolves outside the project.');
            }
        }
        $canonical = null;
        try {
            $environmentDirectory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
            $dump = is_string($environmentDirectory) && is_readable($environmentDirectory . '/.env.dump.php');
            $profile = $this->config['compatibility-profile']->unwrap();
            if (!is_string($profile)) {
                throw new RuntimeException('Invalid compatibility profile.');
            }
            $env = $dump
                ? EnvReader::buildFromCacheDump($environmentDirectory . '/.env.dump.php', $profile)
                : (new EnvFactory($this->config, $this->paths))->create();
            $kernel = new KernelPaths($env, $this->paths);
            $canonical = $kernel->environment();
            $missing = [];
            foreach (['DB_NAME', 'DB_USER', 'DB_HOST', 'DB_PASSWORD'] as $name) {
                $value = $env->rawValue($name);
                if ($value !== null && ($name === 'DB_PASSWORD' || trim($value) !== '')) {
                    continue;
                }
                $missing[] = $name;
            }
            $record('environment', $missing === [] ? 'pass' : 'fail', $missing === [] ? 'Required database environment keys are present.' : 'Missing keys: ' . implode(', ', $missing));
            $skipDatabase = $this->config['skip-db-check']->is(true) || $this->config['db-check']->is(false);
            if ($skipDatabase) {
                $record('database', 'unknown', 'Database inspection is disabled by configuration.');
            }
            if (!$skipDatabase) {
                $quiet = new Io(new ArrayInput([]), new NullOutput());
                $database = new DbChecker($env, $quiet, new SystemProcess($this->paths, $quiet), new ExecutableFinder(), new MysqliProbe());
                $status = $database->status();
                $state = !$status->envValid || $status->exists === false || $status->installed === false ? 'fail' : ($status->exists === null || $status->installed === null ? 'unknown' : 'pass');
                $record('database', $state, 'Database status: ' . $status->reason . '.');
            }
            $packages = new PackageFinder($this->context);
            $hasKernel = $packages->findByName('sympress/kernel') !== null;
            if (!$hasKernel) {
                $record('kernel.cache', 'not-applicable', 'sympress/kernel is not installed.');
            }
            if ($hasKernel) {
                foreach (['cache' => $kernel->cache(), 'build' => $kernel->build()] as $id => $path) {
                    try {
                        $boundary->assertWritablePath($path);
                        $record('kernel.' . $id, is_dir($path) && is_readable($path) ? 'pass' : 'unknown', is_dir($path) ? 'Kernel directory is readable.' : 'Kernel directory has not been created.');
                    } catch (Throwable) {
                        $record('kernel.' . $id, 'fail', 'Kernel directory is unsafe for runtime cache operations.');
                    }
                }
                try {
                    $owners = (new BootOwnership($packages, new MuPluginList($packages, $this->paths), $this->config, $this->paths))->providers();
                    $generated = is_file($this->paths->wpContent('mu-plugins/sympress-runtime-kernel.php'));
                    $record('kernel.boot', $owners !== [] || $generated ? 'pass' : 'unknown', $owners !== [] || $generated ? 'A kernel boot entry is present.' : 'No kernel boot owner was identified.');
                } catch (Throwable) {
                    $record('kernel.boot', 'fail', 'Kernel boot ownership is ambiguous.');
                }
            }
            foreach (['configuration' => $this->paths->root('wp-config.php'), 'content' => $this->paths->wpContent(), 'kernel-cache' => $kernel->cache(), 'kernel-build' => $kernel->build()] as $id => $path) {
                $ancestor = $path;
                while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                    $ancestor = dirname($ancestor);
                }
                $writable = is_writable($ancestor);
                $readOnlyReady = $canonical === 'production' && $dump && is_readable($path);
                $record('permissions.' . $id, $writable || $readOnlyReady ? 'pass' : 'fail', $writable ? 'Target or nearest existing parent is writable.' : ($readOnlyReady ? 'Readable production artifact; environment dump is present.' : 'Target cannot be written and is not ready for read-only production.'));
            }
        } catch (Throwable) {
            $record('runtime-inspection', 'fail', 'Runtime environment or dependency inspection failed; values are redacted.');
        }
        $failed = array_any($checks, static fn (array $check): bool => $check['status'] === 'fail');
        $unknown = array_any($checks, static fn (array $check): bool => $check['status'] === 'unknown');

        return ['environment' => $canonical, 'checks' => $checks, 'exit' => $failed ? 1 : ($unknown ? 2 : 0)];
    }

    public function run(bool $json = false): int
    {
        $report = $this->inspect();
        if ($json) {
            $this->io->write(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $report['exit'];
        }
        foreach ($report['checks'] as $check) {
            $this->io->write($check['status'] . ' ' . $check['id'] . ': ' . $check['detail']);
        }

        return $report['exit'];
    }
}
