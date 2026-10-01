<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Step\BlockingStepInterface;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\Step\FileCreationStepInterface;
use Symfony\Component\Filesystem\Path;

/** @internal */
final readonly class IndexStep implements BlockingStepInterface, FileCreationStepInterface, ConditionalStepInterface
{
    public function __construct(private Filesystem $filesystem, private FileContentBuilder $builder, private ProjectBoundary $boundary)
    {
    }

    public function name(): string
    {
        return 'index';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return $paths->wp() !== $paths->wpParent();
    }

    public function targetPath(Paths $paths): string
    {
        return $paths->wpParent('index.php');
    }

    public function run(Config $config, Paths $paths): int
    {
        $this->boundary->assertWritablePath($this->targetPath($paths));
        $relative = '/' . Path::makeRelative($paths->wp('index.php'), $paths->wpParent());
        $content = $this->builder->build($paths, 'index.php', [
            'BOOTSTRAP_EXPRESSION' => '__DIR__ . ' . var_export($relative, true),
            'BOOTSTRAP_PATH' => str_replace(['\\', "'"], ['\\\\', "\\'"], $relative),
        ]);

        return $this->filesystem->save($content, $this->targetPath($paths)) ? self::SUCCESS : self::ERROR;
    }

    public function success(): string
    {
        return 'WordPress front controller saved.';
    }

    public function error(): string
    {
        return 'Cannot save the WordPress front controller.';
    }

    public function conditionsNotMet(): string
    {
        return 'WordPress already owns the webroot front controller.';
    }
}
