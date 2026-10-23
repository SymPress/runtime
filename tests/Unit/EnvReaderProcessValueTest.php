<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\EnvReader;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- The reader owns the superglobal boundary under test.
final class EnvReaderProcessValueTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('RTV_SAMPLED');
        unset($_ENV['RTV_SAMPLED'], $_SERVER['RTV_SAMPLED']);
    }

    public function testProcessValuesAreSampledOncePerReader(): void
    {
        unset($_ENV['RTV_SAMPLED'], $_SERVER['RTV_SAMPLED']);
        putenv('RTV_SAMPLED=first');
        $reader = new EnvReader();

        self::assertSame('first', $reader->read('RTV_SAMPLED'));

        putenv('RTV_SAMPLED=second');

        self::assertSame('first', $reader->read('RTV_SAMPLED'), 'A reader keeps the value sampled on first read.');
        self::assertSame('second', (new EnvReader())->read('RTV_SAMPLED'), 'The next reader (request) observes the change.');
    }
}
