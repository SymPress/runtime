<?php

declare(strict_types=1);

namespace SymPress\Runtime\Filesystem;

use RuntimeException;
use Symfony\Component\Filesystem\Path;

/** @internal */
final readonly class ProjectBoundary
{
    public function __construct(private Paths $paths)
    {
    }

    /** Check existing ancestors too, so a missing leaf cannot conceal an escaping symlink. */
    public function assertWritablePath(string $path): void
    {
        $root = realpath($this->paths->root());
        $canonical = Path::canonicalize($path);
        if ($root === false || !Path::isBasePath($this->paths->root(), $canonical)) {
            throw new RuntimeException('Generated target must be inside the project root.');
        }
        $ancestor = $canonical;
        while (!file_exists($ancestor)) {
            if (is_link($ancestor)) {
                throw new RuntimeException('Generated target has a broken symlink ancestor.');
            }
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new RuntimeException('Generated target cannot be resolved.');
            }
            $ancestor = $parent;
        }
        $physical = realpath($ancestor);
        if ($physical === false || ($physical !== $root && !Path::isBasePath($root, $physical))) {
            throw new RuntimeException('Generated target resolves outside the project root.');
        }
    }
}
