<?php

declare(strict_types=1);

namespace SymPress\Runtime\Event;

use SymPress\Runtime\Services;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\Step\ScriptHaltSignal;
use SymPress\Runtime\Step\StepInterface;
use Symfony\Contracts\EventDispatcher\Event;

abstract class LifecycleEvent extends Event
{
    private bool $halted = false;
    private bool $failed = false;
    private string $haltReason = '';

    public function __construct(public readonly int $result, public readonly Runner|StepInterface $subject, public readonly Services $services)
    {
    }

    abstract public function isPre(): bool;

    public function applySignal(ScriptHaltSignal $signal): void
    {
        if ($this->isPre() && $signal->isStepHalted()) {
            $this->halted = true;
            $this->haltReason = $signal->reason();
        }
        if (!$signal->isPropagationStopped()) {
            return;
        }
        $this->stopPropagation();
    }

    public function haltStep(string $reason, bool $continuePropagation = false): void
    {
        $this->applySignal($continuePropagation ? ScriptHaltSignal::haltStepContinuePropagation($reason) : ScriptHaltSignal::haltStep($reason));
    }

    public function isStepHalted(): bool
    {
        return $this->halted;
    }

    public function reason(): string
    {
        return $this->haltReason;
    }

    public function fail(): void
    {
        $this->failed = true;
    }

    public function failed(): bool
    {
        return $this->failed;
    }
}
