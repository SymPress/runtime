<?php

declare(strict_types=1);

namespace SymPress\Runtime\Download;

use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Process\PhpTool;
use SymPress\Runtime\Process\WpCliTool;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

final readonly class PharInstaller
{
    public function __construct(private Io $io, private UrlDownloader $downloads, private Filesystem $files = new Filesystem())
    {
    }

    public function install(PhpTool $tool, string $path): string
    {
        $temporary = null;
        try {
            $url = $tool->pharUrl();
            if ($url === '' || $tool->niceName() === '' || $path === '' || is_dir($path) || is_link($path)) {
                $this->io->error('PHP tool installation is disabled or its target is invalid.');

                return '';
            }
            $this->files->mkdir(dirname($path));
            $temporary = $this->files->tempnam(dirname($path), '.sympress-download-', '.phar');
            if (!$this->downloads->save($url, $temporary, fn (string $candidate): bool => $tool->checkPhar($candidate, $this->io), $tool instanceof WpCliTool ? 'wp-cli.phar' : null)) {
                $this->io->error($this->downloads->error());

                return '';
            }
            $this->files->chmod($temporary, 0550);
            $this->files->rename($temporary, $path, true);
            $this->io->success('PHP tool installed successfully.');

            return $path;
        } catch (Throwable) {
            $this->io->error('PHP tool installation failed.');

            return '';
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                $this->files->remove($temporary);
            }
        }
    }
}
