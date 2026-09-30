<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\ArtifactWriter;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\Step\FileCreationStepInterface;
use Symfony\Component\Filesystem\Path;

final class MuLoaderStep implements FileCreationStepInterface, ConditionalStepInterface
{
    /** @var array<string, string> */
    private array $plugins = [];

    public function __construct(private readonly MuPluginList $list, private readonly FileContentBuilder $builder, private readonly ArtifactWriter $writer)
    {
    }

    public function name(): string
    {
        return 'muloader';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        $this->plugins = $this->list->pluginsList($config);

        return $this->plugins !== [];
    }

    public function targetPath(Paths $paths): string
    {
        return $paths->wpContent('mu-plugins/sympress-runtime-mu-loader.php');
    }

    public function run(Config $config, Paths $paths): int
    {
        $relative = array_map(fn (string $file): string => Path::makeRelative($file, dirname($this->targetPath($paths))), array_values($this->plugins));
        $template = 'sympress-runtime-mu-loader.php';
        if ($config['compatibility']->is(true) && is_file($paths->template('wpstarter-mu-loader.php'))) {
            $template = 'wpstarter-mu-loader.php';
        }
        $content = $this->builder->build($paths, $template, [
            'MU_PLUGINS_ARRAY' => var_export($relative, true),
            'MU_PLUGINS_LIST' => str_replace(['\\', "'"], ['\\\\', "\\'"], implode(', ', $relative)),
        ]);

        return $this->writer->write($this->targetPath($paths), $content) ? self::SUCCESS : self::ERROR;
    }

    public function success(): string
    {
        return 'MU plugin loader saved.';
    }

    public function error(): string
    {
        return 'Cannot save the MU plugin loader.';
    }

    public function conditionsNotMet(): string
    {
        return 'No MU plugin entry points were discovered.';
    }
}
