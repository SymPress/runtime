<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UrlDownloaderTest extends TemporaryProject
{
    public function testStreamLimitRejectsLargeBodiesBeforeReplacingTargets(): void
    {
        $this->write('artifact', 'original');
        $responses = [
            new MockResponse('abcde', ['response_headers' => ['content-length: 5']]),
            new MockResponse((static function (): iterable {
                yield 'ab';
                yield 'cde';
            })()),
            new MockResponse((static function (): iterable {
                yield 'ab';
                yield 'cd';
            })()),
        ];
        $http = new MockHttpClient($responses);
        $downloader = $this->downloader($http, ['download-max-bytes' => 4]);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertFalse($downloader->save('https://example.test/file', $this->root . '/artifact'));
            self::assertStringContainsString('byte limit', $downloader->error());
            self::assertSame('original', file_get_contents($this->root . '/artifact'));
        }
        self::assertTrue($downloader->save('https://example.test/file', $this->root . '/artifact'));
        self::assertSame('abcd', file_get_contents($this->root . '/artifact'));
        $larger = $this->downloader(new MockHttpClient(new MockResponse('abcde')), ['download-max-bytes' => 5]);
        self::assertSame('abcde', $larger->fetch('https://example.test/file'));
    }

    public function testUppercaseChecksumsAreAccepted(): void
    {
        $url = 'https://example.test/file';
        $downloader = $this->downloader(new MockHttpClient(new MockResponse('verified')), ['download-checksums' => [$url => strtoupper(hash('sha256', 'verified'))]]);
        self::assertSame('verified', $downloader->fetch($url));
    }

    /** @param array<string, mixed> $options */
    private function downloader(MockHttpClient $http, array $options = []): UrlDownloader
    {
        return new UrlDownloader($http, new Filesystem(), new Config($options, new Validator(new Paths($this->root))));
    }

    #[Group('PAR-SVC-008')]
    #[Group('PAR-NATIVE-004')]
    public function testHttpsPolicyIsAppliedBeforeRequestsAndAfterRedirects(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'redirect_url' => 'http://example.test/private?token=synthetic-secret']));
        $downloader = $this->downloader($http);
        self::assertSame('', $downloader->fetch('file:///etc/passwd'));
        self::assertSame('', $downloader->fetch('http://example.test'));
        self::assertSame(0, $http->getRequestsCount());
        self::assertSame('', $downloader->fetch('https://example.test'));
        self::assertSame(1, $http->getRequestsCount());
        self::assertStringContainsString('HTTPS', $downloader->error());
        self::assertStringNotContainsString('synthetic-secret', $downloader->error());
    }

    #[Group('PAR-SVC-008')]
    #[Group('PAR-NATIVE-005')]
    #[Group('PAR-NATIVE-006')]
    public function testChecksumsGuardAtomicReplacementAndErrorStateResets(): void
    {
        $url = 'https://example.test/artifact';
        $this->write('artifact', 'original');
        $http = new MockHttpClient([new MockResponse('corrupt'), new MockResponse('verified')]);
        $downloader = $this->downloader($http, ['download-checksums' => [$url => hash('sha256', 'verified')]]);
        self::assertFalse($downloader->save($url, $this->root . '/artifact'));
        self::assertSame('original', file_get_contents($this->root . '/artifact'));
        self::assertStringContainsString('SHA256', $downloader->error());
        self::assertTrue($downloader->save($url, $this->root . '/artifact'));
        self::assertSame('verified', file_get_contents($this->root . '/artifact'));
        self::assertSame('', $downloader->error());
        $requiredHttp = new MockHttpClient();
        $required = $this->downloader($requiredHttp, ['require-download-checksums' => true]);
        self::assertFalse($required->save($url, $this->root . '/artifact'));
        self::assertSame(0, $requiredHttp->getRequestsCount());
        self::assertSame('verified', file_get_contents($this->root . '/artifact'));
    }

    #[Group('PAR-SVC-008')]
    public function testStatusAndTransportErrorsDoNotExposeUrlsOrResponseBodies(): void
    {
        $http = new MockHttpClient([
            new MockResponse('synthetic-secret', ['http_code' => 403]),
            new MockResponse([new TransportException('synthetic-secret in exception')]),
        ]);
        $downloader = $this->downloader($http);
        self::assertSame('', $downloader->fetch('https://example.test?token=synthetic-secret'));
        self::assertStringContainsString('403', $downloader->error());
        self::assertStringNotContainsString('synthetic-secret', $downloader->error());
        self::assertSame('', $downloader->fetch('https://example.test'));
        self::assertSame('Download transport or configuration failed.', $downloader->error());
    }

    #[Group('PAR-SVC-008')]
    #[Group('PAR-NATIVE-004')]
    public function testInsecureOptInDoesNotDisableTlsVerificationAndEmptyFileCanBeSaved(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            self::assertSame(0, $options['max_redirects']);
            self::assertFalse($options['buffer']);

            return new MockResponse('');
        });
        $downloader = $this->downloader($http, ['allow-insecure-downloads' => true]);
        self::assertTrue($downloader->save('http://example.test', $this->root . '/empty'));
        self::assertSame('', file_get_contents($this->root . '/empty'));
        self::assertSame('', $downloader->error());
        self::assertSame('', $downloader->fetch('http://example.test'));
        self::assertSame('Download response is empty.', $downloader->error());
    }

    public function testRedirectLimitAndSymlinkTargetsPreserveExistingFiles(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://example.test/loop']));
        $downloader = $this->downloader($http);
        self::assertSame('', $downloader->fetch('https://example.test/loop'));
        self::assertSame(6, $http->getRequestsCount());
        self::assertStringContainsString('redirect limit', $downloader->error());
        $this->write('original', 'preserve');
        self::assertTrue(symlink($this->root . '/original', $this->root . '/link'));
        $downloader = $this->downloader(new MockHttpClient(new MockResponse('replacement')));
        self::assertFalse($downloader->save('https://example.test', $this->root . '/link'));
        self::assertSame('preserve', file_get_contents($this->root . '/original'));
    }
}
