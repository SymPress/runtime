<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

use PhpToken;
use SymPress\Runtime\Env\SecureFileWriter;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ArtifactWriter
{
    public function __construct(private ProjectBoundary $boundary, private int $mode = 0600)
    {
    }

    public function write(string $file, string $content): bool
    {
        PhpToken::tokenize($content, TOKEN_PARSE);
        $this->boundary->assertWritablePath($file);
        if (is_link($file) || is_dir($file)) {
            return false;
        }
        (new Filesystem())->mkdir(dirname($file));

        return SecureFileWriter::write($file, $content, $this->mode);
    }
}
