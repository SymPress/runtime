<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use Closure;

final class Question
{
    /** @var list<string> */
    private array $lines;

    /** @var array<string, string> */
    private array $answers = [];

    private string $default = '';

    private ?Closure $validator = null;

    /**
     * @param list<string> $lines
     * @param array<string, string> $answers
     */
    public function __construct(array $lines, array $answers = [], ?string $default = null)
    {
        $this->lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        if ($this->lines === []) {
            return;
        }
        foreach ($answers as $key => $label) {
            if (trim($key) === '' || trim($label) === '') {
                continue;
            }

            $this->answers[strtolower(trim($key))] = $label;
        }
        $default = strtolower(trim($default ?? ''));
        $this->default = isset($this->answers[$default]) ? $default : (array_key_first($this->answers) ?? '');
    }

    /** @param list<string> $lines */
    public static function newWithValidator(array $lines, callable $validator, ?string $default = null): self
    {
        $question = new self($lines);
        $question->validator = Closure::fromCallable($validator);
        if ($default !== null && $validator($default)) {
            $question->default = $default;
        }

        return $question;
    }

    public function filterAnswer(string $answer): ?string
    {
        $answer = trim($answer);
        if ($this->validator !== null) {
            return ($this->validator)($answer) ? $answer : null;
        }
        $answer = strtolower($answer);

        return isset($this->answers[$answer]) ? $answer : null;
    }

    public function defaultAnswerKey(): string
    {
        return $this->default;
    }

    public function defaultAnswerText(): string
    {
        return $this->answers[$this->default] ?? $this->default;
    }

    /** @return list<string> */
    public function questionLines(): array
    {
        if ($this->lines === []) {
            return [];
        }
        $lines = ['QUESTION:', ...$this->lines];
        if ($this->answers !== [] || $this->default !== '') {
            $lines[] = '';
        }
        if ($this->answers !== []) {
            $lines[] = implode(' | ', $this->answers);
        }
        if ($this->default !== '') {
            $lines[] = "Default: '" . $this->default . "'";
        }

        return $lines;
    }
}
