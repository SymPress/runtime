<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

final readonly class PhpProcess
{
    public function __construct(private SystemProcess $process, private string $executable = PHP_BINARY)
    {
    }

    public function withExecutable(string $executable): self
    {
        return new self(clone $this->process, $executable);
    }

    /** @param array<string, string|false> $environment */
    public function withEnvironment(array $environment): self
    {
        $this->process->withEnvironment($environment);

        return $this;
    }

    /** @param list<string>|string $command */
    public function execute(array|string $command, ?string $cwd = null, int $verbosity = 32): bool
    {
        return $this->process->execute($this->command($command), $cwd, $verbosity);
    }

    /** @param list<string>|string $command */
    public function executeSilently(array|string $command, ?string $cwd = null): bool
    {
        return $this->process->executeSilently($this->command($command), $cwd);
    }

    /**
     * @param list<string>|string $command
     * @return list<string>|string
     */
    private function command(array|string $command): array|string
    {
        return is_array($command) ? [$this->executable, ...$command] : escapeshellarg($this->executable) . ' ' . $command;
    }
}
