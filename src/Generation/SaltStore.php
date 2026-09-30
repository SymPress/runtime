<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

use ParseError;
use PhpToken;
use RuntimeException;

/**
 * Reads literal fallbacks without executing an existing configuration.
 *
 * @internal
 */
final readonly class SaltStore
{
    public function __construct(private Salter $salter)
    {
    }

    /** @return array<string, string> */
    public function keys(?string $existing, bool $allowDynamic = false): array
    {
        $preserved = $existing === null ? [] : $this->existingKeys($existing, $allowDynamic);
        if (count($preserved) === count(Salter::KEYS)) {
            return array_replace(array_fill_keys(Salter::KEYS, ''), $preserved);
        }

        return array_replace($this->salter->keys(), $preserved);
    }

    /** @return array<string, string> */
    public function existingKeys(string $source, bool $allowDynamic = false): array
    {
        return array_filter($this->definitions($source, $allowDynamic), is_string(...));
    }

    /** @return list<string> */
    public function definedKeys(string $source): array
    {
        return array_keys($this->definitions($source, true));
    }

    /** @return array<string, string|null> */
    private function definitions(string $source, bool $allowDynamic): array
    {
        try {
            $tokens = array_values(array_filter(PhpToken::tokenize($source, TOKEN_PARSE), static fn (PhpToken $token): bool => !$token->isIgnorable()));
        } catch (ParseError) {
            throw new RuntimeException('Cannot recover salts from invalid PHP configuration.');
        }
        $salts = [];
        foreach ($tokens as $index => $token) {
            if (!in_array($token->id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) || strtolower(ltrim($token->text, '\\')) !== 'define') {
                continue;
            }
            if (in_array($tokens[$index - 1]->id ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            if (($tokens[$index + 1]->text ?? null) !== '(' || ($tokens[$index + 2]->id ?? null) !== T_CONSTANT_ENCAPSED_STRING || ($tokens[$index + 3]->text ?? null) !== ',') {
                continue;
            }
            $name = $this->literal($tokens[$index + 2]->text);
            if (!in_array($name, Salter::KEYS, true)) {
                continue;
            }
            $cursor = $index + 4;
            $value = '';
            while (($tokens[$cursor]->id ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                $value .= $this->literal($tokens[$cursor]->text);
                $cursor++;
                if (($tokens[$cursor]->text ?? null) !== '.') {
                    break;
                }
                $cursor++;
            }
            if ($cursor === $index + 4 || ($tokens[$cursor]->text ?? null) !== ')') {
                if ($allowDynamic) {
                    $salts[$name] = null;
                    continue;
                }
                throw new RuntimeException('Cannot replace a configuration with a nonliteral salt definition: ' . $name);
            }
            if (isset($salts[$name]) && $salts[$name] !== $value) {
                throw new RuntimeException('Conflicting existing salt definitions: ' . $name);
            }
            $salts[$name] = $value;
        }

        return $salts;
    }

    private function literal(string $literal): string
    {
        $quote = $literal[0];
        $value = substr($literal, 1, -1);
        if ($quote === "'") {
            return preg_replace_callback('/\\\\([\\\\\'])/', static fn (array $match): string => $match[1], $value) ?? $value;
        }
        return preg_replace_callback('/\\\\(?:[nrtvef\\\\$"]|[0-7]{1,3}|x[0-9a-fA-F]{1,2}|u\{[0-9a-fA-F]+\})/', static function (array $match): string {
            $escape = substr($match[0], 1);
            $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
            if (isset($simple[$escape])) {
                return $simple[$escape];
            }
            if ($escape[0] !== 'u') {
                return chr((int) ($escape[0] === 'x' ? hexdec(substr($escape, 1)) : octdec($escape)) & 255);
            }
            $point = (int) hexdec(substr($escape, 2, -1));
            if ($point < 0 || $point > 0x10FFFF) {
                throw new RuntimeException('Invalid Unicode escape in existing salt.');
            }

            return match (true) {
                $point < 0x80 => chr($point),
                $point < 0x800 => chr(0xC0 | ($point >> 6)) . chr(0x80 | ($point & 63)),
                $point < 0x10000 => chr(0xE0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 63)) . chr(0x80 | ($point & 63)),
                default => chr(0xF0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 63)) . chr(0x80 | (($point >> 6) & 63)) . chr(0x80 | ($point & 63)),
            };
        }, $value) ?? $value;
    }
}
