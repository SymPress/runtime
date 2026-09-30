<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Step\Registry;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Filesystem\Path;

/** @internal */
final class Application
{
    /**
     * @param list<string> $arguments
     * @param array<string, mixed>|null $data
     */
    public static function main(array $arguments, string $root, string $vendor, ?array $data = null): int
    {
        $manifestFile = $data['manifest'] ?? (getenv('COMPOSER') ?: 'composer.json');
        $manifestFile = is_string($manifestFile) ? Path::makeAbsolute($manifestFile, $root) : $root . '/composer.json';
        $manifest = (new ConfigLoader())->readObject($manifestFile);
        $composerConfig = $manifest['config'] ?? [];
        $bin = is_array($composerConfig) ? ($composerConfig['bin-dir'] ?? $vendor . '/bin') : $vendor . '/bin';
        $bin = is_string($bin) ? Path::makeAbsolute($bin, $root) : $vendor . '/bin';
        $installedFile = $vendor . '/composer/installed.json';
        $installed = is_file($installedFile) ? json_decode((string) file_get_contents($installedFile), true, 512, JSON_THROW_ON_ERROR) : [];
        $dev = !is_array($installed) || ($installed['dev'] ?? true) !== false;
        $context = $data === null ? new RunContext($root, $vendor, $bin, dev: $dev, manifest: $manifestFile) : RunContext::fromArray($data);
        $application = new ConsoleApplication('SymPress Runtime', '1.1.0');
        $application->setAutoExit(false);
        foreach (['run', ...Registry::RESERVED] as $operation) {
            $application->addCommand(new RuntimeCommand($context, $operation));
        }
        $application->setDefaultCommand('run');
        $explicitCommand = false;
        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--') {
                break;
            }
            if (str_starts_with($argument, '-')) {
                continue;
            }
            $explicitCommand = $application->has($argument);
            break;
        }
        if (!$explicitCommand) {
            array_splice($arguments, 1, 0, ['run']);
        }
        $input = new ArgvInput(array_values($arguments));
        $output = $data === null ? new ConsoleOutput() : new ConsoleOutput($context->verbosity, $context->decorated);
        if ($data !== null) {
            $input->setInteractive($context->interactive);
        } elseif (!stream_isatty(STDIN)) {
            $input->setInteractive(false);
        }

        return $application->run($input, $output);
    }
}
