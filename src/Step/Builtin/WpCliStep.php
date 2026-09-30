<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\ConditionalStepInterface;
use Symfony\Component\Console\Input\StringInput;
use Throwable;

final class WpCliStep implements ConditionalStepInterface
{
    /** @var list<list<string>>|null */
    private ?array $commands = null;
    /** @var list<array{file: string, args: list<string>, skip-wordpress: bool}> */
    private array $files = [];

    public function __construct(private readonly Services $services)
    {
    }

    public function name(): string
    {
        return 'wpcli';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        $this->resolve($config);

        return $this->commands !== [] || $this->files !== [];
    }

    public function run(Config $config, Paths $paths): int
    {
        $this->resolve($config);
        $process = $this->services->wpCliProcess();
        $io = $this->services->io();
        $version = $io->isVerbose() ? $process->execute(['cli', 'version']) : $process->executeSilently(['cli', 'version']);
        if (!$version) {
            return self::ERROR;
        }
        $commands = [];
        foreach ($this->files as $file) {
            if (!is_file($file['file']) || !is_readable($file['file'])) {
                $io->comment('WP-CLI eval-file source is missing; skipping ' . basename($file['file']) . '.');
                continue;
            }
            $command = ['eval-file', $file['file'], ...$file['args']];
            if ($file['skip-wordpress']) {
                $command[] = '--skip-wordpress';
            }
            $commands[] = $command;
        }
        array_push($commands, ...($this->commands ?? []));
        $io->verbose('Running ' . count($commands) . ' WP-CLI commands.');
        foreach ($commands as $index => $command) {
            $io->comment('Running WP-CLI command ' . ($index + 1) . '.');
            if (!$process->execute($command)) {
                return self::ERROR;
            }
        }

        return self::SUCCESS;
    }

    private function resolve(Config $config): void
    {
        if ($this->commands !== null) {
            return;
        }
        $value = $config['wp-cli-commands']->unwrapOrFallback([]);
        if (is_string($value)) {
            $value = $this->provider($value);
        }
        if (!is_array($value)) {
            throw new RuntimeException('WP-CLI command provider must return an array.');
        }
        $commands = [];
        foreach ($value as $command) {
            if (!is_string($command) || !str_starts_with($command, 'wp ')) {
                throw new RuntimeException('WP-CLI commands must be strings starting with "wp ".');
            }
            try {
                $arguments = (new StringInput(substr($command, 3)))->getRawTokens();
            } catch (Throwable) {
                throw new RuntimeException('WP-CLI command has invalid quoting.');
            }
            if ($arguments === []) {
                throw new RuntimeException('WP-CLI command must not be empty.');
            }
            $commands[] = $arguments;
        }
        $files = $config['wp-cli-files']->unwrapOrFallback([]);
        if (!is_array($files)) {
            throw new RuntimeException('WP-CLI files must be an array.');
        }
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['file'] ?? null) || !is_array($file['args'] ?? null) || !is_bool($file['skip-wordpress'] ?? null)) {
                throw new RuntimeException('WP-CLI file descriptor is invalid.');
            }
            foreach ($file['args'] as $argument) {
                if (!is_string($argument)) {
                    throw new RuntimeException('WP-CLI file arguments must be strings.');
                }
            }
            $this->files[] = ['file' => $file['file'], 'args' => array_values($file['args']), 'skip-wordpress' => $file['skip-wordpress']];
        }
        $this->commands = $commands;
    }

    private function provider(string $file): mixed
    {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
            try {
                return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new RuntimeException('WP-CLI JSON command provider is invalid.');
            }
        }
        // phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Legacy PHP providers receive a scoped service facade.
        $present = array_key_exists('locator', $GLOBALS);
        $previous = $GLOBALS['locator'] ?? null;
        $GLOBALS['locator'] = $this->services;
        try {
            return (static function (string $path, Services $services): mixed {
                // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Required providers can access this legacy local variable.
                $locator = $services;

                return require $path;
            })($file, $this->services);
        } catch (Throwable) {
            throw new RuntimeException('WP-CLI PHP command provider failed.');
        } finally {
            unset($GLOBALS['locator']);
            if ($present) {
                $GLOBALS['locator'] = $previous;
            }
        }
        // phpcs:enable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable
    }

    public function success(): string
    {
        return 'WP-CLI commands executed.';
    }

    public function error(): string
    {
        return 'WP-CLI execution failed; remaining commands were not run.';
    }

    public function conditionsNotMet(): string
    {
        return 'No WP-CLI commands or files were configured.';
    }
}
