<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

/** @api */
final readonly class ScriptHaltSignal
{
    private function __construct(private string $message, private bool $stop, private bool $halt)
    {
    }

    /** @api */
    public static function stopPropagation(string $reason): self
    {
        return new self($reason, true, false);
    }

    /** @api */
    public static function haltStep(string $reason): self
    {
        return new self($reason, true, true);
    }

    /** @api */
    public static function haltStepContinuePropagation(string $reason): self
    {
        return new self($reason, false, true);
    }

    /** @api */
    public function reason(): string
    {
        return $this->message;
    }

    /** @api */
    public function isPropagationStopped(): bool
    {
        return $this->stop;
    }

    /** @api */
    public function isStepHalted(): bool
    {
        return $this->halt;
    }
}
