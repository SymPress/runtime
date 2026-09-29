<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

final readonly class ScriptHaltSignal
{
    private function __construct(private string $message, private bool $stop, private bool $halt)
    {
    }

    public static function stopPropagation(string $reason): self
    {
        return new self($reason, true, false);
    }

    public static function haltStep(string $reason): self
    {
        return new self($reason, true, true);
    }

    public static function haltStepContinuePropagation(string $reason): self
    {
        return new self($reason, false, true);
    }

    public function reason(): string
    {
        return $this->message;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stop;
    }

    public function isStepHalted(): bool
    {
        return $this->halt;
    }
}
