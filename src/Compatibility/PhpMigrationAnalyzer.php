<?php

declare(strict_types=1);

namespace SymPress\Runtime\Compatibility;

use PhpToken;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Static inspection only: never require project files or resolve class names. */
final class PhpMigrationAnalyzer
{
    /**
     * @param list<string> $paths
     * @param list<string> $excluded
     * @return list<array{file: string, line: int, symbol: string, replacement: string, guidance: string}>
     */
    public function analyze(array $paths, array $excluded = []): array
    {
        $files = [];
        foreach (array_unique($paths) as $path) {
            if (is_file($path)) {
                $files[$path] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $excludedPaths = array_map(static fn (string $relative): string => $path . '/' . $relative, $excluded);
            $filter = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), static fn (SplFileInfo $file): bool => !$file->isDir() || (!$file->isLink() && $file->isReadable() && !in_array($file->getFilename(), ['vendor', 'node_modules', '.git', '.ddev', 'var', 'build'], true) && !in_array($file->getPathname(), $excludedPaths, true)));
            $finder = new RecursiveIteratorIterator($filter);
            foreach ($finder as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $files[$file->getPathname()] = $file->getPathname();
            }
        }
        ksort($files);
        $findings = [];
        foreach ($files as $file) {
            array_push($findings, ...$this->file($file));
        }

        return $findings;
    }

    /** @return list<array{file: string, line: int, symbol: string, replacement: string, guidance: string}> */
    private function file(string $file): array
    {
        $findings = [];
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Cannot inspect PHP migration source: ' . $file);
        }
        $namespace = '';
        $namespaceDeclaration = false;
        foreach (PhpToken::tokenize($source) as $token) {
            if (in_array($token->id, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_WHITESPACE], true)) {
                continue;
            }
            // Literal class strings and grouped namespace imports are included; expressions are never evaluated.
            $text = str_replace('\\\\', '\\', $token->text);
            if ($token->id === T_NAMESPACE) {
                $namespaceDeclaration = true;
            }
            if ($namespaceDeclaration && in_array($token->id, [T_NAME_QUALIFIED, T_STRING], true)) {
                $namespace = $text;
                $namespaceDeclaration = false;
            }
            if ($token->id === T_NAME_QUALIFIED && str_starts_with($namespace, 'WeCodeMore\\WpStarter') && !str_starts_with($text, 'WeCodeMore\\WpStarter')) {
                $text = $namespace . '\\' . $text;
            }
            preg_match_all('/(?:WeCodeMore\\\\WpStarter(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*|Composer\\\\[A-Za-z_][A-Za-z0-9_\\\\]*|wpstarter_getenv)/', $text, $matches);
            foreach ($matches[0] as $symbol) {
                $suffix = substr($symbol, strlen('WeCodeMore\\WpStarter\\'));
                $replacement = LegacyApi::ALIASES[$suffix] ?? '';
                $guidance = 'Replace the legacy import or class string; verify methods against the native service API.';
                if ($replacement === '' || $suffix === 'Step\\Steps') {
                    $guidance = 'Manual rewrite required: use StepInterface and injected Services; Composer-dependent callbacks must accept RunContext.';
                }
                if ($symbol === 'wpstarter_getenv') {
                    $replacement = 'sympress_runtime_getenv';
                    $guidance = 'Use the native runtime getter.';
                }
                if (str_starts_with($symbol, 'Composer\\')) {
                    $replacement = 'SymPress\\Runtime\\Application\\RunContext';
                    $guidance = 'Review setup constructor/callback types; replace Composer access with RunContext or the corresponding Services method.';
                }
                $key = $file . ':' . $token->line . ':' . $symbol;
                $findings[$key] = ['file' => $file, 'line' => $token->line, 'symbol' => $symbol, 'replacement' => $replacement, 'guidance' => $guidance];
            }
        }

        return array_values($findings);
    }
}
