<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Download\DownloadLock;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DownloadLockTest extends TemporaryProject
{
    public function testVersionOnlyLockFrom02MigratesOnWriteWithoutChangingExistingPins(): void
    {
        $url = 'https://example.test/old';
        $pins = [hash('sha256', $url) => hash('sha256', 'old')];
        $legacy = json_encode(['version' => 1, 'downloads' => $pins], JSON_THROW_ON_ERROR);
        $this->write('sympress-runtime.lock', $legacy);
        self::assertSame($pins, $this->entries());
        self::assertSame($legacy, file_get_contents($this->root . '/sympress-runtime.lock'));
        $downloader = $this->downloader(new MockHttpClient(new MockResponse('new')));
        self::assertSame('new', $downloader->fetch('https://example.test/new'));
        $migrated = json_decode((string) file_get_contents($this->root . '/sympress-runtime.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $migrated['format']);
        self::assertSame(1, $migrated['version'], '0.2 readers can still read the additive marker.');
        self::assertSame($pins[hash('sha256', $url)], $this->entries()[hash('sha256', $url)]);
        self::assertCount(2, $this->entries());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsupportedFormats(): iterable
    {
        yield 'future format with legacy alias' => [['format' => 2, 'version' => 1]];
        yield 'future legacy version' => [['version' => 2]];
        yield 'conflicting alias' => [['format' => 1, 'version' => 2]];
        yield 'null is not absent' => [['format' => null, 'version' => 1]];
        yield 'string is not integer' => [['format' => '1', 'version' => 1]];
        yield 'older unsupported format' => [['format' => 0]];
        yield 'missing discriminator' => [[]];
    }

    /** @param array<string, mixed> $markers */
    #[DataProvider('unsupportedFormats')]
    public function testUnknownFormatCannotBeReplacedEvenWithExplicitUpdate(array $markers): void
    {
        $contents = json_encode($markers + ['downloads' => []], JSON_THROW_ON_ERROR);
        $this->write('sympress-runtime.lock', $contents);
        $downloader = $this->downloader(new MockHttpClient(new MockResponse('new')), ['update-lock' => true]);
        self::assertFalse($downloader->save('https://example.test/new', $this->root . '/artifact'));
        self::assertStringContainsString('unsupported format', $downloader->error());
        self::assertSame($contents, file_get_contents($this->root . '/sympress-runtime.lock'));
        self::assertFileDoesNotExist($this->root . '/artifact');
    }

    /** @param array<string, mixed> $options */
    private function downloader(MockHttpClient $http, array $options = [], string $profile = 'native'): UrlDownloader
    {
        $paths = new Paths($this->root);
        $config = new Config($options, new Validator($paths), $profile);

        return new UrlDownloader($http, new Filesystem(), $config, new DownloadLock($paths, new ProjectBoundary($paths)));
    }

    /** @return array<string, string> */
    private function entries(): array
    {
        $paths = new Paths($this->root);

        return (new DownloadLock($paths, new ProjectBoundary($paths)))->entries();
    }

    public function testFirstDownloadPinsBytesAndRejectsChangedBytesBeforePublication(): void
    {
        $url = 'https://user:secret@example.test/artifact?token=private-token';
        $downloader = $this->downloader(new MockHttpClient([new MockResponse('first'), new MockResponse('changed'), new MockResponse('first')]));
        self::assertTrue($downloader->save($url, $this->root . '/artifact'));
        self::assertSame([hash('sha256', $url) => hash('sha256', 'first')], $this->entries());
        self::assertSame(0600, fileperms($this->root . '/sympress-runtime.lock') & 0777);
        self::assertStringNotContainsString('secret', (string) file_get_contents($this->root . '/sympress-runtime.lock'));
        self::assertStringNotContainsString('private-token', (string) file_get_contents($this->root . '/sympress-runtime.lock'));
        self::assertFalse($downloader->save($url, $this->root . '/artifact'));
        self::assertStringContainsString('--update-lock', $downloader->error());
        self::assertStringNotContainsString('secret', $downloader->error());
        self::assertSame('first', file_get_contents($this->root . '/artifact'));
        self::assertSame('first', $downloader->fetch($url));
    }

    public function testUpdateOnlyRefreshesRequestedUrlAndExplicitChecksumsStillWin(): void
    {
        $firstUrl = 'https://example.test/a';
        $secondUrl = 'https://example.test/b';
        $initial = $this->downloader(new MockHttpClient([new MockResponse('a'), new MockResponse('b')]));
        self::assertSame('a', $initial->fetch($firstUrl));
        self::assertSame('b', $initial->fetch($secondUrl));
        $update = $this->downloader(new MockHttpClient(new MockResponse('changed')), ['update-lock' => true]);
        self::assertSame('changed', $update->fetch($firstUrl));
        self::assertSame(hash('sha256', 'b'), $this->entries()[hash('sha256', $secondUrl)]);
        self::assertSame(hash('sha256', 'changed'), $this->entries()[hash('sha256', $firstUrl)]);
        $assertion = $this->downloader(new MockHttpClient(new MockResponse('wrong')), ['update-lock' => true, 'download-checksums' => [$firstUrl => hash('sha256', 'asserted')]]);
        self::assertSame('', $assertion->fetch($firstUrl));
        self::assertStringContainsString('SHA256', $assertion->error());
        self::assertSame(hash('sha256', 'changed'), $this->entries()[hash('sha256', $firstUrl)]);
    }

    public function testFailuresNeverCreateTrustEntries(): void
    {
        $url = 'https://example.test/a';
        $downloader = $this->downloader(new MockHttpClient([
            new MockResponse('bad', ['http_code' => 503]),
            new MockResponse(''),
            new MockResponse('body'),
            new MockResponse('untrusted'),
        ]));
        self::assertSame('', $downloader->fetch($url));
        self::assertSame('', $downloader->fetch($url));
        self::assertFalse($downloader->save($url, $this->root));
        self::assertFalse($downloader->save($url, $this->root . '/artifact', static fn (string $candidate): bool => false));
        self::assertSame([], $this->entries());
        self::assertFileDoesNotExist($this->root . '/artifact');
        self::assertFileDoesNotExist($this->root . '/sympress-runtime.lock');
    }

    public function testArtifactVerificationCanDownloadItsSidecarBeforeLockCommit(): void
    {
        $url = 'https://example.test/tool.phar';
        $downloader = $this->downloader(new MockHttpClient([new MockResponse('phar'), new MockResponse('sidecar')]));
        self::assertTrue($downloader->save($url, $this->root . '/tool.phar', static fn (string $candidate): bool => $downloader->fetch($url . '.sha512') === 'sidecar'));
        self::assertCount(2, $this->entries());
    }

    public function testRedirectsPinOriginalIdentityAndMovingMetadataIsExplicitlyUnpinned(): void
    {
        $url = 'https://example.test/original';
        $downloader = $this->downloader(new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://cdn.example.test/artifact']),
            new MockResponse('body'),
            new MockResponse('metadata'),
        ]));
        self::assertSame('body', $downloader->fetch($url));
        self::assertSame('metadata', $downloader->fetch('https://example.test/latest', false));
        self::assertSame([hash('sha256', $url) => hash('sha256', 'body')], $this->entries());
    }

    public function testMalformedAndSymlinkLocksFailClosed(): void
    {
        $this->write('sympress-runtime.lock', '{bad json');
        $downloader = $this->downloader(new MockHttpClient([new MockResponse('a'), new MockResponse('b')]));
        self::assertSame('', $downloader->fetch('https://example.test/a'));
        self::assertSame('{bad json', file_get_contents($this->root . '/sympress-runtime.lock'));
        unlink($this->root . '/sympress-runtime.lock');
        $this->write('keep', 'preserve');
        symlink($this->root . '/keep', $this->root . '/sympress-runtime.lock');
        self::assertSame('', $downloader->fetch('https://example.test/a'));
        self::assertSame('preserve', file_get_contents($this->root . '/keep'));
    }

    public function testLegacyProfilesDoNotCreateLocksUnlessEnabled(): void
    {
        foreach (['release-3.0.1', 'upstream-dev'] as $profile) {
            $downloader = $this->downloader(new MockHttpClient(new MockResponse('legacy')), [], $profile);
            self::assertSame('legacy', $downloader->fetch('https://example.test/' . $profile));
        }
        self::assertFileDoesNotExist($this->root . '/sympress-runtime.lock');
        $optIn = $this->downloader(new MockHttpClient(new MockResponse('legacy')), ['download-lock' => true], 'release-3.0.1');
        self::assertSame('legacy', $optIn->fetch('https://example.test/release'));
        self::assertCount(1, $this->entries());
    }
}
