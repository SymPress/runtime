<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Paths;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
final class SystemProcess
{
    /** @var array<string, string|false> */
    private array $environment = [];

    /** @internal */
    public function __construct(private readonly Paths $paths, private readonly Io $io)
    {
    }

    /**
     * @param array<string, string|false> $environment
     * @api
     */
    public function withEnvironment(array $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * @param list<string>|string $command
     * @api
     */
    public function execute(array|string $command, ?string $cwd = null, int $verbosity = 32): bool
    {
        if ($verbosity <= 16) {
            return $this->executeSilently($command, $cwd);
        }
        try {
            $this->process($command, $cwd)->mustRun(function (string $type, string $buffer): void {
                $this->io->raw($buffer, $type === Process::ERR);
            });

            return true;
        } catch (Throwable $error) {
            $code = $error instanceof ProcessFailedException ? $error->getProcess()->getExitCode() : null;
            $this->io->error($code === null ? 'Process could not be started or completed.' : 'Process failed with exit code ' . $code . '.');

            return false;
        }
    }

    /**
     * @param list<string>|string $command
     * @return array{string, string, bool, Throwable|null}
     * @api
     */
    public function executeCapturing(array|string $command, ?string $cwd = null): array
    {
        $out = '';
        $err = '';
        try {
            $this->process($command, $cwd)->mustRun(static function (string $type, string $buffer) use (&$out, &$err): void {
                if ($type === Process::ERR) {
                    $err .= $buffer;

                    return;
                }
                $out .= $buffer;
            });

            return [$out, $err, true, null];
        } catch (Throwable $error) {
            return [$out, $err, false, $error];
        }
    }

    /**
     * @param list<string>|string $command
     * @api
     */
    public function executeSilently(array|string $command, ?string $cwd = null): bool
    {
        try {
            $this->process($command, $cwd)->disableOutput()->mustRun();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string>|string $command */
    private function process(array|string $command, ?string $cwd): Process
    {
        // Strings explicitly retain the trusted custom-script shell API. Internal callers use argv.
        return is_array($command)
            ? new Process($command, $cwd ?? $this->paths->root(), $this->environment, timeout: null)
            : Process::fromShellCommandline($command, $cwd ?? $this->paths->root(), $this->environment, timeout: null);
    }
}
