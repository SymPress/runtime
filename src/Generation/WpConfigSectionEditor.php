<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;

final readonly class WpConfigSectionEditor
{
    public function __construct(private Paths $paths, private Config $config, private Filesystem $filesystem)
    {
    }

    public function sectionContent(string $section): string
    {
        if (!preg_match($this->pattern($section), $this->read(), $matches)) {
            return '';
        }

        return implode("\n", array_map(trim(...), explode("\n", trim($matches[2]))));
    }

    public function append(string $section, string $newContent): void
    {
        $this->edit($section, $newContent, 'A');
    }

    public function prepend(string $section, string $newContent): void
    {
        $this->edit($section, $newContent, 'P');
    }

    public function replace(string $section, string $newContent): void
    {
        $this->edit($section, $newContent, 'R');
    }

    public function delete(string $section): void
    {
        $this->replace($section, '');
    }

    private function edit(string $section, string $newContent, string $mode): void
    {
        $content = $this->read();
        $newContent = trim(implode("\n    ", array_map(rtrim(...), explode("\n", $newContent))));
        $body = $newContent === '' ? '' : '    ' . $newContent;
        if ($mode !== 'R' && $body === '') {
            return;
        }
        $editing = "\n" . $body . "\n";
        if ($mode !== 'R') {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
            $id = $mode . '-' . md5((preg_replace('/\s+/', '', $body) ?? $body) . '#' . ($caller['file'] ?? '-') . '#' . ($caller['line'] ?? -1));
            $editing = '# <' . $id . ">\n" . $body . "\n# </" . $id . '>';
            if (str_contains($content, $editing)) {
                return;
            }
        }
        $updated = preg_replace_callback($this->pattern($section), static function (array $matches) use ($editing, $mode): string {
            $body = match ($mode) {
                'A' => $matches[2] . $editing . "\n",
                'P' => "\n" . $editing . $matches[2],
                default => $editing,
            };

            return $matches[1] . $body . $matches[3];
        }, $content);
        if ($updated === null) {
            throw new RuntimeException('Cannot edit the requested configuration section.');
        }
        if ($updated !== $content && !$this->filesystem->save($updated, $this->path())) {
            throw new RuntimeException('Cannot save the configuration section.');
        }
    }

    private function pattern(string $section): string
    {
        $name = preg_quote(strtoupper(trim($section)), '~');

        return '~(' . $name . '\s*:\s*\{)(.*?)(\}\s*#@@/' . $name . ')~s';
    }

    private function path(): string
    {
        $configured = $this->config['wp-config-php-path']->unwrapOrFallback();
        if (is_string($configured)) {
            return $configured;
        }

        return $this->config['compatibility-profile']->is('release-3.0.1') ? $this->paths->wpParent('wp-config.php') : $this->paths->root('wp-config.php');
    }

    private function read(): string
    {
        $path = $this->path();
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Configuration file is missing or unreadable.');
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new RuntimeException('Configuration file is empty or unreadable.');
        }

        return $content;
    }
}
