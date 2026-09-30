<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

/** @api */
final class PostRunEvent extends LifecycleEvent
{
    /** @api */
    public function isPre(): bool
    {
        return false;
    }
}
