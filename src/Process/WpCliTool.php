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
    private const string RELEASE_BASE = 'https://github.com/wp-cli/wp-cli/releases/download/';
    // Reviewed default release; bump both values together after verifying the official PHAR.
    private const string DEFAULT_VERSION = '2.12.0';
    private const string DEFAULT_SHA256 = 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c';

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
        $version = $this->config['wp-cli-version']->unwrap();
        if (!is_string($version)) {
            $version = self::DEFAULT_VERSION;
        } elseif (version_compare($version, $this->minVersion(), '<')) {
            $this->io->error('Configured WP-CLI release is below the minimum supported version.');

            return '';
        }

        return self::RELEASE_BASE . 'v' . $version . '/wp-cli-' . $version . '.phar';
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
        // The bundled digest pins the default release, also when configured explicitly; another version is the project's own choice.
        $default = in_array($this->config['wp-cli-version']->unwrap(), [null, self::DEFAULT_VERSION], true) ? self::DEFAULT_SHA256 : null;
        if (!$this->checkConfiguredDigest($pharPath, $io, $default)) {
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
        // Unpinned project-owned PHARs stay usable for compatibility, but no longer silently.
        if ($this->config['wp-cli-sha256']->unwrap() === null && $this->downloads->artifactUnpinned('wp-cli.phar') && hash_file('sha256', $pharPath) !== self::DEFAULT_SHA256) {
            $io->error('Warning: ' . basename($pharPath) . ' is not pinned by wp-cli-sha256, sympress-runtime.lock or the bundled WP-CLI release; review it and adopt it with --update-lock.');
        }

        return true;
    }

    private function checkConfiguredDigest(string $pharPath, Io $io, ?string $default = null): bool
    {
        $expected = $this->config['wp-cli-sha256']->unwrap() ?? $default;
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
