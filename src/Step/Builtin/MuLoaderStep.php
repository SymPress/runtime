<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\ArtifactWriter;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\Step\FileCreationStepInterface;
use Symfony\Component\Filesystem\Path;

/** @internal */
final class MuLoaderStep implements FileCreationStepInterface, ConditionalStepInterface
{
    /** @var array<string, string> */
    private array $plugins = [];

    public function __construct(private readonly MuPluginList $list, private readonly FileContentBuilder $builder, private readonly ArtifactWriter $writer, private readonly PackageFinder $packages)
    {
    }

    public function name(): string
    {
        return 'muloader';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        $this->plugins = $this->list->pluginsList($config);

        return $this->plugins !== [] || file_exists($paths->wpContent('mu-plugins/wpstarter-mu-loader.php')) || is_link($paths->wpContent('mu-plugins/wpstarter-mu-loader.php'));
    }

    public function targetPath(Paths $paths): string
    {
        return $paths->wpContent('mu-plugins/sympress-runtime-mu-loader.php');
    }

    public function run(Config $config, Paths $paths): int
    {
        $legacy = $paths->wpContent('mu-plugins/wpstarter-mu-loader.php');
        $backup = $this->legacyBackup($legacy, $paths);
        $relative = array_map(fn (string $file): string => Path::makeRelative($file, dirname($this->targetPath($paths))), array_values($this->plugins));
        $template = 'sympress-runtime-mu-loader.php';
        if ($config['compatibility']->is(true) && is_file($paths->template('wpstarter-mu-loader.php'))) {
            $template = 'wpstarter-mu-loader.php';
        }
        $content = $this->builder->build($paths, $template, [
            'RUNTIME_VERSION' => $this->packages->runtimeVersion(),
            'MU_PLUGINS_ARRAY' => var_export($relative, true),
            'MU_NATIVE' => var_export($config['compatibility-profile']->is('native'), true),
            'MU_PLUGINS_LIST' => str_replace(['\\', "'"], ['\\\\', "\\'"], implode(', ', $relative)),
        ]);

        if (!$this->writer->write($this->targetPath($paths), $content)) {
            return self::ERROR;
        }
        if ($backup !== null && !rename($legacy, $backup)) {
            return self::ERROR;
        }

        return self::SUCCESS;
    }

    private function legacyBackup(string $legacy, Paths $paths): ?string
    {
        if (!file_exists($legacy) && !is_link($legacy)) {
            return null;
        }
        (new ProjectBoundary($paths))->assertWritablePath($legacy);
        if (is_link($legacy) || !is_file($legacy)) {
            throw new RuntimeException('The legacy MU loader must be reviewed manually: it is not a regular file.');
        }
        $content = file_get_contents($legacy);
        $recognized = false;
        if ($content !== false && preg_match('/ \* Description: MU plugins loaded: (.*?)\.\n/s', $content, $matches) === 1) {
            foreach (['legacy-mu-loader.php.txt', 'legacy-mu-loader-dev.php.txt'] as $file) {
                $template = file_get_contents(dirname(__DIR__, 3) . '/resources/' . $file);
                $recognized = $recognized || ($template !== false && $content === str_replace('{{{MU_PLUGINS_LIST}}}', $matches[1], $template));
            }
        }
        if (!$recognized) {
            throw new RuntimeException('The legacy MU loader contains custom changes. Migrate it manually before running muloader.');
        }
        $backup = $legacy . '.sympress-backup';
        if (file_exists($backup) || is_link($backup)) {
            throw new RuntimeException('The legacy MU loader backup already exists. Review it before retrying migration.');
        }

        return $backup;
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
