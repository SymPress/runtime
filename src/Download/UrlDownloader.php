<?php

declare(strict_types=1);

namespace SymPress\Runtime\Download;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class UrlDownloader
{
    private string $lastError = '';

    public function __construct(private readonly HttpClientInterface $http, private readonly Filesystem $files, private readonly Config $config)
    {
    }

    public function fetch(string $url): string
    {
        $this->lastError = '';
        try {
            $body = $this->download($url);
            if (!$body) {
                throw new DownloadException('Download response is empty.');
            }

            return $body;
        } catch (Throwable $error) {
            $this->recordError($error);

            return '';
        }
    }

    public function save(string $url, string $filename): bool
    {
        $this->lastError = '';
        try {
            $body = $this->download($url);
            if (!$this->files->save($body, $filename)) {
                throw new DownloadException('Cannot save downloaded file.');
            }

            return true;
        } catch (Throwable $error) {
            $this->recordError($error);

            return false;
        }
    }

    public function error(): string
    {
        return $this->lastError;
    }

    private function download(string $url): string
    {
        $checksums = $this->config['download-checksums']->unwrap();
        $checksum = is_array($checksums) ? ($checksums[$url] ?? null) : null;
        if ($this->config['require-download-checksums']->is(true) && !is_string($checksum)) {
            throw new DownloadException('A SHA256 checksum is required for this download.');
        }
        for ($redirects = 0; $redirects <= 5; ++$redirects) {
            $this->validateUrl($url);
            $response = $this->http->request('GET', $url, ['max_redirects' => 0, 'timeout' => 15, 'max_duration' => 60, 'verify_peer' => true, 'verify_host' => true, 'buffer' => false]);
            $status = $response->getStatusCode();
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $next = $response->getInfo('redirect_url');
                $response->cancel();
                if (!is_string($next) || $next === '') {
                    throw new DownloadException('Download redirect has no valid destination.');
                }
                $url = $next;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $response->cancel();
                throw new DownloadException('Download failed with HTTP status ' . $status . '.');
            }
            $body = $this->boundedContent($response);
            if (is_string($checksum) && !hash_equals($checksum, hash('sha256', $body))) {
                throw new DownloadException('Downloaded content does not match its SHA256 checksum.');
            }

            return $body;
        }

        throw new DownloadException('Download exceeded the redirect limit.');
    }

    private function boundedContent(ResponseInterface $response): string
    {
        try {
            $limit = $this->config['download-max-bytes']->unwrap();
            if (!is_int($limit) || $limit < 1) {
                throw new DownloadException('Download byte limit must be a positive integer.');
            }
            $length = $response->getHeaders(false)['content-length'][0] ?? null;
            if (is_string($length) && ctype_digit($length) && (float) $length > $limit) {
                throw new DownloadException('Download exceeds the configured byte limit.');
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $content = $chunk->getContent();
                if (strlen($content) > $limit - strlen($body)) {
                    throw new DownloadException('Download exceeds the configured byte limit.');
                }
                $body .= $content;
            }

            return $body;
        } finally {
            $response->cancel();
        }
    }

    private function validateUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new DownloadException('Download requires a valid HTTP(S) URL.');
        }
        if ($scheme !== 'https' && !$this->config['allow-insecure-downloads']->is(true)) {
            throw new DownloadException('Download requires HTTPS; insecure downloads need explicit opt-in.');
        }
    }

    private function recordError(Throwable $error): void
    {
        $this->lastError = $error instanceof DownloadException ? $error->getMessage() : 'Download transport or configuration failed.';
    }
}
