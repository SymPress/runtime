<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Fixtures;

use SymPress\Runtime\Step\StepInterface;

final class StepTrace
{
    /** @var list<string> */
    public array $events = [];

    public int $result = StepInterface::SUCCESS;

    public bool $allowed = true;

    public bool $confirmed = true;
}
