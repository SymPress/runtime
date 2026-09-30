<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;

interface OptionalStepInterface extends StepInterface
{
    public const string ASK = 'ask';

    public function askConfirm(Config $config, Io $io): bool;

    public function skipped(): string;
}
