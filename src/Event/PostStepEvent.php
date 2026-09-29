<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

final class PostStepEvent extends LifecycleEvent
{
    public function isPre(): bool
    {
        return false;
    }
}
