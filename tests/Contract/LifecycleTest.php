<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Application\ContainerFactory;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Event\PostRunEvent;
use SymPress\Runtime\Event\PostStepEvent;
use SymPress\Runtime\Event\PreRunEvent;
use SymPress\Runtime\Event\PreStepEvent;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\Definition;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Fixtures\LifecycleCallbacks;
use SymPress\Runtime\Tests\Fixtures\ProbeStep;
use SymPress\Runtime\Tests\Fixtures\StepTrace;
use SymPress\Runtime\Tests\Fixtures\TailStep;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class LifecycleTest extends TemporaryProject
{
    /**
     * @param array<string, mixed> $scripts
     * @return array{Runner, list<Definition>, StepTrace, EventDispatcherInterface, BufferedOutput}
     */
    private function fixture(array $scripts): array
    {
        $paths = new Paths($this->root);
        $config = new Config(['scripts' => $scripts], new Validator($paths));
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $io = new Io($input, $output);
        $registry = new Registry();
        $container = (new ContainerFactory())->create($config, $paths, $io, new RunContext($this->root, $paths->vendor(), $paths->bin()), $registry, static function (ContainerBuilder $builder): void {
            $builder->register(StepTrace::class)->setPublic(true);
            $builder->register(ProbeStep::class)->setAutowired(true)->setAutoconfigured(true);
            $builder->register(TailStep::class)->setAutowired(true)->addTag('sympress.runtime.step', ['name' => 'tail']);
        });

        return [new Runner($config, $paths, $io, $container), (new Selection())->resolve($registry, Registry::DEFAULT_ORDER)['steps'], $container->get(StepTrace::class), $container->get(EventDispatcherInterface::class), $output];
    }

    #[Group('PAR-EXT-001')]
    #[Group('PAR-EXT-004')]
    #[Group('PAR-OPT-027')]
    public function testScriptsAndEventsShareExactResultsAndPriorityOrdering(): void
    {
        $record = LifecycleCallbacks::class . '::record';
        [$runner, $definitions, $trace, $dispatcher, $output] = $this->fixture(['PRE-WPSTARTER' => $record, 'pre-' => $record, 'pre-sympress-runtime' => $record, 'pre-build-probe' => $record, 'post-probe' => $record, 'post-sympress-runtime' => $record]);
        $dispatcher->addListener(PreStepEvent::class, static fn (PreStepEvent $event) => $event->services->io()->write('event:first:' . $event->subject->name()), 10);
        $dispatcher->addListener(PreStepEvent::class, static fn (PreStepEvent $event) => $event->services->io()->write('event:last:' . $event->subject->name()), -10);
        $trace->result = StepInterface::SUCCESS | StepInterface::ERROR;
        self::assertSame(1, $runner->run($definitions));
        self::assertSame(['allowed', 'target', 'ask', 'run', 'tail', 'post'], $trace->events);
        $text = $output->fetch();
        self::assertSame(3, substr_count($text, 'script:sympress-runtime:4'));
        self::assertStringContainsString("event:first:probe\nscript:probe:4\nevent:last:probe", $text);
        self::assertStringContainsString('script:probe:3', $text);
        self::assertStringContainsString('script:sympress-runtime:3', $text);
        self::assertSame(1, substr_count($text, 'script:probe:4'));
    }

    public function testLegacyFourthCallbackArgumentRetainsTheSharedInternalContext(): void
    {
        $called = false;
        $callback = static function (int $result, Runner|StepInterface $subject, Services $services, RunContext $context) use (&$called): void {
            self::assertSame($context, $services->runContext());
            $called = true;
        };
        [$runner, $definitions] = $this->fixture(['pre-probe' => [$callback]]);
        self::assertSame(0, $runner->run($definitions));
        self::assertTrue($called);
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function signals(): iterable
    {
        yield 'stop only' => ['stop', false, true];
        yield 'halt and stop' => ['halt', true, true];
        yield 'halt and continue' => ['haltContinue', true, false];
    }

    #[DataProvider('signals')]
    #[Group('PAR-EXT-003')]
    #[Group('PAR-EXT-004')]
    public function testPreSignalsControlStepAndEventPropagation(string $signal, bool $halted, bool $stopped): void
    {
        [$runner, $definitions, $trace, $dispatcher, $output] = $this->fixture(['pre-probe' => [LifecycleCallbacks::class . '::' . $signal, LifecycleCallbacks::class . '::record']]);
        $dispatcher->addListener(PreStepEvent::class, static fn (PreStepEvent $event) => $event->services->io()->write('after-script:' . $event->subject->name()), -10);
        self::assertSame(0, $runner->run($definitions));
        self::assertSame(!$halted, in_array('run', $trace->events, true));
        $text = $output->fetch();
        self::assertSame(!$stopped, str_contains($text, 'script:probe'));
        self::assertSame(!$stopped, str_contains($text, 'after-script:probe'));
        self::assertContains('tail', $trace->events);
    }

    #[Group('PAR-EXT-002')]
    public function testScriptFailuresAreRedactedAndDoNotPreventLaterCallbacksOrSteps(): void
    {
        [$runner, $definitions, $trace, , $output] = $this->fixture(['pre-probe' => ['UnavailableCallback', LifecycleCallbacks::class . '::fail', LifecycleCallbacks::class . '::record']]);
        self::assertSame(1, $runner->run($definitions));
        self::assertContains('run', $trace->events);
        self::assertContains('tail', $trace->events);
        $text = $output->fetch();
        self::assertStringContainsString('script:probe:4', $text);
        self::assertStringContainsString('Unavailable runtime script callback', $text);
        self::assertStringNotContainsString('synthetic-private-value', $text);
    }

    #[Group('PAR-OPT-027')]
    #[Group('PAR-EXT-001')]
    public function testArrayStaticCallbacksAndPhpClosuresExecuteInOrder(): void
    {
        $called = [];
        $closure = static function (int $result) use (&$called): void {
            $called[] = $result;
        };
        [$runner, $definitions, , , $output] = $this->fixture(['pre-probe' => [[LifecycleCallbacks::class, 'record'], $closure], 'post-probe' => [$closure]]);
        self::assertSame(0, $runner->run($definitions));
        self::assertSame([StepInterface::NONE, StepInterface::SUCCESS], $called);
        self::assertStringContainsString('script:probe:4', $output->fetch());
    }

    #[Group('PAR-EXT-003')]
    public function testPostHaltOnlyStopsPropagationAndDoesNotSuppressTheNextStep(): void
    {
        [$runner, $definitions, $trace, , $output] = $this->fixture(['post-probe' => [LifecycleCallbacks::class . '::halt', LifecycleCallbacks::class . '::record'], 'post-sympress-runtime' => LifecycleCallbacks::class . '::record']);
        self::assertSame(0, $runner->run($definitions));
        self::assertContains('tail', $trace->events);
        self::assertStringNotContainsString('script:probe', $output->fetch());
    }

    #[Group('PAR-EXT-001')]
    #[Group('PAR-EXT-004')]
    #[Group('PAR-RUN-008')]
    public function testPreRunCanRemoveAndAddStepsButPerStepEventsCannotMutateTheQueue(): void
    {
        [$runner, $definitions, $trace, $dispatcher] = $this->fixture([]);
        $added = new TailStep($trace);
        $dispatcher->addListener(PreRunEvent::class, static function (PreRunEvent $event) use ($added): void {
            self::assertInstanceOf(Runner::class, $event->subject);
            self::assertSame(0, $event->subject->run([]), 'A recursive invocation performs no work.');
            self::assertCount(2, $event->subject);
            $event->subject->removeStep('probe', 'tail')->addStep($added, $added);
            self::assertCount(1, $event->subject);
        });
        $dispatcher->addListener(PreStepEvent::class, static fn () => $runner->removeStep('tail')->addStep(new ProbeStep($trace)));
        self::assertSame(0, $runner->run($definitions));
        self::assertSame(['tail'], $trace->events);
        self::assertCount(1, $runner);
    }

    #[Group('PAR-EXT-001')]
    #[Group('PAR-EXT-003')]
    public function testWholeRunHaltAndBlockedStepsStillDispatchAnAccuratePostRunEvent(): void
    {
        [$runner, $definitions, $trace, $dispatcher] = $this->fixture(['pre-wpstarter' => LifecycleCallbacks::class . '::halt']);
        $results = [];
        $dispatcher->addListener(PostRunEvent::class, static function (PostRunEvent $event) use (&$results): void {
            $results[] = $event->result;
        });
        self::assertSame(0, $runner->run($definitions));
        self::assertSame([], $trace->events);
        self::assertSame([StepInterface::NONE], $results);
        [$runner, $definitions, $trace, $dispatcher] = $this->fixture([]);
        $trace->result = StepInterface::ERROR;
        $dispatcher->addListener(PostRunEvent::class, static function (PostRunEvent $event) use (&$results): void {
            $results[] = $event->result;
        });
        self::assertSame(1, $runner->run($definitions));
        self::assertSame([StepInterface::NONE, StepInterface::ERROR], $results);
        self::assertNotContains('tail', $trace->events);
    }

    #[Group('PAR-RUN-003')]
    #[Group('PAR-EXT-004')]
    public function testSkippedStepEmitsNoStepEventsOrScripts(): void
    {
        [$runner, $definitions, $trace, $dispatcher, $output] = $this->fixture(['pre-probe' => LifecycleCallbacks::class . '::record', 'post-probe' => LifecycleCallbacks::class . '::record']);
        $trace->allowed = false;
        $names = [];
        foreach ([PreStepEvent::class, PostStepEvent::class] as $event) {
            $dispatcher->addListener($event, static function (PreStepEvent|PostStepEvent $event) use (&$names): void {
                $names[] = $event->subject->name();
            });
        }
        self::assertSame(0, $runner->run($definitions));
        self::assertSame(['tail', 'tail'], $names);
        self::assertStringNotContainsString('script:probe', $output->fetch());
    }
}
