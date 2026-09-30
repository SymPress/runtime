<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Fixtures;

use RuntimeException;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\Step\ScriptHaltSignal;
use SymPress\Runtime\Step\StepInterface;

final class LifecycleCallbacks
{
    public static function record(int $result, Runner|StepInterface $subject, Services $services): void
    {
        $services->io()->write('script:' . $subject->name() . ':' . $result);
    }

    public static function fail(): void
    {
        throw new RuntimeException('synthetic-private-value');
    }

    public static function stop(): ScriptHaltSignal
    {
        return ScriptHaltSignal::stopPropagation('fixture stop');
    }

    public static function halt(): ScriptHaltSignal
    {
        return ScriptHaltSignal::haltStep('fixture halt');
    }

    public static function haltContinue(): ScriptHaltSignal
    {
        return ScriptHaltSignal::haltStepContinuePropagation('fixture halt and continue');
    }
}
