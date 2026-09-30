<?php

declare(strict_types=1);

namespace SymPress\Runtime\Process;

use Composer\Semver\Semver;
use DirectoryIterator;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Paths;
use Symfony\Component\Console\Input\StringInput;
use UnexpectedValueException;

/** @internal */
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
        $configured = $this->config['wp-cli-version']->unwrap();
        if (is_string($configured)) {
            if (version_compare($configured, $this->minVersion(), '<')) {
                $this->io->error('Configured WP-CLI release is below the minimum supported version.');

                return '';
            }
            $this->releaseUrl = self::RELEASE_BASE . 'v' . $configured . '/wp-cli-' . $configured . '.phar';

            return $this->releaseUrl;
        }
        // Release discovery is moving metadata; the selected artifact and sidecar are locked.
        $data = json_decode($this->downloads->fetch(self::API_URL, false), true);
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
        if ($this->config['compatibility-profile']->is('native')) {
            $this->io->error('Latest WP-CLI release lookup failed; configure wp-cli-version for an explicit release.');
            $this->releaseUrl = '';

            return '';
        }
        $this->io->comment('Latest WP-CLI release lookup failed; using the pinned minimum version.');
        $this->releaseUrl = self::RELEASE_BASE . 'v' . $this->minVersion() . '/wp-cli-' . $this->minVersion() . '.phar';

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
        if (!$this->checkConfiguredDigest($pharPath, $io)) {
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

    /** An explicitly configured digest also applies to an existing local PHAR. */
    public function checkLocalPhar(string $pharPath, Io $io): bool
    {
        if (!$this->checkConfiguredDigest($pharPath, $io)) {
            return false;
        }
        if (!$this->downloads->verifyArtifact('wp-cli.phar', $pharPath)) {
            $io->error($this->downloads->error());

            return false;
        }

        return true;
    }

    private function checkConfiguredDigest(string $pharPath, Io $io): bool
    {
        $expected = $this->config['wp-cli-sha256']->unwrap();
        if (!is_string($expected)) {
            return true;
        }
        $actual = hash_file('sha256', $pharPath);
        if (!is_string($actual) || !hash_equals(strtolower($expected), $actual)) {
            $io->error('WP-CLI SHA256 integrity check failed.');

            return false;
        }

        return true;
    }

    public function prepareCommand(string $command, Paths $paths, Io $io): string
    {
        return $this->prepareArguments((new StringInput($command))->getRawTokens(), $paths);
    }

    /** @param list<string> $tokens */
    public function prepareArguments(array $tokens, Paths $paths): string
    {
        $arguments = [];
        $terminated = false;
        $skip = false;
        foreach ($tokens as $token) {
            if ($skip) {
                $skip = false;
                continue;
            }
            if (!$terminated && $token === '--path') {
                $skip = true;
                continue;
            }
            if (!$terminated && str_starts_with($token, '--path=')) {
                continue;
            }
            if (!$terminated && $token === '--') {
                $arguments[] = '--path=' . $paths->wp();
                $terminated = true;
            }
            $arguments[] = $token;
        }
        if (!$terminated) {
            $arguments[] = '--path=' . $paths->wp();
        }

        return implode(' ', array_map(escapeshellarg(...), $arguments));
    }
}
