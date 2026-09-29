<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use SymPress\Kernel\App;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\SiteKernel;
use SymPress\Runtime\Bridge\Kernel\RuntimeBundle;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Kernel\KernelPaths;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class KernelBridgeTest extends TemporaryProject
{
    #[Group('PAR-SYM-003')]
    public function testAnExplicitKernelEnvironmentOverrideCannotSelectAnotherCacheSilently(): void
    {
        $reader = new EnvReader();
        $reader->write('WP_ENVIRONMENT_TYPE', 'production');
        App::new(new SiteKernel($this->root, 'test'));
        $this->expectExceptionMessage('active kernel environment differs');
        (new KernelPaths($reader, new Paths($this->root)))->clearTargets();
    }

    #[Group('PAR-SYM-003')]
    #[Group('PAR-SYM-006')]
    public function testKernelAndRuntimeResolveTheSameCanonicalEnvironmentAndOverridePaths(): void
    {
        $reader = new EnvReader();
        $reader->write('WP_ENV', 'preprod-eu-1');
        $reader->write('APP_CACHE_DIR', 'custom/cache');
        $reader->write('APP_BUILD_DIR', $this->root . '/custom/build');
        $reader->setupConstants();
        $kernel = new SiteKernel($this->root);
        $paths = new KernelPaths($reader, new Paths($this->root));
        self::assertSame('staging', $kernel->getEnvironment());
        self::assertSame($kernel->getEnvironment(), $paths->environment());
        self::assertSame($kernel->getCacheDir(), $paths->cache());
        self::assertSame($kernel->getBuildDir(), $paths->build());
    }

    /** @return iterable<string, array{?string}> */
    public static function environmentSources(): iterable
    {
        yield 'fresh environment' => [null];
        yield 'runtime cache' => ['.env.cached.php'];
        yield 'build dump' => ['.env.dump.php'];
    }

    #[DataProvider('environmentSources')]
    #[Group('PAR-SYM-005')]
    #[Group('PAR-SYM-006')]
    public function testActualSiteKernelContainerLoadsRuntimeCommandsAndEnvironmentServices(?string $dump): void
    {
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"require-wp":false,"db-check":false}}}');
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $reader = new EnvReader();
        $reader->write('WP_ENVIRONMENT_TYPE', 'production');
        $reader->write('RUNTIME_KERNEL_TEST_VALUE', 'available to env processor');
        if ($dump !== null) {
            self::assertTrue($reader->dumpCached($this->root . '/' . $dump));
            // phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Verify restoration into the actual kernel environment sources.
            unset($_ENV['RUNTIME_KERNEL_TEST_VALUE'], $_SERVER['RUNTIME_KERNEL_TEST_VALUE']);
            $reader = EnvReader::buildFromCacheDump($this->root . '/' . $dump);
            self::assertSame('available to env processor', $_ENV['RUNTIME_KERNEL_TEST_VALUE']);
            self::assertSame('available to env processor', $_SERVER['RUNTIME_KERNEL_TEST_VALUE']);
            // phpcs:enable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable
        }
        $reader->setupConstants();
        $kernel = new SiteKernel($this->root);
        $container = $kernel->createContainer();
        $bundle = new RuntimeBundle();
        $registry = (new BundleRegistry())->add(new BundleMetadata('sympress/runtime', 'composer-plugin', 'sympress/runtime', $bundle->getPath(), $bundle->getPath() . '/composer.json', $bundle));
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        $container->builder()->register('fixture.env', \ArrayObject::class)->setArguments([['value' => '%env(RUNTIME_KERNEL_TEST_VALUE)%']])->setPublic(true);
        $kernel->createRuntimeContainer($container, $registry, $files);
        $application = $container->get(Application::class);
        self::assertInstanceOf(Application::class, $application);
        foreach (['doctor', 'check', 'validate', 'dump-env', 'debug:container'] as $name) {
            self::assertTrue($application->has($name), $name . ' must be registered in the actual kernel console.');
        }
        self::assertSame('available to env processor', $container->get('fixture.env')['value']);
        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput(['command' => 'validate', '--no-interaction' => true]), $output);
        $text = $output->fetch();
        self::assertSame(0, $status, $text);
        self::assertStringContainsString('Runtime configuration is valid', $text);
        self::assertDirectoryExists($kernel->getCacheDir());
        // WP-CLI rewrites --json to --format=json before invoking the kernel bridge.
        $status = $application->run(new ArrayInput(['command' => 'doctor', '--format' => 'json', '--no-interaction' => true]), $output);
        $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame($status, $report['exit']);
        self::assertArrayHasKey('checks', $report);
    }
}
