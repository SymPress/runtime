<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Config\Options;

final class StableConfigurationTest extends TestCase
{
    public function testExistingV1SchemaAndDefaultsRemainCompatible(): void
    {
        $snapshot = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/config-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2) . '/schema/runtime.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot['properties'] as $name => $contract) {
            self::assertArrayHasKey($name, $schema['properties']);
            self::assertSame($contract, $schema['properties'][$name], 'Review a schema change against the 1.x contract: ' . $name);
        }
        foreach ($snapshot['defaults'] as $profile => $defaults) {
            $current = Options::defaults($profile);
            foreach ($defaults as $name => $value) {
                self::assertArrayHasKey($name, $current);
                self::assertSame($value, $current[$name], $profile . ': ' . $name);
            }
        }
    }
}
