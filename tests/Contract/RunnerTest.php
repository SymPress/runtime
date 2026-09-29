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
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Fixtures\ProbeStep;
use SymPress\Runtime\Tests\Fixtures\StepTrace;
use SymPress\Runtime\Tests\Fixtures\TailStep;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RunnerTest extends TemporaryProject
{
    /** @return array{Runner, Registry, StepTrace} */
    private function runner(Selection $selection = new Selection()): array
    {
        $paths = new Paths($this->root);
        $context = new RunContext($this->root, $paths->vendor(), $paths->bin());
        $config = new Config([], new Validator($paths));
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new Io($input, new BufferedOutput());
        $registry = new Registry();
        $container = (new ContainerFactory())->create($config, $paths, $io, $context, $registry, static function (ContainerBuilder $container): void {
            $container->register(StepTrace::class)->setPublic(true);
            $container->register('probe.service', ProbeStep::class)->setAutowired(true)->setAutoconfigured(true);
            $container->register('tail.service', TailStep::class)->setAutowired(true)->addTag('sympress.runtime.step', ['name' => 'tail']);
        });

        return [new Runner($config, $paths, $io, $container, $selection), $registry, $container->get(StepTrace::class)];
    }

    /** @return iterable<string, array{int, int, list<string>}> */
    public static function outcomes(): iterable
    {
        yield 'success' => [StepInterface::SUCCESS, 0, ['allowed', 'target', 'ask', 'run', 'tail', 'post']];
        yield 'blocking error' => [StepInterface::ERROR, 1, ['allowed', 'target', 'ask', 'run', 'post']];
        yield 'partial success' => [StepInterface::SUCCESS | StepInterface::ERROR, 1, ['allowed', 'target', 'ask', 'run', 'tail', 'post']];
        yield 'none' => [StepInterface::NONE, 0, ['allowed', 'target', 'ask', 'run', 'tail', 'post']];
    }

    /** @param list<string> $expected */
    #[DataProvider('outcomes')]
    #[Group('PAR-RUN-001')]
    #[Group('PAR-RUN-002')]
    #[Group('PAR-RUN-007')]
    #[Group('PAR-EXT-008')]
    public function testAttributedAndTaggedServicesRunWithExactFlagSemantics(int $result, int $status, array $expected): void
    {
        [$runner, $registry, $trace] = $this->runner();
        $trace->result = $result;
        $definitions = (new Selection())->resolve($registry, Registry::DEFAULT_ORDER)['steps'];
        self::assertSame('probe', $definitions[0]->name);
        self::assertSame(20, $definitions[0]->priority);
        self::assertSame($status, $runner->run($definitions));
        self::assertSame($expected, $trace->events);
    }

    #[Group('PAR-RUN-003')]
    #[Group('PAR-RUN-005')]
    #[Group('PAR-RUN-006')]
    public function testOverwriteGuardPrecedesOptionalQuestion(): void
    {
        $this->write('probe.txt', 'user content');
        [$runner, $registry, $trace] = $this->runner();
        self::assertSame(0, $runner->run([(new Selection(['probe']))->resolve($registry)['steps'][0]]));
        self::assertSame(['allowed', 'target'], $trace->events);
        self::assertSame('user content', file_get_contents($this->root . '/probe.txt'));
        [$forced, $registry, $trace] = $this->runner(new Selection(['probe'], force: true));
        self::assertSame(0, $forced->run((new Selection(['probe']))->resolve($registry)['steps']));
        self::assertSame(['allowed', 'target', 'run', 'post'], $trace->events);
    }
}
