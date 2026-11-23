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
    #[Group('PAR-WPC-002')]
    public function testDefaultSelectsBundledReleaseWithoutReleaseDiscovery(): void
    {
        $release = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';
        foreach (['native', 'release-3.0.1'] as $profile) {
            $http = new MockHttpClient();
            self::assertSame($release, $this->tool($http, profile: $profile)->pharUrl());
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    public function testDefaultReleaseRequiresBundledDigestUnlessExplicitlyOverridden(): void
    {
        $this->write('download.phar', 'synthetic artifact');
        $sidecar = static fn (): MockResponse => new MockResponse(hash('sha512', 'synthetic artifact'));
        $default = $this->tool(new MockHttpClient($sidecar));
        self::assertFalse($default->checkPhar($this->root . '/download.phar', $this->io()));
        $explicitDefault = $this->tool(new MockHttpClient($sidecar), version: '2.12.0');
        self::assertFalse($explicitDefault->checkPhar($this->root . '/download.phar', $this->io()));
        $version = $this->tool(new MockHttpClient($sidecar), version: '2.11.0');
        self::assertTrue($version->checkPhar($this->root . '/download.phar', $this->io()));
        $config = new Config(['wp-cli-sha256' => hash('sha256', 'synthetic artifact')], new Validator(new Paths($this->root)));
        $digest = new WpCliTool($config, new UrlDownloader(new MockHttpClient($sidecar), new Filesystem(), $config), $this->io());
        self::assertTrue($digest->checkPhar($this->root . '/download.phar', $this->io()));
    }

    public function testExactReleaseAndDigestValidationRejectUnsafeOrUnreviewedValues(): void
    {
        $paths = new Paths($this->root);
        foreach (['latest', '2.4.9', '2.12.0-beta1', 'v2.12.0', '2.12.0/attacker'] as $version) {
            $config = new Config(['wp-cli-version' => $version], new Validator($paths));
            self::assertArrayHasKey('wp-cli-version', $config->errors());
        }
        foreach (['abc', str_repeat('g', 64)] as $digest) {
            $config = new Config(['wp-cli-sha256' => $digest], new Validator($paths));
            self::assertArrayHasKey('wp-cli-sha256', $config->errors());
        }
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
