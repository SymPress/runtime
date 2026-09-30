<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;

/**
 * Host boundary: only PHP standard library and immutable context data.
 *
 * @internal
 */
final class RunnerProcess
{
    /** @param list<string> $arguments */
    public function run(RunContext $context, array $arguments = []): int
    {
        $file = ContextFile::create($context);
        try {
            $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '--runtime-context=' . $file, ...$arguments];
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $context->root, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start the isolated runtime process.');
            }
            $previous = [];
            if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
                $async = pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM] as $signal) {
                    $previous[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function (int $received) use ($process): void {
                        proc_terminate($process, $received);
                    });
                }
            }
            try {
                // proc_close blocks PHP signal dispatch while the child is running.
                while (true) {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(10000);
                }
                $closed = proc_close($process);
                if ($status['signaled']) {
                    return 128 + $status['termsig'];
                }

                return $status['exitcode'] >= 0 ? $status['exitcode'] : ($closed >= 0 ? $closed : 1);
            } finally {
                foreach ($previous as $signal => $handler) {
                    pcntl_signal($signal, $handler);
                }
                if (isset($async)) {
                    pcntl_async_signals($async);
                }
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
