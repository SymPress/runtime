<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

/** @api */
interface ConditionalStepInterface extends StepInterface
{
    /** @api */
    public function conditionsNotMet(): string;
}
