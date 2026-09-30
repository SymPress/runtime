<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

interface ConditionalStepInterface extends StepInterface
{
    public function conditionsNotMet(): string;
}
