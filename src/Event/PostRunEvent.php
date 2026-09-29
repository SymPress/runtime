<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

final class PostRunEvent extends LifecycleEvent
{
    public function isPre(): bool
    {
        return false;
    }
}
