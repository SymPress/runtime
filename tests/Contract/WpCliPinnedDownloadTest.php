<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use RuntimeException;
use SymPress\Runtime\Application\ContainerFactory;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Download\DownloadLock;
use SymPress\Runtime\Download\PharInstaller;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Process\WpCliTool;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WpCliPinnedDownloadTest extends TemporaryProject
{
    private function io(): Io
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return new Io($input, new BufferedOutput());
    }

    public function testPinnedReleaseAvoidsMetadataAndRecordsOnlyVerifiedPhar(): void
    {
        $paths = new Paths($this->root);
        $url = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';
        $config = new Config(['wp-cli-version' => '2.12.0', 'wp-cli-sha256' => hash('sha256', 'verified phar')], new Validator($paths));
        $http = new MockHttpClient([new MockResponse('verified phar'), new MockResponse(hash('sha512', 'verified phar'))]);
        $lock = new DownloadLock($paths, new ProjectBoundary($paths));
        $downloads = new UrlDownloader($http, new Filesystem(), $config, $lock);
        $tool = new WpCliTool($config, $downloads, $this->io());
        self::assertSame($url, $tool->pharUrl());
        self::assertSame(0, $http->getRequestsCount());
        self::assertSame($paths->root('wp-cli.phar'), (new PharInstaller($this->io(), $downloads))->install($tool, $paths->root('wp-cli.phar')));
        self::assertSame(hash('sha256', 'verified phar'), $lock->entries()[hash('sha256', $url)]);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testFailedAdditionalVerificationNeverPinsPharEvenWithUpdate(): void
    {
        $paths = new Paths($this->root);
        $url = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';
        $config = new Config(['wp-cli-version' => '2.12.0', 'update-lock' => true], new Validator($paths));
        $lock = new DownloadLock($paths, new ProjectBoundary($paths));
        $http = new MockHttpClient([new MockResponse('bad phar'), new MockResponse(hash('sha512', 'expected phar'))]);
        $downloads = new UrlDownloader($http, new Filesystem(), $config, $lock);
        $tool = new WpCliTool($config, $downloads, $this->io());
        self::assertSame('', (new PharInstaller($this->io(), $downloads))->install($tool, $paths->root('wp-cli.phar')));
        self::assertArrayNotHasKey(hash('sha256', $url), $lock->entries());
        self::assertFileDoesNotExist($paths->root('wp-cli.phar'));
    }

    public function testExplicitSha256CannotBeOverriddenAndAlsoChecksLocalPhar(): void
    {
        $paths = new Paths($this->root);
        $config = new Config(['wp-cli-version' => '2.12.0', 'wp-cli-sha256' => hash('sha256', 'expected phar'), 'update-lock' => true], new Validator($paths));
        $http = new MockHttpClient(new MockResponse('bad phar'));
        $downloads = new UrlDownloader($http, new Filesystem(), $config, new DownloadLock($paths, new ProjectBoundary($paths)));
        $tool = new WpCliTool($config, $downloads, $this->io());
        self::assertSame('', (new PharInstaller($this->io(), $downloads))->install($tool, $paths->root('wp-cli.phar')));
        self::assertFileDoesNotExist($paths->root('sympress-runtime.lock'));
        $this->write('wp-cli.phar', 'bad local phar');
        self::assertFalse($tool->checkLocalPhar($paths->root('wp-cli.phar'), $this->io()));
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testLogicalPinRejectsTamperedLocalPharAndDifferentLatestVersionUntilUpdated(): void
    {
        $paths = new Paths($this->root);
        $lock = new DownloadLock($paths, new ProjectBoundary($paths));
        $url = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';
        $config = new Config(['wp-cli-version' => '2.12.0'], new Validator($paths));
        $http = new MockHttpClient([new MockResponse('first phar'), new MockResponse(hash('sha512', 'first phar'))]);
        $downloads = new UrlDownloader($http, new Filesystem(), $config, $lock);
        $tool = new WpCliTool($config, $downloads, $this->io());
        $target = $paths->root('wp-cli.phar');
        self::assertSame($target, (new PharInstaller($this->io(), $downloads))->install($tool, $target));
        self::assertSame(hash('sha256', 'first phar'), $lock->artifactDigest('wp-cli.phar'));
        self::assertTrue($tool->checkLocalPhar($target, $this->io()));
        chmod($target, 0600);
        file_put_contents($target, 'tampered phar');
        self::assertFalse($tool->checkLocalPhar($target, $this->io()));
        self::assertSame(2, $http->getRequestsCount());
        self::assertSame(hash('sha256', 'first phar'), $lock->artifactDigest('wp-cli.phar'));
        chmod($target, 0600);
        file_put_contents($target, 'first phar');
        $nextUrl = 'https://github.com/wp-cli/wp-cli/releases/download/v2.13.0/wp-cli-2.13.0.phar';
        $nextConfig = new Config([], new Validator($paths));
        $nextHttp = new MockHttpClient([
            new MockResponse(json_encode(['assets' => [['browser_download_url' => $nextUrl]]], JSON_THROW_ON_ERROR)),
            new MockResponse('next phar'),
            new MockResponse(hash('sha512', 'next phar')),
        ]);
        $nextDownloads = new UrlDownloader($nextHttp, new Filesystem(), $nextConfig, $lock);
        $nextTool = new WpCliTool($nextConfig, $nextDownloads, $this->io());
        self::assertSame('', (new PharInstaller($this->io(), $nextDownloads))->install($nextTool, $target));
        self::assertSame('first phar', file_get_contents($target));
        self::assertArrayNotHasKey(hash('sha256', $nextUrl), $lock->entries());
        self::assertSame(hash('sha256', 'first phar'), $lock->entries()[hash('sha256', $url)]);
        $updateConfig = new Config(['wp-cli-version' => '2.13.0', 'update-lock' => true], new Validator($paths));
        $updateHttp = new MockHttpClient([new MockResponse('next phar'), new MockResponse(hash('sha512', 'next phar'))]);
        $updateDownloads = new UrlDownloader($updateHttp, new Filesystem(), $updateConfig, $lock);
        $updateTool = new WpCliTool($updateConfig, $updateDownloads, $this->io());
        self::assertSame($target, (new PharInstaller($this->io(), $updateDownloads))->install($updateTool, $target));
        self::assertSame('next phar', file_get_contents($target));
        self::assertSame(hash('sha256', 'next phar'), $lock->artifactDigest('wp-cli.phar'));
        self::assertTrue($updateTool->checkLocalPhar($target, $this->io()));
    }

    public function testUnpinnedExistingLocalPharIsOnlyAdoptedByExplicitUpdate(): void
    {
        $paths = new Paths($this->root);
        $this->write('wp-cli.phar', 'owned local phar');
        $lock = new DownloadLock($paths, new ProjectBoundary($paths));
        foreach ([false, true] as $update) {
            $config = new Config(['install-wp-cli' => false, 'update-lock' => $update], new Validator($paths));
            $http = new MockHttpClient();
            $tool = new WpCliTool($config, new UrlDownloader($http, new Filesystem(), $config, $lock), $this->io());
            self::assertTrue($tool->checkLocalPhar($paths->root('wp-cli.phar'), $this->io()));
            self::assertSame($update ? hash('sha256', 'owned local phar') : null, $lock->artifactDigest('wp-cli.phar'));
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    public function testTamperedPinnedPharIsRejectedBeforeProcessExecutionWithoutNetwork(): void
    {
        $paths = new Paths($this->root);
        (new DownloadLock($paths, new ProjectBoundary($paths)))->acceptArtifact('wp-cli.phar', hash('sha256', 'trusted phar'), false);
        $this->write('wp-cli.phar', '<?php file_put_contents("must-not-execute", "tampered");');
        $config = new Config(['install-wp-cli' => false], new Validator($paths));
        $container = (new ContainerFactory())->create($config, $paths, $this->io(), new RunContext($this->root, $paths->vendor(), $paths->bin()), new Registry(), static function (ContainerBuilder $builder): void {
            $builder->register(HttpClientInterface::class)->setSynthetic(true)->setPublic(true);
        });
        $http = new MockHttpClient();
        $container->set(HttpClientInterface::class, $http);
        $services = $container->get(Services::class);
        self::assertInstanceOf(Services::class, $services);
        try {
            $services->wpCliProcess()->executeSilently(['cli', 'version']);
            self::fail('Tampered local PHAR must not execute.');
        } catch (RuntimeException $error) {
            self::assertSame('Local WP-CLI PHAR failed integrity validation.', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/must-not-execute');
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testInstalledComposerBundleUsesCoreBootstrapWithoutDownloading(): void
    {
        $this->write('vendor/composer/installed.json', json_encode([
        'packages' => [
            ['name' => 'wp-cli/wp-cli-bundle', 'version' => '2.12.0', 'install-path' => '../wp-cli/wp-cli-bundle'],
            ['name' => 'wp-cli/wp-cli', 'version' => '2.12.0', 'install-path' => '../wp-cli/wp-cli'],
        ],
        ], JSON_THROW_ON_ERROR));
        $this->write('vendor/wp-cli/wp-cli/php/boot-fs.php', '<?php file_put_contents("bundle-executed", "yes");');
        $this->write('wp-cli.phar', '<?php exit(1);');
        $paths = new Paths($this->root);
        $config = new Config(['install-wp-cli' => true], new Validator($paths));
        $container = (new ContainerFactory())->create($config, $paths, $this->io(), new RunContext($this->root, $paths->vendor(), $paths->bin()), new Registry(), static function (ContainerBuilder $builder): void {
            $builder->register(HttpClientInterface::class)->setSynthetic(true)->setPublic(true);
        });
        $http = new MockHttpClient();
        $container->set(HttpClientInterface::class, $http);
        $services = $container->get(Services::class);
        self::assertInstanceOf(Services::class, $services);
        self::assertTrue($services->wpCliProcess()->executeSilently(['cli', 'version']));
        self::assertSame('yes', file_get_contents($this->root . '/bundle-executed'));
        self::assertSame(0, $http->getRequestsCount());
    }
}
