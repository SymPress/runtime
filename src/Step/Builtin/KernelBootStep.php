<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\ArtifactWriter;
use SymPress\Runtime\Kernel\BootOwnership;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Step\ConditionalStepInterface;
use Symfony\Component\Filesystem\Path;

/** @internal */
final readonly class KernelBootStep implements ConditionalStepInterface
{
    public function __construct(private BootOwnership $ownership, private PackageFinder $packages, private ArtifactWriter $writer, private OverwritePolicy $overwrite, private ProjectBoundary $boundary, private Selection $selection, private Io $io)
    {
    }

    public function name(): string
    {
        return 'kernel-boot';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return $config['kernel-boot']->is(true) || $this->owned();
    }

    public function run(Config $config, Paths $paths): int
    {
        $file = $this->ownership->generatedFile();
        if (!$config['kernel-boot']->is(true) || $this->ownership->providers() !== []) {
            $this->io->comment('Generated kernel boot is disabled or an existing package owns boot.');
            if (!$this->owned()) {
                return self::NONE;
            }
            $this->boundary->assertWritablePath($file);

            return unlink($file) ? self::SUCCESS : self::ERROR;
        }
        if ($this->packages->findByName('sympress/kernel') === null) {
            $this->io->error('Kernel boot was requested but sympress/kernel is not installed.');

            return self::ERROR;
        }
        if (!$this->overwrite->shouldOverwrite($file, $this->selection->force)) {
            return self::ERROR;
        }
        $root = var_export(Path::makeRelative($paths->root(), dirname($file)), true);
        $autoload = var_export(Path::makeRelative($paths->vendor('autoload.php'), dirname($file)), true);
        $source = "<?php\n// " . OverwritePolicy::MARKER . " kernel-boot:v1\n/**\n * Plugin Name: SymPress Runtime Kernel\n * Version: " . $this->packages->runtimeVersion() . "\n */\n";
        $source .= "if (!defined('ABSPATH')) { return; }\nrequire_once __DIR__ . '/' . " . $autoload . ";\n";
        $source .= "if (\\SymPress\\Kernel\\App::kernel() === null) {\n    \\SymPress\\Kernel\\App::bootKernel(new \\SymPress\\Kernel\\Kernel\\SiteKernel(__DIR__ . '/' . " . $root . "));\n}\n";

        return $this->writer->write($file, $source) ? self::SUCCESS : self::ERROR;
    }

    private function owned(): bool
    {
        $file = $this->ownership->generatedFile();

        return is_file($file) && !is_link($file) && str_contains((string) file_get_contents($file), OverwritePolicy::MARKER . ' kernel-boot:v1');
    }

    public function success(): string
    {
        return 'Kernel boot ownership reconciled.';
    }

    public function error(): string
    {
        return 'Cannot reconcile kernel boot ownership.';
    }

    public function conditionsNotMet(): string
    {
        return 'Generated kernel boot is not enabled.';
    }
}
