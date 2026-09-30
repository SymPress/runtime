<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Extension;

use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Config\Result;
use SymPress\Runtime\Console\Question;
use SymPress\Runtime\Step\ScriptHaltSignal;

/** Consumer tests deliberately depend only on the documented extension surface. */
final class PublicSurfaceTest extends TestCase
{
    public function testResultPreservesFalsyValuesAndFallsBackForErrors(): void
    {
        foreach ([false, 0, '', []] as $value) {
            self::assertSame($value, Result::ok($value)->unwrapOrFallback('fallback'));
        }
        self::assertSame('fallback', Result::errored('invalid')->unwrapOrFallback('fallback'));
    }

    public function testHaltSignalsAndPublicQuestionFactoryAreUsable(): void
    {
        $signal = ScriptHaltSignal::haltStepContinuePropagation('build artifact unavailable');
        self::assertTrue($signal->isStepHalted());
        self::assertFalse($signal->isPropagationStopped());
        self::assertSame('build artifact unavailable', $signal->reason());
        self::assertInstanceOf(Question::class, new Question(['Continue?'], ['yes' => 'Yes'], 'yes'));
        self::assertInstanceOf(Question::class, Question::newWithValidator(['Project?'], static fn (string $answer): bool => $answer !== ''));
    }
}
