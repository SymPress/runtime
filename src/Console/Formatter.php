<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

/** @internal */
final class Formatter
{
    public const int DEFAULT_LINE_LENGTH = 58;

    /** @return list<string> */
    public function ensureLinesLength(int $lineLength, string ...$lines): array
    {
        $output = [];
        foreach ($lines as $line) {
            if (trim(strip_tags($line)) === '') {
                $output[] = '';
                continue;
            }
            foreach (preg_split('/[\r\n]+/', trim($line)) ?: [] as $paragraph) {
                array_push($output, ...$this->wrap($paragraph, $lineLength));
            }
        }

        return $output;
    }

    /** @return list<string> */
    private function wrap(string $paragraph, int $lineLength): array
    {
        $output = [];
        $buffer = '';
        foreach (preg_split('/\s+/', trim($paragraph)) ?: [] as $word) {
            if ($buffer !== '' && strlen(strip_tags($buffer . ' ' . $word)) > max(1, $lineLength - 4)) {
                $output[] = $buffer;
                $buffer = '';
            }
            $buffer .= ($buffer === '' ? '' : ' ') . $word;
        }
        if ($buffer !== '') {
            $output[] = $buffer;
        }

        return $output;
    }

    /** @return list<string> */
    public function ensureDefaultLinesLength(string ...$lines): array
    {
        return $this->ensureLinesLength(self::DEFAULT_LINE_LENGTH, ...$lines);
    }

    /** @return list<string> */
    public function createList(string ...$items): array
    {
        return $this->createListWithPrefix(' -', ...$items);
    }

    /** @return list<string> */
    public function createListWithPrefix(string $prefix, string ...$items): array
    {
        $prefix = rtrim($prefix) . ' ';
        $output = [];
        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }
            $lines = $this->ensureLinesLength(self::DEFAULT_LINE_LENGTH - strlen(trim(strip_tags($prefix))) - 1, $item);
            foreach ($lines as $index => $line) {
                $output[] = ($index === 0 ? $prefix : str_repeat(' ', strlen(strip_tags($prefix)))) . $line;
            }
        }

        return $output;
    }

    /** @return list<string> */
    public function createCenteredBlock(string $before = '', string $after = '', string ...$lines): array
    {
        return $this->block($before, $after, true, array_values($lines));
    }

    /** @return list<string> */
    public function createFilledBlock(string $before = '', string $after = '', string ...$lines): array
    {
        return $this->block($before, $after, false, array_values($lines));
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function block(string $before, string $after, bool $centered, array $lines): array
    {
        $lines = $this->ensureDefaultLinesLength(...$lines);
        if (($lines[0] ?? null) === '') {
            array_shift($lines);
        }
        if ($lines !== [] && $lines[array_key_last($lines)] === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return [];
        }
        $width = max(self::DEFAULT_LINE_LENGTH, ...array_map(static fn (string $line): int => strlen(strip_tags($line)), $lines));
        $width = max(2, $width - strlen(strip_tags($before . $after)));
        $result = [''];
        foreach (['', ...$lines, ''] as $line) {
            $padding = max(0, $width - strlen(strip_tags($line)));
            $left = $centered ? intdiv($padding, 2) : 0;
            $result[] = rtrim($before) . '  ' . str_repeat(' ', $left) . $line . str_repeat(' ', $padding - $left) . '  ' . ltrim($after);
        }
        $result[] = '';

        return $result;
    }
}
