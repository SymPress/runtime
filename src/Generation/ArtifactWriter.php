<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

use PhpToken;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ArtifactWriter
{
    public function __construct(private ProjectBoundary $boundary)
    {
    }

    public function write(string $file, string $content): bool
    {
        PhpToken::tokenize($content, TOKEN_PARSE);
        $this->boundary->assertWritablePath($file);
        if (is_link($file) || is_dir($file)) {
            return false;
        }
        if (is_file($file) && file_get_contents($file) === $content) {
            return chmod($file, 0600);
        }
        (new Filesystem())->mkdir(dirname($file));
        $temporary = tempnam(dirname($file), '.sympress-config-');
        if ($temporary === false) {
            return false;
        }
        try {
            return chmod($temporary, 0600) && file_put_contents($temporary, $content) === strlen($content) && rename($temporary, $file);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
