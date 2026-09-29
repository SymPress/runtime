<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Tests\Support\TemporaryProject;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class KernelBuildIdTest extends TemporaryProject
{
    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        foreach (['environment', 'cache', 'configuration', 'persisted'] as $source) {
            yield $source => [$source];
        }
    }

    #[DataProvider('sources')]
    #[Group('PAR-SYM-006')]
    #[Group('PAR-NATIVE-008')]
    public function testBuildIdReachesKernelConstantFromEachSupportedSource(string $source): void
    {
        $reader = new EnvReader();
        $reader->write('WP_ENVIRONMENT_TYPE', 'production');
        if (in_array($source, ['environment', 'cache'], true)) {
            $reader->write('SYMPRESS_KERNEL_BUILD_ID', 'release-123');
        }
        if ($source === 'cache') {
            self::assertTrue($reader->dumpCached($this->root . '/cache.php'));
            $reader = EnvReader::buildFromCacheDump($this->root . '/cache.php');
        }
        if ($source === 'persisted') {
            $this->write('production/kernel-build-id.json', '{"environment":"production","id":"release-123"}');
        }
        $reader->setupKernelBuildId($this->root, $source === 'configuration' ? 'release-123' : null);
        self::assertSame('release-123', constant('SYMPRESS_KERNEL_BUILD_ID'));
        $reader->setupKernelBuildId($this->root, 'ignored-override');
        self::assertSame('release-123', constant('SYMPRESS_KERNEL_BUILD_ID'));
    }

    #[Group('PAR-SYM-006')]
    public function testProcessValueWinsOverPersistedAndConfiguredIds(): void
    {
        putenv('SYMPRESS_KERNEL_BUILD_ID=actual-deployment');
        $reader = new EnvReader();
        $reader->write('WP_ENVIRONMENT_TYPE', 'production');
        $this->write('production/kernel-build-id.json', '{"environment":"production","id":"stale"}');
        $reader->setupKernelBuildId($this->root, 'config-fallback');
        self::assertSame('actual-deployment', constant('SYMPRESS_KERNEL_BUILD_ID'));
    }

    #[Group('PAR-NATIVE-008')]
    public function testMalformedPersistedIdFailsWithoutRevealingValues(): void
    {
        $reader = new EnvReader();
        $reader->write('WP_ENVIRONMENT_TYPE', 'production');
        $this->write('production/kernel-build-id.json', '{"environment":"production","id":"private invalid value"}');
        $this->expectExceptionMessage('Invalid kernel build ID.');
        $reader->setupKernelBuildId($this->root);
    }
}
