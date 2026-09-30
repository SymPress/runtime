<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use Psr\Container\ContainerInterface;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use Throwable;

final readonly class Runner
{
    public function __construct(private Config $config, private Paths $paths, private Io $io, private ContainerInterface $container, private Selection $selection = new Selection())
    {
    }

    /** @param list<Definition> $definitions */
    public function run(array $definitions): int
    {
        $failed = false;
        $postprocessors = [];
        foreach ($definitions as $definition) {
            if ($definition->class === null) {
                throw new RuntimeException('Step ' . $definition->name . ' is scheduled for a later implementation phase and is not available yet.');
            }
            $step = $this->container->get($definition->serviceId ?? $definition->class);
            if (!$step instanceof StepInterface || $step->name() !== $definition->name) {
                throw new RuntimeException('Step service name does not match its registration: ' . $definition->name);
            }
            try {
                if (!$this->allowed($step)) {
                    continue;
                }
                $result = $step->run($this->config, $this->paths);
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
                $this->io->error($definition->name . ': ' . $error->getMessage());
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
