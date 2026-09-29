<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

final class PreStepEvent extends LifecycleEvent
{
    public function isPre(): bool
    {
        return true;
    }
}
