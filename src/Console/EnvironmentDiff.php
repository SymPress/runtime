<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;

final readonly class EnvironmentDiff
{
    public function __construct(private Config $config, private Paths $paths, private Io $io)
    {
    }

    public function run(bool $json = false): int
    {
        $directory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
        $name = $this->config['env-file']->unwrap();
        if (!is_string($directory) || !is_string($name)) {
            throw new \RuntimeException('Invalid environment file configuration.');
        }
        $example = $directory . '/.env.example';
        if (!is_file($example) || !is_readable($example)) {
            $this->io->error('The environment example is missing or unreadable.');

            return 2;
        }
        $expected = EnvironmentFiles::names($example);
        $actual = [];
        foreach ((new EnvironmentFiles($this->config, $this->paths))->activeFiles() as $file) {
            array_push($actual, ...EnvironmentFiles::names($file));
        }
        $process = getenv();
        foreach (array_keys(is_array($process) ? $process : []) as $name) {
            if (!in_array($name, $expected, true) && !(str_ends_with($name, '_FILE') && in_array(substr($name, 0, -5), $expected, true))) {
                continue;
            }

            $actual[] = $name;
        }
        foreach ($actual as $name) {
            if (!str_ends_with($name, '_FILE')) {
                continue;
            }

            $actual[] = substr($name, 0, -5);
        }
        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_unique(array_diff($actual, $expected)));
        sort($missing);
        sort($extra);
        $report = ['missing' => $missing, 'extra' => $extra];
        if ($json) {
            $this->io->write(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return $missing === [] ? 0 : 1;
        }
        $this->io->write('Missing: ' . implode(', ', $missing));
        $this->io->write('Extra: ' . implode(', ', $extra));

        return $missing === [] ? 0 : 1;
    }
}
