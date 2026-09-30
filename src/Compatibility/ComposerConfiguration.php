<?php

declare(strict_types=1);

namespace SymPress\Runtime\Compatibility;

use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\ConfigLoader;
use Symfony\Component\Filesystem\Path;

/** Read-only project configuration; deliberately not a live Composer Config instance. */
final readonly class ComposerConfiguration
{
    public function __construct(private RunContext $context)
    {
    }

    public function get(string $key, int $flags = 0): mixed
    {
        if ($key === 'vendor-dir' || $key === 'bin-dir') {
            $path = $key === 'vendor-dir' ? $this->context->vendor : $this->context->bin;

            return ($flags & 1) !== 0 ? Path::makeRelative($path, $this->context->root) : $path;
        }
        $manifest = (new ConfigLoader())->readObject($this->context->manifestPath());
        $config = $manifest['config'] ?? [];

        return is_array($config) ? ($config[$key] ?? null) : null;
    }
}
