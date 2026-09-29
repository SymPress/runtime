<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\Paths;

final readonly class PhpToolProcess
{
    public function __construct(private PhpProcess $php, private PhpTool $tool, private string $toolPath, private Paths $paths, private Io $io)
    {
    }

    /** @param array<string, string|false> $environment */
    public function withEnvironment(array $environment): self
    {
        $this->php->withEnvironment($environment);

        return $this;
    }

    /** @param list<string>|string $command */
    public function execute(array|string $command): bool
    {
        return $this->php->execute($this->prepare($command));
    }

    /** @param list<string>|string $command */
    public function executeSilently(array|string $command, ?string $cwd = null): bool
    {
        return $this->php->executeSilently($this->prepare($command), $cwd);
    }

    /** @param list<string>|string $command */
    private function prepare(array|string $command): string
    {
        $arguments = is_array($command) ? implode(' ', array_map(escapeshellarg(...), $command)) : $command;

        return $this->tool->prepareCommand(escapeshellarg($this->toolPath) . ' ' . $arguments, $this->paths, $this->io);
    }
}
