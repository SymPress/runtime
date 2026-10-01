<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use RuntimeException;

/**
 * PHP-only process boundary before project autoload files are executed.
 *
 * @internal
 */
final class LayoutBootstrap
{
    /** @param list<string> $arguments */
    public static function prepare(string $root, string $vendor, string $manifest, array $arguments): void
    {
        $firstArgument = true;
        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--') {
                $firstArgument = false;
            }
            if ($firstArgument && !str_starts_with($argument, '-')) {
                if (in_array($argument, ['list', 'help', 'completion', '_complete'], true)) {
                    return;
                }
                $firstArgument = false;
            }
            if (in_array($argument, ['--help', '-h', '--version', '-V', '--list-steps', 'validate', 'doctor', 'check', 'migrate', 'dump-env', 'flush-env-cache', '--dry-run', '--check', 'prune', 'env:diff'], true)) {
                return;
            }
        }
        $file = $vendor . '/composer/installed.json';
        $contents = is_file($file) ? file_get_contents($file) : false;
        $pending = file_exists($root . '/var/runtime/package-layout.pending.json') || is_link($root . '/var/runtime/package-layout.pending.json');
        if (!$pending && ($contents === false || !preg_match('/"type"\s*:\s*"wordpress-(?:core|plugin|muplugin|theme|dropin)"/', $contents))) {
            return;
        }
        $metadata = $pending ? [] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $packages = is_array($metadata) ? ($metadata['packages'] ?? []) : [];
        $names = is_array($packages) ? array_column($packages, 'name') : [];
        if (!$pending && !in_array('composer/installers', $names, true) && !in_array('johnpbloch/wordpress-core-installer', $names, true)) {
            return;
        }
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/prepare-layout.php', $root, $vendor, $manifest, ...array_slice($arguments, 1)];
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start offline package layout preparation.');
        }
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Offline package layout preparation failed; setup was not started.');
        }
    }
}
