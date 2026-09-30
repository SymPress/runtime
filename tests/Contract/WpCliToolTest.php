<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Application\ContainerFactory;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
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

final class WpCliToolTest extends TemporaryProject
{
    private function io(): Io
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return new Io($input, new BufferedOutput());
    }

    private function tool(MockHttpClient $http, bool $enabled = true, string $profile = 'native', ?string $version = null): WpCliTool
    {
        $config = new Config(['install-wp-cli' => $enabled, 'wp-cli-version' => $version], new Validator(new Paths($this->root)), $profile);

        return new WpCliTool($config, new UrlDownloader($http, new Filesystem(), $config), $this->io());
    }

    #[Group('PAR-SVC-021')]
    #[Group('PAR-WPC-001')]
    public function testLocalDiscoveryIsOfflineAndPrefersDefaultThenHighestVersion(): void
    {
        $http = new MockHttpClient();
        $tool = $this->tool($http, false);
        $paths = new Paths($this->root);
        foreach (['2.4.0', '2.9.0', '2.10.0', '2.11.0-beta1'] as $version) {
            $this->write('wp-cli-' . $version . '.phar', '<?php');
        }
        self::assertSame($paths->root('wp-cli-2.11.0-beta1.phar'), $tool->pharTarget($paths));
        $this->write('wp-cli.phar', '<?php');
        self::assertSame($paths->root('wp-cli.phar'), $tool->pharTarget($paths));
        self::assertSame('', $tool->pharUrl());
        self::assertSame(0, $http->getRequestsCount());
    }

    #[Group('PAR-SVC-021')]
    #[Group('PAR-WPC-002')]
    public function testLatestReleaseLookupIsCachedAndOnlyAcceptsOfficialMatchingAssets(): void
    {
        $release = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';
        $http = new MockHttpClient(new MockResponse(json_encode([
        'assets' => [
            ['browser_download_url' => 'https://example.test/wp-cli-2.99.0.phar'],
            ['browser_download_url' => 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.13.0.phar'],
            ['browser_download_url' => $release],
        ],
        ], JSON_THROW_ON_ERROR)));
        $tool = $this->tool($http);
        self::assertSame($release, $tool->pharUrl());
        self::assertSame($release, $tool->pharUrl());
        self::assertSame(1, $http->getRequestsCount());
        $fallback = $this->tool(new MockHttpClient(new MockResponse('invalid response')));
        self::assertSame('', $fallback->pharUrl());
        $legacy = $this->tool(new MockHttpClient(new MockResponse('invalid response')), profile: 'release-3.0.1');
        self::assertSame('https://github.com/wp-cli/wp-cli/releases/download/v2.5.0/wp-cli-2.5.0.phar', $legacy->pharUrl());
    }

    #[Group('PAR-SVC-021')]
    #[Group('PAR-WPC-003')]
    public function testSha512IsRequiredAndMismatchCannotPass(): void
    {
        $this->write('download.phar', 'synthetic artifact');
        $http = new MockHttpClient([
            new MockResponse(str_repeat('0', 128)),
            new MockResponse('not a sha512 checksum'),
            new MockResponse(strtoupper(hash('sha512', 'synthetic artifact')) . "\n"),
        ]);
        $tool = $this->tool($http, version: '2.5.0');
        self::assertFalse($tool->checkPhar($this->root . '/download.phar', $this->io()));
        self::assertFalse($tool->checkPhar($this->root . '/download.phar', $this->io()));
        self::assertTrue($tool->checkPhar($this->root . '/download.phar', $this->io()));
        self::assertSame(3, $http->getRequestsCount());
    }

    #[Group('PAR-SVC-021')]
    public function testServicesProvideOneLazyProcessWithFixedQuotedWordPressPath(): void
    {
        $paths = new Paths($this->root, wp: 'public site/wordpress', content: 'public site/wp-content');
        $config = new Config(['install-wp-cli' => false], new Validator($paths));
        $context = new RunContext($this->root, $paths->vendor(), $paths->bin());
        $container = (new ContainerFactory())->create($config, $paths, $this->io(), $context, new Registry(), static function (ContainerBuilder $builder): void {
            $builder->register(HttpClientInterface::class)->setSynthetic(true)->setPublic(true);
        });
        $http = new MockHttpClient();
        $container->set(HttpClientInterface::class, $http);
        $services = $container->get(Services::class);
        self::assertInstanceOf(Services::class, $services);
        self::assertSame(0, $http->getRequestsCount());
        $this->write('wp-cli.phar', '<?php file_put_contents("wp-cli-arguments.json", json_encode([$argv, getenv("WP_TOOL_FIXTURE")]));');
        $process = $services->wpCliProcess();
        self::assertSame($process, $services->wpCliProcess());
        self::assertTrue($process->withEnvironment(['WP_TOOL_FIXTURE' => 'synthetic'])->executeSilently(['option', 'get', 'name with spaces']));
        $record = json_decode((string) file_get_contents($this->root . '/wp-cli-arguments.json'), true);
        self::assertIsArray($record);
        self::assertIsArray($record[0]);
        self::assertSame(['option', 'get', 'name with spaces', '--path=' . $paths->wp()], array_slice($record[0], 1));
        self::assertSame('synthetic', $record[1]);
        self::assertSame(0, $http->getRequestsCount());
    }
}
