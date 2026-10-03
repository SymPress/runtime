<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use Symfony\Component\Dotenv\Dotenv;

/** Keeps original expressions, never their process-derived results. @internal */
final class EnvCacheExpressions
{
    /** @var array<string, list<array{name: string, source: string, references: list<string>, assigns: list<string>, external: list<string>, command: bool}>> */
    private array $sources = [];

    public function record(string $content, string $path): void
    {
        $definitions = [];
        foreach ($this->statements($content) as $statement) {
            if (!preg_match('/^\s*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)(?:=|[ \t]*(?:#[^\n]*)?$)/', $statement, $match)) {
                continue;
            }
            [$references, $command, $assigned] = $this->references($statement);
            $definitions[] = ['name' => $match[1], 'source' => $statement, 'references' => $references, 'assigns' => $assigned, 'external' => [], 'command' => $command];
        }
        $this->sources[$path] = $definitions;
    }

    public function forget(string $name): void
    {
        foreach ($this->sources as $path => $definitions) {
            $this->sources[$path] = array_values(array_filter($definitions, static fn (array $definition): bool => $definition['name'] !== $name));
        }
    }

    /**
     * @param array<string, true> $external
     * @param array<string, true> $filePrecedence
     */
    public function trackExternalReferences(string $path, array $external, array $filePrecedence): void
    {
        $defined = [];
        foreach ($this->sources[$path] as $index => $definition) {
            $active = array_diff_key($external, array_intersect_key($defined, $filePrecedence));
            $this->sources[$path][$index]['external'] = array_values(array_intersect($definition['references'], array_keys($active)));
            foreach ([$definition['name'], ...$definition['assigns']] as $name) {
                $defined[$name] = true;
            }
        }
    }

    /** @return list<string> */
    public function referencesFor(string $path): array
    {
        return array_values(array_unique(array_merge(...array_column($this->sources[$path] ?? [], 'references'))));
    }

    /** @return array<string, mixed>|null */
    public function templates(Dotenv $dotenv): ?array
    {
        $expressions = $this->payload()['expressions'];
        $plans = [];
        foreach ($expressions as $path => $content) {
            $compiled = EnvCacheTemplates::compile($dotenv, $this->statements($content));
            if ($compiled === null) {
                return null;
            }
            $plans[$path] = $compiled;
        }
        return $plans;
    }

    public function hasDynamicEnvironmentSelection(): bool
    {
        foreach ($this->sources as $definitions) {
            foreach ($definitions as $definition) {
                if ($definition['references'] === [] && !$definition['command']) {
                    continue;
                }
                if (array_intersect([$definition['name'], ...$definition['assigns']], ['WP_ENVIRONMENT_TYPE', 'WP_ENV', 'WORDPRESS_ENV']) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{expressions: array<string, string>, dynamic: array<string, true>, transient: array<string, true>, process: array<string, true>, needed: array<string, true>, replay: array<string, true>} */
    public function payload(): array
    {
        $definitions = [];
        $transient = [];
        $process = [];
        $ordered = array_merge(...array_values($this->sources));
        foreach ($ordered as $definition) {
            $definitions[$definition['name']] = $definition;
            $references = array_flip($definition['references']);
            $command = $definition['command'] || array_intersect_key($transient, $references) !== [];
            $derived = array_intersect_key(array_fill_keys($definition['external'], true) + $process, $references) !== [];
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
        $replay = [];
        foreach ($this->sources as $path => $source) {
            $selected = array_filter($source, static fn (array $definition): bool => isset($needed[$definition['name']]));
            if ($selected === []) {
                continue;
            }
            $expressions[$path] = implode("\n", array_column($selected, 'source')) . "\n";
            foreach ($selected as $definition) {
                foreach ([$definition['name'], ...$definition['assigns']] as $name) {
                    $replay[$name] = true;
                }
            }
        }

        return ['expressions' => $expressions, 'dynamic' => $dynamic, 'transient' => $transient, 'process' => $process, 'needed' => $needed, 'replay' => $replay];
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

    /** @return array{list<string>, bool, list<string>} */
    private function references(string $statement): array
    {
        $references = [];
        $assigned = [];
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
            if (!preg_match('/^\{?([A-Za-z_][A-Za-z0-9_]*):=/', $tail, $assignment)) {
                continue;
            }
            $assigned[] = $assignment[1];
        }

        return [array_values(array_unique($references)), $command, array_values(array_unique($assigned))];
    }
}
