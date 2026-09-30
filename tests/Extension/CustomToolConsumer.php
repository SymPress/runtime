<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Extension;

use SymPress\Runtime\Process\PhpTool;
use SymPress\Runtime\Services;

/** Executable consumer fixture: only the stable service factory is needed. */
final class CustomToolConsumer
{
    public function execute(Services $services, PhpTool $descriptor, string $argument): bool
    {
        return $services->phpToolProcessFactory()->create($descriptor)
            ->withEnvironment(['TOOL_EXTENSION_VALUE' => 'public-factory'])
            ->execute([$argument]);
    }
}
