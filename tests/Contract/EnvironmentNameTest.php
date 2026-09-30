<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\EnvironmentName;

final class EnvironmentNameTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        $groups = [
            'local' => ['local'],
            'development' => ['development', 'dev', 'develop'],
            'staging' => ['staging', 'stage', 'pre', 'preprod', 'pre-prod', 'pre-production', 'preproduction', 'test', 'tests', 'testing', 'uat', 'qa', 'acceptance', 'accept'],
            'production' => ['production', 'prod', 'live', 'public'],
        ];
        foreach ($groups as $expected => $names) {
            foreach ($names as $name) {
                yield $name => [$name, $expected];
            }
        }
        yield 'bounded contains' => ['my_dev_one', 'development'];
        yield 'not arbitrary substring' => ['my_devone', 'production'];
        yield 'catalog priority' => ['prod-dev-local', 'local'];
        yield 'case insensitive' => ['PREPROD-EU-1', 'staging'];
        yield 'unknown' => ['custom', 'production'];
    }

    #[DataProvider('names')]
    #[Group('PAR-ENV-008')]
    #[Group('PAR-ENV-009')]
    public function testEnvironmentMapping(string $name, string $expected): void
    {
        self::assertSame($expected, EnvironmentName::canonical($name));
    }
}
