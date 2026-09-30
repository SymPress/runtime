<?php

declare(strict_types=1);

namespace SymPress\Runtime\Filesystem;

use RuntimeException;

/** @internal */
final class FileContentBuilder
{
    /** @param array<string, mixed> $vars */
    public function build(Paths $paths, string $template, array $vars = []): string
    {
        $path = $paths->template($template);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Template is missing or unreadable: ' . $template);
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new RuntimeException('Template is empty: ' . $template);
        }

        return $this->render($content, $vars);
    }

    /** @param array<string, mixed> $vars */
    public function render(string $content, array $vars): string
    {
        foreach ($vars as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $content = preg_replace_callback('/\{\{\{\s*' . preg_quote($name, '/') . '\s*\}\}\}/i', static fn (): string => (string) $value, $content) ?? $content;
        }

        return $content;
    }
}
