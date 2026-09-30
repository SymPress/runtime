<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use Throwable;

/** @internal */
final readonly class Filters
{
    public const string FILTER_BOOL = 'bool';
    public const string FILTER_INT = 'int';
    public const string FILTER_FLOAT = 'float';
    public const string FILTER_INT_OR_BOOL = 'int|bool';
    public const string FILTER_STRING_OR_BOOL = 'string|bool';
    public const string FILTER_STRING = 'string';
    public const string FILTER_RAW_STRING = 'raw-string';
    public const string FILTER_OCTAL_MOD = 'mod';
    public const string FILTER_TABLE_PREFIX = 'table-prefix';

    public function __construct(private string $profile = 'native')
    {
    }

    public static function resolveFilterName(string $name, string $profile = 'native'): string
    {
        $mode = match (strtolower(trim($name))) {
            'bool' => self::FILTER_BOOL,
            'int' => self::FILTER_INT,
            'float' => self::FILTER_FLOAT,
            'int_or_bool' => self::FILTER_INT_OR_BOOL,
            'string_or_bool' => self::FILTER_STRING_OR_BOOL,
            'string' => self::FILTER_STRING,
            'raw_string' => $profile === 'release-3.0.1' ? '' : self::FILTER_RAW_STRING,
            'octal_mod' => self::FILTER_OCTAL_MOD,
            default => '',
        };
        if ($mode !== '' || $profile !== 'native') {
            return $mode;
        }

        return in_array($name, ['int|bool', 'string|bool', 'raw-string', 'mod'], true) ? $name : '';
    }

    public function filter(string $mode, mixed $value): bool|int|float|string|null
    {
        try {
            return $this->apply($mode, $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function apply(string $mode, mixed $value): bool|int|float|string|null
    {
        return match ($mode) {
            self::FILTER_BOOL => $this->boolean($value),
            self::FILTER_INT => is_numeric($value) || is_bool($value) ? (int) $value : null,
            self::FILTER_FLOAT => is_numeric($value) ? (float) $value : null,
            self::FILTER_INT_OR_BOOL => is_numeric($value) ? (int) $value : $this->boolean($value),
            self::FILTER_STRING => is_scalar($value) ? htmlspecialchars(strip_tags((string) $value), ENT_QUOTES, 'UTF-8', false) : null,
            self::FILTER_RAW_STRING => $this->raw($value),
            self::FILTER_STRING_OR_BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null ? $this->filter(self::FILTER_STRING, $value) : $this->boolean($value),
            self::FILTER_OCTAL_MOD => $this->permissions($value),
            self::FILTER_TABLE_PREFIX => !$value || !is_string($value) ? 'wp_' : (string) preg_replace('/\W/', '', $value),
            default => null,
        };
    }

    private function boolean(mixed $value): ?bool
    {
        return $value === '' || $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function raw(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        return match ($this->profile) {
            'release-3.0.1' => htmlspecialchars(strip_tags((string) $value), ENT_QUOTES, 'UTF-8', false),
            'upstream-dev' => addslashes((string) $value),
            default => (string) $value,
        };
    }

    private function permissions(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0 && $value <= 0777) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) @octdec($value) : null;
    }
}
