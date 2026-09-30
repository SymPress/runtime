<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Symfony signatures here belong to Composer's host Console API; see ADR 0004. */
final class Command extends BaseCommand
{
    public function __construct(private readonly ?string $operation = null)
    {
        parent::__construct($operation === null ? 'sympress-runtime' : 'sympress-runtime:' . $operation);
    }

    protected function configure(): void
    {
        $this->setDescription('Run the isolated SymPress Runtime application.');
        if ($this->operation === 'dump-env') {
            $this->addArgument('environment', InputArgument::REQUIRED, 'Raw environment name to resolve and dump.');
        }
        if (in_array($this->operation, ['doctor', 'check'], true)) {
            $this->addOption('json', null, InputOption::VALUE_NONE);
        }
        if ($this->operation === 'migrate') {
            $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'New configuration path.', 'sympress-runtime.json');
            foreach (['force', 'dry-run', 'json'] as $flag) {
                $this->addOption($flag, null, InputOption::VALUE_NONE);
            }
        }
        if ($this->operation !== null) {
            return;
        }
        $this->addArgument('steps', InputArgument::IS_ARRAY, 'Step names to include, or exclude with --skip.');
        foreach (['skip', 'skip-custom', 'ignore-skip-config', 'list-steps', 'force', 'generate-build-id'] as $flag) {
            $this->addOption($flag, null, InputOption::VALUE_NONE);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $dev = $composer->getRepositoryManager()->getLocalRepository()->getDevMode() ?? true;
        $context = (new ContextFactory())->create($composer, $this->getIO(), 'command', $dev);

        return (new RunnerProcess())->run($context, $this->arguments($input));
    }

    /** @return list<string> */
    private function arguments(InputInterface $input): array
    {
        if ($this->operation === 'migrate') {
            $arguments = ['migrate'];
            $target = $input->getOption('output');
            if (is_string($target)) {
                $arguments[] = '--output=' . $target;
            }
            foreach (['force', 'dry-run', 'json'] as $flag) {
                if ($input->getOption($flag) !== true) {
                    continue;
                }
                $arguments[] = '--' . $flag;
            }

            return $arguments;
        }
        if ($this->operation !== null) {
            $environment = $this->operation === 'dump-env' ? $input->getArgument('environment') : null;
            if (in_array($this->operation, ['doctor', 'check'], true) && $input->getOption('json') === true) {
                return [$this->operation, '--json'];
            }

            return is_string($environment) ? [$this->operation, $environment] : [$this->operation];
        }
        $arguments = [];
        foreach (['skip', 'skip-custom', 'ignore-skip-config', 'list-steps', 'force', 'generate-build-id'] as $flag) {
            if ($input->getOption($flag) !== true) {
                continue;
            }

            $arguments[] = '--' . $flag;
        }
        $steps = $input->getArgument('steps');
        $steps = is_array($steps) ? array_values(array_filter($steps, is_string(...))) : [];

        return [...$arguments, ...$steps];
    }
}
