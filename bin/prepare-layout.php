<?php

declare(strict_types=1);

// Private child entrypoint: no project autoload.php or Composer plugins/scripts.
try {
    [$script, $root, $vendor, $manifest] = $argv;
    require_once dirname(__DIR__) . '/src/Composer/LayoutAutoload.php';
    SymPress\Runtime\Composer\LayoutAutoload::register($vendor);
    $context = new SymPress\Runtime\Application\RunContext($root, $vendor, $vendor . '/bin', manifest: $manifest);
    $command = new SymPress\Runtime\Console\RuntimeCommand($context);
    $command->setApplication(new Symfony\Component\Console\Application());
    $command->mergeApplicationDefinition(false);
    $input = new Symfony\Component\Console\Input\ArgvInput([$script, ...array_slice($argv, 4)], $command->getDefinition());
    if ($input->getOption('skip') && $input->getArgument('steps') === []) {
        throw new InvalidArgumentException('--skip requires step names.');
    }
    (new SymPress\Runtime\Composer\PackageLayout())->prepare($root, $vendor, $manifest);
} catch (Throwable $error) {
    fwrite(STDERR, 'SymPress package layout: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
