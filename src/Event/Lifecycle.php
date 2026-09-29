<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

use SymPress\Runtime\Services;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

final readonly class Lifecycle
{
    public function __construct(private EventDispatcherInterface $dispatcher, private Services $services)
    {
    }

    public function dispatch(Runner|StepInterface $subject, bool $pre, int $result = StepInterface::NONE): LifecycleEvent
    {
        $class = $subject instanceof Runner
            ? ($pre ? PreRunEvent::class : PostRunEvent::class)
            : ($pre ? PreStepEvent::class : PostStepEvent::class);
        $event = new $class($result, $subject, $this->services);
        try {
            $this->dispatcher->dispatch($event);
        } catch (Throwable) {
            $event->fail();
            $this->services->io()->error('Runtime lifecycle listener failed for ' . $subject->name() . '.');
        }

        return $event;
    }
}
