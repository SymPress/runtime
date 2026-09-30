<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Filesystem\Paths;

/** @api */
interface FileCreationStepInterface extends StepInterface
{
    /** @api */
    public function targetPath(Paths $paths): string;
}
