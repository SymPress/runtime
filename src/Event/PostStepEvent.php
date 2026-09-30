<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

/** @api */
final class PostStepEvent extends LifecycleEvent
{
    /** @api */
    public function isPre(): bool
    {
        return false;
    }
}
