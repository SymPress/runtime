<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use SymPress\Runtime\Application\ContainerFactory;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Download\PharInstaller;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Process\PhpProcess;
use SymPress\Runtime\Process\PhpToolProcessFactory;
use SymPress\Runtime\Process\SystemProcess;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Tests\Extension\CustomToolConsumer;
use SymPress\Runtime\Tests\Fixtures\PhpToolProbe;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PhpToolsTest extends TemporaryProject
{
    private function io(): Io
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return new Io($input, new BufferedOutput());
    }

    private function installer(MockHttpClient $http): PharInstaller
    {
        return new PharInstaller($this->io(), new UrlDownloader($http, new Filesystem(), new Config([], new Validator(new Paths($this->root)))));
    }

    private function factory(MockHttpClient $http): PhpToolProcessFactory
    {
        $paths = new Paths($this->root);
        $packages = new PackageFinder(new RunContext($this->root, $paths->vendor(), $paths->bin()));

        return new PhpToolProcessFactory($paths, $this->io(), $this->installer($http), $packages, new PhpProcess(new SystemProcess($paths, $this->io())));
    }

    #[Group('PAR-SVC-012')]
    #[Group('PAR-WPC-003')]
    public function testPharIsVerifiedBeforeAtomicReplacementAndTemporaryFilesAreRemoved(): void
    {
        $this->write('tool.phar', 'original');
        $installer = $this->installer(new MockHttpClient([new MockResponse('corrupt'), new MockResponse('valid tool'), new MockResponse('failure', ['http_code' => 503])]));
        $tool = new PhpToolProbe();
        $path = $this->root . '/tool.phar';
        self::assertSame('', $installer->install($tool, $path));
        self::assertSame('original', file_get_contents($path));
        self::assertSame([], glob($this->root . '/.sympress-download-*'));
        self::assertSame($path, $installer->install($tool, $path));
        self::assertSame('valid tool', file_get_contents($path));
        self::assertSame(0550, fileperms($path) & 0777);
        self::assertSame('', $installer->install($tool, $path));
        self::assertSame('valid tool', file_get_contents($path));
        self::assertSame([], glob($this->root . '/.sympress-download-*'));
    }

    #[Group('PAR-SVC-012')]
    public function testPharInstallerRejectsDirectoriesLinksAndDisabledDownloads(): void
    {
        $http = new MockHttpClient();
        $installer = $this->installer($http);
        $tool = new PhpToolProbe();
        self::assertSame('', $installer->install($tool, $this->root));
        $this->write('original', 'preserve');
        self::assertTrue(symlink($this->root . '/original', $this->root . '/link'));
        self::assertSame('', $installer->install($tool, $this->root . '/link'));
        $tool->url = '';
        self::assertSame('', $installer->install($tool, $this->root . '/disabled'));
        self::assertSame(0, $http->getRequestsCount());
        self::assertSame('preserve', file_get_contents($this->root . '/original'));
    }

    #[Group('PAR-SVC-019')]
    #[Group('PAR-SVC-020')]
    #[Group('PAR-WPC-001')]
    public function testPackageWinsAndSelectedPhpEnvironmentAndArgumentBoundariesAreRetained(): void
    {
        $source = '<?php file_put_contents("result.json", json_encode([$argv[1], getenv("TOOL_FIXTURE"), "package"]));';
        $this->write('vendor/fixture/tool/boot.php', $source);
        $this->write('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'fixture/tool', 'version' => '1.2.0', 'install-path' => '../fixture/tool']]], JSON_THROW_ON_ERROR));
        $this->write('fixture.phar', '<?php exit(9);');
        $this->write('php wrapper', "#!/bin/sh\nprintf used > " . escapeshellarg($this->root . '/wrapper-used') . "\nexec " . escapeshellarg(PHP_BINARY) . ' "$@"' . "\n");
        chmod($this->root . '/php wrapper', 0700);
        $http = new MockHttpClient();
        $tool = $this->factory($http)->create(new PhpToolProbe(), $this->root . '/php wrapper');
        self::assertTrue($tool->withEnvironment(['TOOL_FIXTURE' => 'synthetic'])->execute(['$(touch injected); argument with spaces']));
        self::assertSame(['$(touch injected); argument with spaces', 'synthetic', 'package'], json_decode((string) file_get_contents($this->root . '/result.json'), true));
        self::assertFileDoesNotExist($this->root . '/injected');
        self::assertSame('used', file_get_contents($this->root . '/wrapper-used'));
        self::assertSame(0, $http->getRequestsCount());
    }

    #[Group('PAR-SVC-020')]
    public function testLocalPharWorksOfflineAndFallbackDownloadIsVerified(): void
    {
        $this->write('fixture.phar', '<?php exit(0);');
        $http = new MockHttpClient();
        $descriptor = new PhpToolProbe();
        $descriptor->url = '';
        self::assertTrue($this->factory($http)->create($descriptor)->executeSilently([]));
        self::assertSame(0, $http->getRequestsCount());
        unlink($this->root . '/fixture.phar');
        $descriptor->url = 'https://example.test/tool.phar';
        $descriptor->expected = '<?php exit(0);';
        $http = new MockHttpClient(new MockResponse($descriptor->expected));
        self::assertTrue($this->factory($http)->create($descriptor)->executeSilently([]));
        self::assertSame(1, $http->getRequestsCount());
        self::assertFileExists($this->root . '/fixture.phar');
    }

    public function testExtensionCanExecuteCustomPhpToolThroughThePublicServiceFactory(): void
    {
        $this->write('fixture.phar', '<?php file_put_contents("extension-result.json", json_encode([$argv[1], getenv("TOOL_EXTENSION_VALUE")]));');
        $paths = new Paths($this->root);
        $config = new Config([], new Validator($paths));
        $context = new RunContext($this->root, $paths->vendor(), $paths->bin());
        $container = (new ContainerFactory())->create($config, $paths, $this->io(), $context, new Registry());
        $services = $container->get(Services::class);
        self::assertInstanceOf(Services::class, $services);
        $descriptor = new PhpToolProbe();
        $descriptor->url = '';
        $argument = '$(touch injected); argument with spaces';
        self::assertTrue((new CustomToolConsumer())->execute($services, $descriptor, $argument));
        self::assertSame([$argument, 'public-factory'], json_decode((string) file_get_contents($this->root . '/extension-result.json'), true));
        self::assertFileDoesNotExist($this->root . '/injected');
    }

    public function testMissingPhpFailsBeforeNetworkOrWriting(): void
    {
        $http = new MockHttpClient();
        try {
            $this->factory($http)->create(new PhpToolProbe(), $this->root . '/absent-php');
            self::fail('Missing PHP must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('PHP executable', $error->getMessage());
        }
        self::assertSame(0, $http->getRequestsCount());
        self::assertFileDoesNotExist($this->root . '/fixture.phar');
    }
}
