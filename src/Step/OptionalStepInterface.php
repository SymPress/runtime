<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;

/** @api */
interface OptionalStepInterface extends StepInterface
{
    public const string ASK = 'ask';

    /** @api */
    public function askConfirm(Config $config, Io $io): bool;

    /** @api */
    public function skipped(): string;
}
