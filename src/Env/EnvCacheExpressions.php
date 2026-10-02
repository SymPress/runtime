<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

/** Keeps original expressions, never their process-derived results. @internal */
final class EnvCacheExpressions
{
    /** @var array<string, list<array{name: string, source: string, references: list<string>, assigns: list<string>, command: bool}>> */
    private array $sources = [];

    public function record(string $content, string $path): void
    {
        $definitions = [];
        foreach ($this->statements($content) as $statement) {
            if (!preg_match('/^\s*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)(?:=|[ \t]*(?:#[^\n]*)?$)/', $statement, $match)) {
                continue;
            }
            [$references, $command] = $this->references($statement);
            preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):=/', $statement, $assigned);
            $definitions[] = ['name' => $match[1], 'source' => $statement, 'references' => $references, 'assigns' => $assigned[1], 'command' => $command];
        }
        $this->sources[$path] = $definitions;
    }

    public function forget(string $name): void
    {
        foreach ($this->sources as $path => $definitions) {
            $this->sources[$path] = array_values(array_filter($definitions, static fn (array $definition): bool => $definition['name'] !== $name));
        }
    }

    /** @return list<string> */
    public function referencesFor(string $path): array
    {
        return array_values(array_unique(array_merge(...array_column($this->sources[$path] ?? [], 'references'))));
    }

    /**
     * @param array<string, true> $external
     * @return array{expressions: array<string, string>, dynamic: array<string, true>, transient: array<string, true>, process: array<string, true>}
     */
    public function payload(array $external = []): array
    {
        $definitions = [];
        $transient = [];
        $process = [];
        $ordered = array_merge(...array_values($this->sources));
        foreach ($ordered as $definition) {
            $definitions[$definition['name']] = $definition;
            $references = array_flip($definition['references']);
            $command = $definition['command'] || array_intersect_key($transient, $references) !== [];
            $derived = array_intersect_key($external + $process, $references) !== [];
            foreach ([$definition['name'], ...$definition['assigns']] as $name) {
                unset($transient[$name], $process[$name]);
                if ($command) {
                    $transient[$name] = true;
                }
                if (!$derived) {
                    continue;
                }
                $process[$name] = true;
            }
        }
        $dynamic = [];
        foreach ($definitions as $name => $definition) {
            if ($definition['references'] === [] && !$definition['command']) {
                continue;
            }
            $dynamic[$name] = true;
            foreach ($definition['assigns'] as $assigned) {
                $dynamic[$assigned] = true;
            }
        }
        // Preserve relevant file defaults in source order, including overridden
        // definitions. Unrelated static assignments never reach the parser on hits.
        $needed = $dynamic;
        do {
            $previous = $needed;
            foreach ($ordered as $definition) {
                if (!isset($needed[$definition['name']])) {
                    continue;
                }
                foreach ($definition['references'] as $reference) {
                    $needed[$reference] = true;
                }
            }
        } while ($previous !== $needed);
        $expressions = [];
        foreach ($this->sources as $path => $source) {
            $selected = array_filter($source, static fn (array $definition): bool => isset($needed[$definition['name']]));
            if ($selected === []) {
                continue;
            }
            $expressions[$path] = implode("\n", array_column($selected, 'source')) . "\n";
        }

        return ['expressions' => $expressions, 'dynamic' => $dynamic, 'transient' => $transient, 'process' => $process];
    }

    /** @return list<string> */
    private function statements(string $content): array
    {
        $statements = [];
        $start = 0;
        $quote = null;
        $escaped = false;
        $comment = false;
        $length = strlen($content);
        for ($index = 0; $index < $length; ++$index) {
            $char = $content[$index];
            if ($quote === null && $char === "\n") {
                $statements[] = substr($content, $start, $index - $start);
                $start = $index + 1;
                $comment = false;
                $escaped = false;
                continue;
            }
            if ($comment) {
                continue;
            }
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && $quote !== "'") {
                $escaped = true;
                continue;
            }
            if ($quote === null && $char === '#' && ($index === $start || ctype_space($content[$index - 1]))) {
                $comment = true;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
                continue;
            }
            if ($quote !== null || ($char !== "'" && $char !== '"')) {
                continue;
            }
            $quote = $char;
        }
        $statements[] = substr($content, $start);

        return $statements;
    }

    /** @return array{list<string>, bool} */
    private function references(string $statement): array
    {
        $references = [];
        $command = false;
        $quote = null;
        $length = strlen($statement);
        for ($index = 0; $index < $length; ++$index) {
            $char = $statement[$index];
            if ($char === '\\' && $quote !== "'") {
                ++$index;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
                continue;
            }
            if ($quote === null && ($char === "'" || $char === '"')) {
                $quote = $char;
                continue;
            }
            if ($quote === "'") {
                continue;
            }
            if ($quote === null && $char === '#' && ($index === 0 || ctype_space($statement[$index - 1]))) {
                break;
            }
            if ($char !== '$') {
                continue;
            }
            $tail = substr($statement, $index + 1);
            $command = $command || str_starts_with($tail, '(');
            if (!preg_match('/^\{?([A-Za-z_][A-Za-z0-9_]*)/', $tail, $match)) {
                continue;
            }
            $references[] = $match[1];
        }

        return [array_values(array_unique($references)), $command];
    }
}
