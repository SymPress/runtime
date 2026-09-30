<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

final class DownloadTest extends TemporaryProject
{
    public function testRealHttpTransportResolvesRelativeRedirectAndRejectsErrorResponse(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->write('router.php', <<<'PHP'
<?php
if ($_SERVER['REQUEST_URI'] === '/start') {
    header('Location: /artifact');
} elseif ($_SERVER['REQUEST_URI'] === '/artifact') {
    echo 'verified fixture';
} else {
    http_response_code(503);
    echo 'synthetic-secret';
}
PHP);
        $server = new Process([PHP_BINARY, '-S', $address, $this->root . '/router.php'], $this->root);
        $server->start();
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $probe = @stream_socket_client('tcp://' . $address, timeout: 0.05);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            self::assertTrue($ready, $server->getErrorOutput());
            $url = 'http://' . $address . '/start';
            $config = new Config(['allow-insecure-downloads' => true, 'download-checksums' => [$url => hash('sha256', 'verified fixture')]], new Validator(new Paths($this->root)));
            $downloader = new UrlDownloader(HttpClient::create(), new Filesystem(), $config);
            self::assertTrue($downloader->save($url, $this->root . '/artifact'), $downloader->error());
            self::assertSame('verified fixture', file_get_contents($this->root . '/artifact'));
            self::assertFalse($downloader->save('http://' . $address . '/failure', $this->root . '/artifact'));
            self::assertStringContainsString('503', $downloader->error());
            self::assertStringNotContainsString('synthetic-secret', $downloader->error());
            self::assertSame('verified fixture', file_get_contents($this->root . '/artifact'));
        } finally {
            $server->stop(1);
        }
    }
}
