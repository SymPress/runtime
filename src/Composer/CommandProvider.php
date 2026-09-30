<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/** @internal */
final class CommandProvider implements CommandProviderCapability
{
    /** @return list<Command> */
    public function getCommands(): array
    {
        return [new Command(), new Command('validate'), new Command('flush-env-cache'), new Command('dump-env'), new Command('doctor'), new Command('check'), new Command('migrate'), new Command('prune'), new Command('env:diff')];
    }
}
