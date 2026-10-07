<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Database\DbChecker;
use SymPress\Runtime\Database\MysqliProbe;
use SymPress\Runtime\Env\EnvRequirements;
use SymPress\Runtime\EnvironmentInspection;
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

/** @internal */
final readonly class Doctor
{
    public function __construct(private Config $config, private Paths $paths, private RunContext $context, private Io $io)
    {
    }

    /** @return array{environment: ?string, checks: list<array{id: string, status: string, detail: string}>, exit: int} */
    public function inspect(bool $production = false, bool $databaseHealth = false, bool $quick = false, ?string $phpUser = null, ?string $webroot = null): array
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
            $commands = (new EnvironmentFiles($this->config, $this->paths))->containsCommands();
            if ($commands && !$dump) {
                $record('environment.commands', $production ? 'fail' : 'unknown', 'Shell substitutions require a deployment dump; diagnostics do not execute them.');
                throw new RuntimeException('Cannot safely inspect shell substitutions without a dump.');
            }
            $profile = $this->config['compatibility-profile']->unwrap();
            if (!is_string($profile)) {
                throw new RuntimeException('Invalid compatibility profile.');
            }
            [$env] = EnvironmentInspection::loadReader($this->config, $this->paths);
            $kernel = new KernelPaths($env, $this->paths, $webroot);
            $canonical = $kernel->environment();
            if ($production || in_array($canonical, ['staging', 'production'], true)) {
                $home = $env->rawValue('WP_HOME');
                $record('environment.home', is_string($home) && filter_var($home, FILTER_VALIDATE_URL) !== false ? 'pass' : 'fail', 'Staging and production require an explicit WP_HOME.');
            }
            $required = $this->config['required-env']->unwrapOrFallback([]);
            $requirementErrors = (new EnvRequirements())->validate($env, is_array($required) ? $required : []);
            $record('environment.required', $requirementErrors === [] ? 'pass' : 'fail', $requirementErrors === [] ? 'Declared environment requirements are satisfied.' : implode(' ', $requirementErrors));
            if ($production) {
                array_push($checks, ...(new ProductionChecks($this->config, $this->paths))->inspect($env, $dump, $phpUser, $webroot));
            }
            if (!$dump && is_string($environmentDirectory) && !is_writable($environmentDirectory)) {
                $record('environment.cache', 'warning', 'The environment directory is read-only; automatic cache writes are disabled. Build a deployment dump.');
            }
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
            if ($skipDatabase && !$databaseHealth) {
                $record('database', 'unknown', 'Database inspection is disabled by configuration.');
            }
            if (!$skipDatabase || $databaseHealth) {
                $quiet = new Io(new ArrayInput([]), new NullOutput());
                $database = new DbChecker($env, $quiet, new SystemProcess($this->paths, $quiet), new ExecutableFinder(), new MysqliProbe());
                $status = $database->status();
                $state = !$status->envValid || $status->exists === false || $status->installed === false ? 'fail' : ($status->exists === null || $status->installed === null ? 'unknown' : 'pass');
                $record('database', $state, 'Database status: ' . $status->reason . '.');
                if ($databaseHealth) {
                    $record('database.health', $database->mysqlcheck($quick) ? 'pass' : 'fail', $quick ? 'Explicit quick table inspection.' : 'Explicit complete table inspection.');
                }
            }
            $packages = new PackageFinder($this->context);
            $hasKernel = $packages->findByName('sympress/kernel') !== null;
            $kernelDirectories = [];
            foreach (['cache', 'build'] as $id) {
                if (!$hasKernel) {
                    $record('kernel.' . $id, 'not-applicable', 'sympress/kernel is not installed.');
                    $record('permissions.kernel-' . $id, 'not-applicable', 'sympress/kernel is not installed; its directories are unused.');
                    continue;
                }
                try {
                    $path = $id === 'cache' ? $kernel->cache() : $kernel->build();
                    $kernel->assertSafe($path, $id, readOnly: true);
                    $kernelDirectories['kernel-' . $id] = $path;
                    $record('kernel.' . $id, is_dir($path) && is_readable($path) ? 'pass' : 'unknown', is_dir($path) ? 'Selected kernel directory is readable.' : 'Selected kernel directory has not been created; run cache warmup as the PHP-FPM user.');
                } catch (Throwable) {
                    $record('kernel.' . $id, 'fail', 'Unsafe kernel directory. Configure APP_CACHE_DIR/APP_BUILD_DIR outside the webroot without symlinks or group/world write access. External roots require mode 0700. Create and warm them as the PHP-FPM user.');
                    $record('permissions.kernel-' . $id, 'fail', 'Unsafe kernel directory cannot be used for cache operations.');
                }
            }
            if ($hasKernel) {
                if (isset($kernelDirectories['kernel-cache']) && $kernel->usesFallback()) {
                    $record('kernel.cache-migration', 'warning', 'Kernel selected its private per-user fallback because the implicit cache is unsafe, public or unavailable. Configure a durable APP_CACHE_DIR shared by CLI and PHP-FPM and warm it as the PHP-FPM user. Diagnostics do not create or migrate directories.');
                }
                try {
                    $owners = (new BootOwnership($packages, new MuPluginList($packages, $this->paths), $this->config, $this->paths))->providers();
                    $generated = is_file($this->paths->wpContent('mu-plugins/sympress-runtime-kernel.php'));
                    $record('kernel.boot', $owners !== [] || $generated ? 'pass' : 'unknown', $owners !== [] || $generated ? 'A kernel boot entry is present.' : 'No kernel boot owner was identified.');
                } catch (Throwable) {
                    $record('kernel.boot', 'fail', 'Kernel boot ownership is ambiguous.');
                }
            }
            $configuration = $this->config['wp-config-php-path']->unwrapOrFallback($this->config['compatibility-profile']->is('upstream-dev') ? $this->paths->root('wp-config.php') : $this->paths->wpParent('wp-config.php'));
            if (!is_string($configuration)) {
                throw new RuntimeException('Invalid configuration path.');
            }
            foreach (['configuration' => $configuration, 'content' => $this->paths->wpContent(), ...$kernelDirectories] as $id => $path) {
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

    public function run(bool $json = false, bool $production = false, bool $databaseHealth = false, bool $quick = false, ?string $phpUser = null, ?string $webroot = null): int
    {
        $report = $this->inspect($production, $databaseHealth, $quick, $phpUser, $webroot);
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
