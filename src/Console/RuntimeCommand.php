<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use InvalidArgumentException;
use SymPress\Runtime\Application\ContainerFactory;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\ExtensionMetadata;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\WordPress\VersionDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RuntimeCommand extends Command
{
    public function __construct(private readonly RunContext $context, private readonly string $operation = 'run')
    {
        parent::__construct($operation);
    }

    protected function configure(): void
    {
        $this->setDescription($this->operation === 'validate' ? 'Validate project configuration without running steps.' : 'Run project setup steps.');
        if ($this->operation !== 'run') {
            return;
        }
        $this->addArgument('steps', InputArgument::IS_ARRAY, 'Names to include; with --skip, names to exclude.');
        $this->addOption('skip', null, InputOption::VALUE_NONE, 'Exclude the named steps (requires names).');
        $this->addOption('skip-custom', null, InputOption::VALUE_NONE, 'Exclude custom steps unless explicitly selected.');
        $this->addOption('ignore-skip-config', null, InputOption::VALUE_NONE, 'Ignore configured skip-steps.');
        $this->addOption('list-steps', null, InputOption::VALUE_NONE, 'List selected steps without executing them.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Explicitly allow overwriting protected files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new Io($input, $output);
        $context = $this->context->withConsole($input->isInteractive(), $output->isDecorated(), $output->getVerbosity());
        if (!in_array($this->operation, ['run', 'validate'], true)) {
            $io->error('Command ' . $this->operation . ' is scheduled for a later implementation phase.');

            return self::INVALID;
        }
        $selection = $this->selection($input);
        $loader = new ConfigLoader();
        $manifest = $loader->readObject($this->context->manifestPath());
        $extra = $manifest['extra'] ?? [];
        if (!is_array($extra)) {
            throw new InvalidArgumentException('Composer extra must be an object.');
        }
        $extra = $this->namedEntries($extra);
        $paths = new Paths(
            $this->context->root,
            $this->context->vendor,
            $this->context->bin,
            $this->directory($extra, 'wordpress-install-dir', 'wordpress'),
            $this->directory($extra, 'wordpress-content-dir', 'wp-content'),
        );
        $loaded = $loader->load($paths->root(), $extra);
        $config = new Config(array_replace($loaded->values, $this->context->configuration($selection->selected())), new Validator($paths, $loaded->profile), $loaded->profile);
        foreach ($loaded->diagnostics as $diagnostic) {
            $io->error($diagnostic);
        }
        $errors = $config->errors();
        foreach ($errors as $key => $error) {
            $io->error($key . ': ' . $error);
        }
        if ($errors !== []) {
            return self::INVALID;
        }
        foreach ((new PackageFinder($context))->all() as $package) {
            (new ExtensionMetadata($paths))->steps($package);
        }
        if ($this->operation === 'validate') {
            $io->success('Runtime configuration is valid.');

            return self::SUCCESS;
        }
        if (in_array($manifest['type'] ?? null, ['sympress-runtime-extension', 'wpstarter-extension'], true)) {
            $io->error('Runtime extension roots do not run project setup.');

            return self::INVALID;
        }
        $templates = $config['templates-dir']->unwrap();
        if (is_string($templates)) {
            $paths->useCustomTemplatesDir($templates);
        }
        $autoload = $config['autoload']->unwrap();
        $configure = null;
        if (is_string($autoload)) {
            $result = require_once $autoload;
            $configure = is_callable($result) ? $result : null;
        }
        $registry = new Registry();
        $container = (new ContainerFactory())->create($config, $paths, $io, $context, $registry, $configure);
        $skips = $config['skip-steps']->unwrapOrFallback([]);
        $skips = is_array($skips) ? array_values(array_filter($skips, is_string(...))) : [];
        $resolved = $selection->resolve($registry, $skips, $loaded->profile);
        foreach ($resolved['warnings'] as $warning) {
            $io->error($warning);
        }
        if ($selection->list) {
            foreach ($resolved['steps'] as $step) {
                $io->write($step->name . ($step->commandOnly ? ' (command only)' : '') . ($step->class === null ? ' (pending implementation)' : ''));
            }

            return self::SUCCESS;
        }

        if ($config['require-wp']->is(true)) {
            $fallback = $config['wp-version']->unwrap();
            (new VersionDiscovery(new PackageFinder($this->context)))->discover(is_string($fallback) ? $fallback : null);
        }

        return (new Runner($config, $paths, $io, $container, $selection))->run($resolved['steps']);
    }

    private function selection(InputInterface $input): Selection
    {
        if ($this->operation !== 'run') {
            return new Selection();
        }
        $names = $input->getArgument('steps');
        $names = is_array($names) ? array_values(array_filter($names, is_string(...))) : [];

        return new Selection($names, $input->getOption('skip') === true, $input->getOption('skip-custom') === true, $input->getOption('ignore-skip-config') === true, $input->getOption('list-steps') === true, $input->getOption('force') === true);
    }

    /** @param array<string, mixed> $extra */
    private function directory(array $extra, string $key, string $default): string
    {
        $value = $extra[$key] ?? $default;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException($key . ' must be a non-empty path string.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $entries
     * @return array<string, mixed>
     */
    private function namedEntries(array $entries): array
    {
        $result = [];
        foreach ($entries as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Expected named configuration keys.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
