<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

use Composer\Semver\Semver;
use DirectoryIterator;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Paths;
use UnexpectedValueException;

final class WpCliTool implements PhpTool
{
    private const string API_URL = 'https://api.github.com/repos/wp-cli/wp-cli/releases/latest';
    private const string RELEASE_BASE = 'https://github.com/wp-cli/wp-cli/releases/download/';
    private ?string $releaseUrl = null;

    public function __construct(private readonly Config $config, private readonly UrlDownloader $downloads, private readonly Io $io)
    {
    }

    public function niceName(): string
    {
        return 'WP CLI';
    }

    public function packageName(): string
    {
        return 'wp-cli/wp-cli';
    }

    public function minVersion(): string
    {
        return '2.5.0';
    }

    public function pharUrl(): string
    {
        if (!$this->config['install-wp-cli']->is(true)) {
            return '';
        }
        if ($this->releaseUrl !== null) {
            return $this->releaseUrl;
        }
        $this->releaseUrl = self::RELEASE_BASE . 'v' . $this->minVersion() . '/wp-cli-' . $this->minVersion() . '.phar';
        $data = json_decode($this->downloads->fetch(self::API_URL), true);
        $assets = is_array($data) ? ($data['assets'] ?? null) : null;
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                $url = is_array($asset) ? ($asset['browser_download_url'] ?? null) : null;
                if (!is_string($url) || !preg_match('~^' . preg_quote(self::RELEASE_BASE, '~') . 'v([0-9]+\.[0-9]+\.[0-9]+)/wp-cli-\1\.phar$~D', $url, $matches)) {
                    continue;
                }
                if (version_compare($matches[1], $this->minVersion(), '<')) {
                    continue;
                }
                $this->releaseUrl = $url;

                return $url;
            }
        }
        $this->io->comment('Latest WP-CLI release lookup failed; using the pinned minimum version.');

        return $this->releaseUrl;
    }

    public function pharTarget(Paths $paths): string
    {
        $default = $paths->root('wp-cli.phar');
        if (is_file($default)) {
            return $default;
        }
        $candidates = [];
        foreach (new DirectoryIterator($paths->root()) as $entry) {
            if (!$entry->isFile() || !preg_match('/^wp-cli-(.+)\.phar$/D', $entry->getFilename(), $matches)) {
                continue;
            }
            try {
                $supported = Semver::satisfies($matches[1], '>=' . $this->minVersion());
            } catch (UnexpectedValueException) {
                $supported = false;
            }
            if (!$supported) {
                continue;
            }
            $candidates[$matches[1]] = $entry->getPathname();
        }
        return $candidates === [] ? $default : $candidates[Semver::rsort(array_keys($candidates))[0]];
    }

    public function filesystemBootstrap(string $packageVendorPath): string
    {
        return rtrim($packageVendorPath, '/\\') . '/php/boot-fs.php';
    }

    public function checkPhar(string $pharPath, Io $io): bool
    {
        $url = $this->pharUrl();
        if ($url === '' || !is_file($pharPath) || !is_readable($pharPath)) {
            return false;
        }
        $expected = trim($this->downloads->fetch($url . '.sha512'));
        if (!preg_match('/^[a-fA-F0-9]{128}$/D', $expected)) {
            $io->error('WP-CLI SHA512 checksum is unavailable or invalid.');

            return false;
        }
        $actual = hash_file('sha512', $pharPath);
        if (!is_string($actual) || !hash_equals(strtolower($expected), $actual)) {
            $io->error('WP-CLI SHA512 integrity check failed.');

            return false;
        }

        return true;
    }

    public function prepareCommand(string $command, Paths $paths, Io $io): string
    {
        return $command . ' --path=' . escapeshellarg($paths->wp());
    }
}
