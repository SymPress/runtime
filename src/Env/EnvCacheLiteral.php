<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use PhpToken;
use RuntimeException;

/** Reads generated var_export data without executing PHP. @internal */
final class EnvCacheLiteral
{
    /** @var list<PhpToken> */
    private array $tokens;
    private int $cursor = 0;

    /** @return array<array-key, mixed> */
    public function decode(string $source): array
    {
        $this->tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => !$token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $this->cursor = 0;
        $this->expect('return');
        $value = $this->value();
        $this->expect(';');
        if ($this->cursor !== count($this->tokens) || !is_array($value)) {
            throw new RuntimeException('Environment cache must contain only generated literal data.');
        }
        return $value;
    }

    private function value(int $depth = 0): mixed
    {
        if ($depth > 64 || !isset($this->tokens[$this->cursor])) {
            throw new RuntimeException('Environment cache literal data is invalid.');
        }
        $token = $this->tokens[$this->cursor];
        ++$this->cursor;
        if ($token->id === T_ARRAY) {
            $this->expect('(');
            $array = [];
            while (($this->tokens[$this->cursor]->text ?? null) !== ')') {
                $key = $this->value($depth + 1);
                $this->expect('=>');
                if (!is_int($key) && !is_string($key)) {
                    throw new RuntimeException('Environment cache literal key is invalid.');
                }
                $array[$key] = $this->value($depth + 1);
                $this->expect(',');
            }
            $this->expect(')');
            return $array;
        }
        if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
            $value = $token->text[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($token->text, 1, -1)) : stripcslashes(substr($token->text, 1, -1));
            if (($this->tokens[$this->cursor]->text ?? null) === '.') {
                ++$this->cursor;
                $next = $this->value($depth + 1);
                if (!is_string($next)) {
                    throw new RuntimeException('Environment cache string literal is invalid.');
                }
                $value .= $next;
            }
            return $value;
        }
        if ($token->id === T_LNUMBER) {
            return (int) $token->text;
        }
        if ($token->id === T_DNUMBER) {
            return (float) $token->text;
        }
        if ($token->text === '-') {
            $number = $this->value($depth + 1);
            if (is_int($number) || is_float($number)) {
                return -$number;
            }
        }
        return match (strtolower($token->text)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new RuntimeException('Environment cache must contain only generated literal data.'),
        };
    }

    private function expect(string $text): void
    {
        $token = $this->tokens[$this->cursor] ?? null;
        ++$this->cursor;
        if ($token?->text !== $text) {
            throw new RuntimeException('Environment cache literal syntax is invalid.');
        }
    }
}
