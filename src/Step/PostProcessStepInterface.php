<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Console\Io;

/** @api */
interface PostProcessStepInterface extends StepInterface
{
    /** @api */
    public function postProcess(Io $io): void;
}
