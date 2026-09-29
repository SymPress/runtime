<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use Countable;
use Psr\Container\ContainerInterface;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Event\Lifecycle;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use Throwable;

final class Runner implements Countable
{
    /** @var array<int, StepInterface> */
    private array $steps = [];
    private bool $running = false;
    private bool $preparing = false;

    public function __construct(private readonly Config $config, private readonly Paths $paths, private readonly Io $io, private readonly ContainerInterface $container, private readonly Selection $selection = new Selection())
    {
    }

    public function name(): string
    {
        return 'sympress-runtime';
    }

    public function count(): int
    {
        return count($this->steps);
    }

    public function addStep(StepInterface $step, StepInterface ...$steps): self
    {
        if (!$this->running || $this->preparing) {
            foreach ([$step, ...$steps] as $entry) {
                $this->steps[spl_object_id($entry)] = $entry;
            }
        }

        return $this;
    }

    public function removeStep(StepInterface|string $step, StepInterface|string ...$steps): self
    {
        if ($this->running && !$this->preparing) {
            return $this;
        }
        foreach ([$step, ...$steps] as $remove) {
            foreach ($this->steps as $key => $entry) {
                if ($entry !== $remove && $entry->name() !== $remove) {
                    continue;
                }
                unset($this->steps[$key]);
            }
        }

        return $this;
    }

    /** @param list<Definition> $definitions */
    public function run(array $definitions): int
    {
        if ($this->running) {
            return 0;
        }
        foreach ($definitions as $definition) {
            if ($definition->class === null) {
                throw new RuntimeException('Step ' . $definition->name . ' is scheduled for a later implementation phase and is not available yet.');
            }
            $step = $this->container->get($definition->serviceId ?? $definition->class);
            if (!$step instanceof StepInterface || $step->name() !== $definition->name) {
                throw new RuntimeException('Step service name does not match its registration: ' . $definition->name);
            }
            $this->addStep($step);
        }
        $lifecycle = $this->container->get(Lifecycle::class);
        if (!$lifecycle instanceof Lifecycle) {
            throw new RuntimeException('Runtime lifecycle service is unavailable.');
        }
        $this->running = true;
        $this->preparing = true;
        $pre = $lifecycle->dispatch($this, true);
        $this->preparing = false;
        foreach ($this->steps as $key => $step) {
            if ($step->name() === 'wpcli') {
                unset($this->steps[$key]);
                $this->steps[$key] = $step;
                break;
            }
        }
        $failed = $pre->failed();
        $aggregate = 0;
        $postprocessors = [];
        foreach ($pre->isStepHalted() ? [] : $this->steps as $step) {
            try {
                if (!$this->allowed($step)) {
                    continue;
                }
                $before = $lifecycle->dispatch($step, true);
                $failed = $failed || $before->failed();
                if ($before->isStepHalted()) {
                    continue;
                }
                try {
                    $result = $step->run($this->config, $this->paths);
                } catch (Throwable $error) {
                    $this->io->error($step->name() . ': ' . $error->getMessage());
                    $result = StepInterface::ERROR;
                }
                $after = $lifecycle->dispatch($step, false, $result);
                $failed = $failed || $after->failed();
                $aggregate |= $result & (StepInterface::SUCCESS | StepInterface::ERROR);
                if ($step instanceof PostProcessStepInterface) {
                    $postprocessors[] = $step;
                }
                if (($result & StepInterface::SUCCESS) !== 0) {
                    $this->io->success($step->success());
                }
                if (($result & StepInterface::ERROR) !== 0) {
                    $this->io->error($step->error());
                    $failed = true;
                }
                if ($result === StepInterface::ERROR && $step instanceof BlockingStepInterface) {
                    break;
                }
            } catch (Throwable $error) {
                $this->io->error($step->name() . ': ' . $error->getMessage());
                $failed = true;
                if ($step instanceof BlockingStepInterface) {
                    break;
                }
            }
        }
        foreach ($postprocessors as $step) {
            try {
                $step->postProcess($this->io);
            } catch (Throwable $error) {
                $this->io->error($step->name() . ': ' . $error->getMessage());
                $failed = true;
            }
        }
        if ($failed) {
            $aggregate |= StepInterface::ERROR;
        }
        $post = $lifecycle->dispatch($this, false, $aggregate ?: StepInterface::NONE);
        $failed = $failed || $post->failed();
        $this->running = false;

        return $failed ? 1 : 0;
    }

    private function allowed(StepInterface $step): bool
    {
        if (!$step->allowed($this->config, $this->paths)) {
            if ($step instanceof ConditionalStepInterface) {
                $this->selection->selected() ? $this->io->comment($step->conditionsNotMet()) : $this->io->verbose($step->conditionsNotMet());
            }

            return false;
        }
        if ($step instanceof FileCreationStepInterface) {
            $target = $step->targetPath($this->paths);
            if (!(new OverwritePolicy($this->config, $this->paths, $this->io))->shouldOverwrite($target, $this->selection->force)) {
                $this->io->comment('Preserved protected target: ' . $target);

                return false;
            }
        }
        $ask = !$this->selection->selected() || $this->config['compatibility-profile']->is('release-3.0.1');
        if ($ask && $step instanceof OptionalStepInterface && !$step->askConfirm($this->config, $this->io)) {
            $this->io->comment($step->skipped());

            return false;
        }

        return true;
    }
}
