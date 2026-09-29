<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Filesystem\Paths;

interface FileCreationStepInterface extends StepInterface
{
    public function targetPath(Paths $paths): string;
}
