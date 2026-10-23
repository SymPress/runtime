<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Env\EnvCacheSources;

final class EnvCacheSourcesTest extends TestCase
{
    public function testMissingPathHasNullSignatureWithoutLeavingAPhpError(): void
    {
        error_clear_last();

        self::assertNull(EnvCacheSources::signature(sys_get_temp_dir() . '/sympress-missing-' . bin2hex(random_bytes(8))));
        // A suppressed warning still lands in error_get_last(), which makes WordPress add the php-error admin class.
        self::assertNull(error_get_last());
    }

    public function testExistingFileKeepsSizeAndMtimeSignature(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sympress-env');
        self::assertIsString($file);
        try {
            file_put_contents($file, 'APP_ENV=test');
            self::assertSame([12, filemtime($file)], EnvCacheSources::signature($file));
        } finally {
            unlink($file);
        }
    }
}
