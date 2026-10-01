<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

use RuntimeException;

/** @internal */
final class SectionMerger
{
    private const string MARKER = '// @sympress-section-hashes: ';

    /** @return array<string, string> */
    public function sections(string $content): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)\s*:\s*\{(.*?)\}\s*#@@\/\1\b/ms', $content, $matches, PREG_SET_ORDER);
        $sections = [];
        foreach ($matches as $match) {
            if (isset($sections[$match[1]])) {
                throw new RuntimeException('Duplicate configuration section: ' . $match[1]);
            }
            $sections[$match[1]] = $match[2];
        }

        return $sections;
    }

    /** @return array{content: string, preserved: list<string>} */
    public function merge(string $generated, ?string $existing): array
    {
        $fresh = $this->sections($generated);
        $hashes = array_map(static fn (string $body): string => hash('sha256', $body), $fresh);
        $preserved = [];
        if ($existing !== null && preg_match('/^' . preg_quote(self::MARKER, '/') . '(.*)$/m', $existing, $match)) {
            $previous = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($previous)) {
                throw new RuntimeException('Configuration section metadata is invalid.');
            }
            foreach ($this->sections($existing) as $name => $body) {
                if ($name === 'KEYS' || !isset($fresh[$name], $previous[$name]) || $previous[$name] === hash('sha256', $body)) {
                    continue;
                }
                $generated = $this->replace($generated, $name, $body);
                $preserved[] = $name;
            }
        }
        $metadata = self::MARKER . json_encode($hashes, JSON_THROW_ON_ERROR) . "\n";
        $generated = preg_replace('/^' . preg_quote(self::MARKER, '/') . '.*\n/m', '', $generated) ?? $generated;
        $generated = preg_replace_callback('/^<\?php\s*\n/', static fn (): string => "<?php\n" . $metadata, $generated, 1, $count) ?? $generated;
        if ($count !== 1) {
            throw new RuntimeException('Configuration template must begin with a PHP opening tag.');
        }

        return ['content' => $generated, 'preserved' => $preserved];
    }

    public function replace(string $content, string $section, string $body): string
    {
        $name = preg_quote($section, '/');

        return preg_replace_callback('/^(' . $name . '\s*:\s*\{)(.*?)(\}\s*#@@\/' . $name . '\b)/ms', static fn (array $matches): string => $matches[1] . $body . $matches[3], $content) ?? $content;
    }
}
