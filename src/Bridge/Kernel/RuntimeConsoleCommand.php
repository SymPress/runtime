<?php

declare(strict_types=1);

namespace SymPress\Runtime\Bridge\Kernel;

use InvalidArgumentException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Console\RuntimeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

final class RuntimeConsoleCommand extends Command
{
    public function __construct(private readonly string $projectDir, private readonly string $operation)
    {
        parent::__construct($operation);
    }

    protected function configure(): void
    {
        $this->setDescription('SymPress Runtime ' . $this->operation . '.');
        if ($this->operation === 'dump-env') {
            $this->addArgument('environment', InputArgument::REQUIRED, 'Raw environment name to resolve and dump.');
        }
        if (!in_array($this->operation, ['doctor', 'check'], true)) {
            return;
        }
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print structured diagnostics.');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Diagnostic output format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (in_array($this->operation, ['doctor', 'check'], true)) {
            $options = $input->getOptions();
            $format = $options['format'];
            if (!in_array($format, ['text', 'json'], true)) {
                throw new InvalidArgumentException('Diagnostic format must be text or json.');
            }
            unset($options['format']);
            $options['json'] = $options['json'] === true || $format === 'json';
            $parameters = $input->getArguments();
            foreach ($options as $name => $value) {
                $parameters['--' . $name] = $value;
            }
            $normalized = new ArrayInput($parameters);
            $normalized->setInteractive($input->isInteractive());
            $input = $normalized;
        }
        $manifest = Path::makeAbsolute(getenv('COMPOSER') ?: 'composer.json', $this->projectDir);
        $data = (new ConfigLoader())->readObject($manifest);
        $configuration = $data['config'] ?? [];
        if (!is_array($configuration)) {
            throw new InvalidArgumentException('Composer config must be an object.');
        }
        $vendor = getenv('COMPOSER_VENDOR_DIR') ?: ($configuration['vendor-dir'] ?? 'vendor');
        if (!is_string($vendor)) {
            throw new InvalidArgumentException('Composer vendor-dir must be a string.');
        }
        $vendor = Path::makeAbsolute($vendor, $this->projectDir);
        $bin = $configuration['bin-dir'] ?? $vendor . '/bin';
        if (!is_string($bin)) {
            throw new InvalidArgumentException('Composer bin-dir must be a string.');
        }
        $context = new RunContext($this->projectDir, $vendor, Path::makeAbsolute($bin, $this->projectDir), manifest: $manifest);
        $command = new RuntimeCommand($context, $this->operation);
        $command->setApplication($this->getApplication());

        return $command->run($input, $output);
    }
}
