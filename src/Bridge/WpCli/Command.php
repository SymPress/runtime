<?php

declare(strict_types=1);

namespace SymPress\Runtime\Bridge\WpCli;

use RuntimeException;

/** @internal */
final readonly class Command
{
    public function __construct(private string $binary, private string $root)
    {
    }

    /**
     * Run Runtime before WordPress loads, in the same isolated process as the binary.
     *
     * @param list<string> $arguments
     * @param array<string, string|bool> $options
     */
    public function __invoke(array $arguments, array $options): void
    {
        if (!class_exists('WP_CLI', false)) {
            throw new RuntimeException('The WP-CLI bridge must run inside WP-CLI.');
        }
        // WP-CLI reserves --json and passes it to commands as --format=json.
        if (($options['format'] ?? null) === 'json') {
            unset($options['format']);
            $options['json'] = true;
        }
        $command = [PHP_BINARY, $this->binary, ...$arguments];
        foreach ($options as $name => $value) {
            if ($value === false) {
                $command[] = '--no-' . $name;
                continue;
            }
            $command[] = '--' . $name . ($value === true ? '' : '=' . $value);
        }
        // Diagnostics must not prompt when invoked by another command runner.
        $command[] = '--no-interaction';
        $pipes = [];
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the Runtime process.');
        }
        \WP_CLI::halt(proc_close($process));
    }
}
