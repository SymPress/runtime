<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\WpConfigGenerator;
use SymPress\Runtime\Step\FileCreationStepInterface;
use Symfony\Component\Filesystem\Path;

final readonly class WpCliConfigStep implements FileCreationStepInterface
{
    public function __construct(private FileContentBuilder $builder, private Filesystem $files, private ProjectBoundary $boundary, private WpConfigGenerator $configGenerator)
    {
    }

    public function name(): string
    {
        return 'wpcliconfig';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return true;
    }

    public function targetPath(Paths $paths): string
    {
        return $paths->root('wp-cli.yml');
    }

    public function run(Config $config, Paths $paths): int
    {
        $this->boundary->assertWritablePath($this->targetPath($paths));
        $relative = Path::makeRelative($paths->wp(), $paths->root());
        $target = $this->configGenerator->target();
        $exec = 'putenv(' . var_export('WP_CONFIG_PATH=' . $target, true) . ');';
        $execYaml = json_encode($exec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $content = $this->builder->build($paths, 'wp-cli.yml', [
            'WP_INSTALL_PATH_YAML' => json_encode($relative, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'WP_CONFIG_EXEC_LINE' => $config['compatibility-profile']->is('release-3.0.1') ? '' : 'exec: ' . $execYaml,
            'WP_INSTALL_PATH' => $relative,
            'WP_CONFIG_PATH' => $target,
        ]);

        return $this->files->save($content, $this->targetPath($paths)) ? self::SUCCESS : self::ERROR;
    }

    public function success(): string
    {
        return 'WP-CLI configuration saved.';
    }

    public function error(): string
    {
        return 'Cannot save WP-CLI configuration.';
    }
}
