<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Console\Io;

interface PostProcessStepInterface extends StepInterface
{
    public function postProcess(Io $io): void;
}
