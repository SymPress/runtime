<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use InvalidArgumentException;
use SymPress\Runtime\Application\ContainerFactory;
use SymPress\Runtime\Application\DatabasePreflight;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Compatibility\LegacyApi;
use SymPress\Runtime\Compatibility\Migration;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\AutoloadRegistry;
use SymPress\Runtime\Package\ExtensionMetadata;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Step\Builtin\KernelBootStep;
use SymPress\Runtime\Step\Builtin\KernelCacheStep;
use SymPress\Runtime\Step\Definition;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Step\Runner;
use SymPress\Runtime\WordPress\VersionDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

final class RuntimeCommand extends Command
{
    private bool $legacyApiEnabled = false;

    public function __construct(private readonly RunContext $context, private readonly string $operation = 'run')
    {
        parent::__construct($operation);
    }

    protected function configure(): void
    {
        $this->setDescription($this->operation === 'validate' ? 'Validate project configuration without running steps.' : 'Run project setup steps.');
        if ($this->operation === 'dump-env') {
            $this->addArgument('environment', InputArgument::REQUIRED, 'Raw environment name to resolve and dump.');
        }
        if (in_array($this->operation, ['doctor', 'check'], true)) {
            $this->addOption('json', null, InputOption::VALUE_NONE, 'Print structured diagnostics without environment values.');
        }
        if ($this->operation === 'migrate') {
            $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'New configuration path relative to the project root.', 'sympress-runtime.json');
            foreach (['force', 'dry-run', 'json'] as $flag) {
                $this->addOption($flag, null, InputOption::VALUE_NONE);
            }
        }
        if ($this->operation !== 'run') {
            return;
        }
        $this->addArgument('steps', InputArgument::IS_ARRAY, 'Names to include; with --skip, names to exclude.');
        $this->addOption('skip', null, InputOption::VALUE_NONE, 'Exclude the named steps (requires names).');
        $this->addOption('skip-custom', null, InputOption::VALUE_NONE, 'Exclude custom steps unless explicitly selected.');
        $this->addOption('ignore-skip-config', null, InputOption::VALUE_NONE, 'Ignore configured skip-steps.');
        $this->addOption('list-steps', null, InputOption::VALUE_NONE, 'List selected steps without executing them.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Explicitly allow overwriting protected files.');
        $this->addOption('generate-build-id', null, InputOption::VALUE_NONE, 'Generate a kernel build ID when explicitly selecting kernel-cache.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $included = get_included_files();
        try {
            return $this->executeOperation($input, $output);
        } catch (Throwable $error) {
            if (!in_array($this->operation, ['doctor', 'check'], true) || $input->getOption('json') !== true) {
                throw $error;
            }
            $output->writeln(json_encode(['environment' => null, 'checks' => [['id' => 'configuration', 'status' => 'fail', 'detail' => 'Cannot validate project configuration; values are redacted.']], 'exit' => 1], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::FAILURE;
        } finally {
            if ($this->legacyApiEnabled) {
                LegacyApi::reportUsage(array_values(array_diff(get_included_files(), $included)), new Io($input, $output));
            }
        }
    }

    private function executeOperation(InputInterface $input, OutputInterface $output): int
    {
        $io = new Io($input, $output);
        $context = $this->context->withConsole($input->isInteractive(), $output->isDecorated(), $output->getVerbosity());
        if (!in_array($this->operation, ['run', 'validate', 'flush-env-cache', 'dump-env', 'doctor', 'check', 'migrate'], true)) {
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
        if ($this->operation === 'migrate') {
            $target = $input->getOption('output');
            if (!is_string($target) || $target === '') {
                throw new InvalidArgumentException('Migration output must be a non-empty path.');
            }
            $report = (new Migration($context, $paths))->run($extra, $target, $input->getOption('force') === true, $input->getOption('dry-run') === true);
            if ($input->getOption('json') === true) {
                $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $io->write($report['status'] . ': ' . $report['target'] . ' (profile: ' . $report['profile'] . ')');
            foreach ($report['findings'] as $finding) {
                $io->write($finding['file'] . ':' . $finding['line'] . ': ' . $finding['symbol'] . ' => ' . $finding['replacement'] . '. ' . $finding['guidance']);
            }
            foreach ($report['next'] as $next) {
                $io->write($next);
            }

            return self::SUCCESS;
        }
        $loaded = $loader->load($paths->root(), $extra);
        $values = array_replace($loaded->values, $this->context->configuration($selection->selected()));
        if ($this->operation === 'run' && $input->getOption('generate-build-id')) {
            if (!$selection->selected() || !in_array('kernel-cache', $selection->names, true)) {
                throw new InvalidArgumentException('--generate-build-id requires explicit kernel-cache selection.');
            }
            $values['kernel-build-id'] = bin2hex(random_bytes(16));
        }
        $config = new Config($values, new Validator($paths, $loaded->profile), $loaded->profile);
        foreach ($loaded->diagnostics as $diagnostic) {
            $io->error($diagnostic);
        }
        $errors = $config->errors();
        foreach ($errors as $key => $error) {
            $io->error($key . ': ' . $error);
        }
        if ($errors !== []) {
            if (in_array($this->operation, ['doctor', 'check'], true) && $input->getOption('json') === true) {
                throw new InvalidArgumentException('Invalid runtime configuration.');
            }
            return self::INVALID;
        }
        $extensionAutoload = new AutoloadRegistry();
        $packages = (new PackageFinder($context))->all();
        $compatible = !$config['compatibility']->is(false);
        foreach ($packages as $package) {
            (new ExtensionMetadata($paths))->steps($package);
            $extensionAutoload->metadata($package, $compatible);
            $packageExtra = $package->getExtra();
            if (!$compatible || ($package->getType() !== 'wpstarter-extension' && !array_key_exists('wpstarter-autoload', $packageExtra))) {
                continue;
            }
            $io->error('Deprecated WP Starter extension: ' . $package->getName() . '; use sympress-runtime-extension and sympress-runtime-autoload.');
        }
        if (in_array($this->operation, ['doctor', 'check'], true)) {
            return (new Doctor($config, $paths, $context, $io))->run($input->getOption('json') === true);
        }
        if ($this->operation === 'validate') {
            $io->success('Runtime configuration is valid.');

            return self::SUCCESS;
        }
        if ($this->operation === 'dump-env') {
            $environment = $input->getArgument('environment');
            if (!is_string($environment)) {
                throw new InvalidArgumentException('A dump requires an explicit environment.');
            }

            return (new DumpEnvironment($config, $paths, $io))->run($environment);
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
        if ($compatible) {
            LegacyApi::register();
            $this->legacyApiEnabled = true;
        }
        $configure = null;
        if (is_string($autoload)) {
            $result = require_once $autoload;
            $configure = is_callable($result) ? $result : null;
        }
        $configurators = $configure === null ? [] : [$configure];
        foreach ($packages as $package) {
            array_push($configurators, ...$extensionAutoload->load($package, $compatible));
        }
        $configure = static function (ContainerBuilder $builder) use ($configurators): void {
            foreach ($configurators as $configure) {
                $configure($builder);
            }
        };
        $registry = new Registry();
        $container = (new ContainerFactory())->create($config, $paths, $io, $context, $registry, $configure, $selection);
        $skips = $config['skip-steps']->unwrapOrFallback([]);
        $skips = is_array($skips) ? array_values(array_filter($skips, is_string(...))) : [];
        $resolved = $selection->resolve($registry, $skips, $loaded->profile);
        foreach ($resolved['warnings'] as $warning) {
            $io->error($warning);
        }
        $release = $config['compatibility-profile']->is('release-3.0.1');
        $kernelMaintenance = $selection->selected() && array_all($resolved['steps'], static fn (Definition $step): bool => in_array($step->class, [KernelCacheStep::class, KernelBootStep::class], true));
        if ($this->operation === 'run' && $release && !$kernelMaintenance) {
            $this->checkWordPress($config);
        }
        if ($selection->list) {
            foreach ($resolved['steps'] as $step) {
                $io->write($step->name . ($step->commandOnly ? ' (command only)' : '') . ($step->class === null ? ' (pending implementation)' : ''));
            }

            return self::SUCCESS;
        }

        if ($this->operation === 'run' && !$kernelMaintenance) {
            if (!$release && !($selection->selected() && $config['compatibility-profile']->is('upstream-dev'))) {
                $this->checkWordPress($config);
            }
            $preflight = $container->get(DatabasePreflight::class);
            if (!$preflight instanceof DatabasePreflight || !$preflight->run($selection)) {
                return self::FAILURE;
            }
        }

        return (new Runner($config, $paths, $io, $container, $selection))->run($resolved['steps']);
    }

    private function checkWordPress(Config $config): void
    {
        if (!$config['require-wp']->is(true)) {
            return;
        }
        $fallback = $config['wp-version']->unwrap();
        (new VersionDiscovery(new PackageFinder($this->context)))->discover(is_string($fallback) ? $fallback : null);
    }

    private function selection(InputInterface $input): Selection
    {
        if ($this->operation === 'flush-env-cache') {
            return new Selection(['flushenvcache'], ignoreSkipConfig: true);
        }
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
