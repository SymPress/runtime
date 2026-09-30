<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\ArtifactWriter;
use SymPress\Runtime\Kernel\KernelPaths;
use SymPress\Runtime\Step\Builtin\KernelCacheStep;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class KernelCacheTest extends TemporaryProject
{
    /** @return array{KernelCacheStep, KernelPaths, Paths} */
    private function fixture(bool $overrides = true): array
    {
        $paths = new Paths($this->root);
        $env = new EnvReader();
        $env->write('WP_ENVIRONMENT_TYPE', 'preprod-eu-1');
        if ($overrides) {
            $env->write('APP_CACHE_DIR', 'custom/cache');
            $env->write('APP_BUILD_DIR', $this->root . '/custom/build');
        }
        $kernel = new KernelPaths($env, $paths);
        $boundary = new ProjectBoundary($paths);

        return [new KernelCacheStep($kernel, $boundary, new ArtifactWriter($boundary), new Io(new ArrayInput([]), new BufferedOutput())), $kernel, $paths];
    }

    #[Group('PAR-SYM-003')]
    public function testCanonicalEnvironmentOverridesAndDiscoveryAreClearedWithoutOtherCaches(): void
    {
        [$step, $kernel, $paths] = $this->fixture();
        self::assertSame('staging', $kernel->environment());
        $this->write('custom/cache/staging/kernel/container.php', 'cache');
        $this->write('custom/build/staging/kernel/container.php', 'build');
        $this->write('var/cache/staging/kernel/discovery-packages.php', 'discovery');
        $this->write('var/cache/staging/kernel/keep.php', 'unrelated default-cache entry');
        $this->write('custom/cache/production/kernel/container.php', 'other environment');
        $this->write('custom/cache/staging/assets/asset.css', 'asset compiler');
        self::assertSame(StepInterface::SUCCESS, $step->run(new Config([], new Validator($paths)), $paths));
        self::assertDirectoryDoesNotExist($kernel->cache());
        self::assertDirectoryDoesNotExist($kernel->build());
        self::assertFileDoesNotExist($kernel->discovery());
        foreach (['var/cache/staging/kernel/keep.php', 'custom/cache/production/kernel/container.php', 'custom/cache/staging/assets/asset.css'] as $file) {
            self::assertFileExists($this->root . '/' . $file);
        }
    }

    #[Group('PAR-SYM-003')]
    public function testEscapingAncestorPreventsAllDeletionAndChildSymlinksAreNotFollowed(): void
    {
        [$step, $kernel, $paths] = $this->fixture();
        $this->write('custom/cache/staging/kernel/keep.php', 'keep');
        self::assertTrue(symlink(sys_get_temp_dir(), $this->root . '/custom/build'));
        try {
            $step->run(new Config([], new Validator($paths)), $paths);
            self::fail('Escaping build path must fail before deleting the valid cache.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('outside the project root', $error->getMessage());
        }
        self::assertFileExists($kernel->cache() . '/keep.php');
        unlink($this->root . '/custom/build');
        $this->write('assets/keep.css', 'keep asset');
        self::assertTrue(symlink($this->root . '/assets', $kernel->cache() . '/assets'));
        self::assertSame(StepInterface::SUCCESS, $step->run(new Config([], new Validator($paths)), $paths));
        self::assertSame('keep asset', file_get_contents($this->root . '/assets/keep.css'));
    }

    #[Group('PAR-SYM-003')]
    #[Group('PAR-NATIVE-008')]
    public function testBuildIdIsExplicitPrivateAndPreservedOnNoopRuns(): void
    {
        [$step, $kernel, $paths] = $this->fixture(false);
        $id = new Config(['kernel-build-id' => 'release-123'], new Validator($paths));
        self::assertSame(StepInterface::SUCCESS, $step->run($id, $paths));
        $before = file_get_contents($kernel->buildIdFile());
        self::assertSame(['environment' => 'staging', 'id' => 'release-123'], json_decode((string) $before, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(0600, fileperms($kernel->buildIdFile()) & 0777);
        self::assertSame(StepInterface::SUCCESS, $step->run(new Config([], new Validator($paths)), $paths));
        self::assertSame($before, file_get_contents($kernel->buildIdFile()));
    }

    #[Group('PAR-SYM-003')]
    #[Group('PAR-NATIVE-008')]
    public function testCliGenerationRequiresExplicitCacheSelectionAndUsesValidatedIds(): void
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('composer.json', '{"extra":{"sympress-runtime":{}}}');
        $environment = ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false, 'WP_ENVIRONMENT_TYPE' => 'production'];
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/sympress-runtime', '-n', '--generate-build-id'];
        $invalid = new Process($command, $this->root, $environment);
        $invalid->run();
        self::assertNotSame(0, $invalid->getExitCode());
        self::assertDirectoryDoesNotExist($this->root . '/var');
        $valid = new Process([...$command, 'kernel-cache'], $this->root, $environment);
        $valid->run();
        self::assertSame(0, $valid->getExitCode(), $valid->getErrorOutput());
        self::assertMatchesRegularExpression('/SYMPRESS_KERNEL_BUILD_ID=[a-f0-9]{32}/', $valid->getOutput());
        self::assertFileExists($this->root . '/var/runtime/production/kernel-build-id.json');
    }
}
