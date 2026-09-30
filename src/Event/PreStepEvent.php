<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

/** @api */
final class PreStepEvent extends LifecycleEvent
{
    /** @api */
    public function isPre(): bool
    {
        return true;
    }
}
