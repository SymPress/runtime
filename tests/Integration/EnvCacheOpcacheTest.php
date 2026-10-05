<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class EnvCacheOpcacheTest extends TemporaryProject
{
    public function testWarmReadsDoNotWasteOpcacheAndChangedMutableWritesRefreshIt(): void
    {
        $this->write('probe.php', <<<'PHP'
<?php
require $argv[1];
use SymPress\Runtime\Env\EnvCacheFormat;
use SymPress\Runtime\Env\SecureFileWriter;
$first = EnvCacheFormat::encode(['format' => 1, 'value' => 'first']);
foreach (['cache.php', 'dump.php'] as $file) {
    SecureFileWriter::write($file, $first);
    if (!opcache_compile_file($file)) {
        throw new RuntimeException('OPcache fixture must be enabled.');
    }
}
$before = opcache_get_status(false);
for ($i = 0; $i < 4000; ++$i) {
    foreach (['cache.php', 'dump.php'] as $file) {
        if (EnvCacheFormat::read($file)['value'] !== 'first') {
            throw new RuntimeException('Warm environment value changed.');
        }
    }
}
$after = opcache_get_status(false);
$second = EnvCacheFormat::encode(['format' => 1, 'value' => 'second']);
SecureFileWriter::write('cache.php', $second, invalidateOpcache: true);
$refreshed = EnvCacheFormat::read('cache.php')['value'];
$written = opcache_get_status(false);
for ($i = 0; $i < 100; ++$i) {
    SecureFileWriter::write('cache.php', $second, invalidateOpcache: true);
    EnvCacheFormat::read('cache.php');
}
$unchanged = opcache_get_status(false);
echo json_encode([$before, $after, $refreshed, $written, $unchanged]);
PHP);
        $process = new Process([
            PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.file_update_protection=0', '-d', 'opcache.memory_consumption=16',
            '-d', 'opcache.interned_strings_buffer=4',
            '-d', 'opcache.max_accelerated_files=10000', '-d', 'opcache.jit_buffer_size=0',
            $this->root . '/probe.php', dirname(__DIR__, 2) . '/vendor/autoload.php',
        ], $this->root);
        $process->mustRun();
        [$before, $after, $refreshed, $written, $unchanged] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($before['opcache_enabled']);
        self::assertSame($before['memory_usage']['wasted_memory'], $after['memory_usage']['wasted_memory']);
        self::assertSame($before['opcache_statistics']['oom_restarts'], $after['opcache_statistics']['oom_restarts']);
        self::assertSame($before['opcache_statistics']['manual_restarts'], $after['opcache_statistics']['manual_restarts']);
        self::assertGreaterThanOrEqual(8000, $after['opcache_statistics']['hits'] - $before['opcache_statistics']['hits']);
        self::assertSame('second', $refreshed);
        self::assertSame($written['memory_usage']['wasted_memory'], $unchanged['memory_usage']['wasted_memory']);
    }
}
