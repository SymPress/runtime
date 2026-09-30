<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use RuntimeException;

/**
 * Shared validation for CLI checks and deployment dumps; never reports values.
 *
 * @internal
 */
final class EnvRequirements
{
    /**
     * @param array<array-key, mixed> $requirements
     * @return list<string>
     */
    public function validate(EnvReader $reader, array $requirements): array
    {
        $errors = [];
        foreach ($requirements as $name => $type) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_string($type) || !in_array($type, ['string', 'int', 'bool', 'float'], true)) {
                $errors[] = 'Invalid required environment name or type.';
                continue;
            }
            try {
                $value = $reader->rawValue($name);
            } catch (RuntimeException) {
                $errors[] = 'Required environment variable ' . $name . ' cannot be read.';
                continue;
            }
            if ($value === null || $value === '') {
                $errors[] = 'Required environment variable ' . $name . ' is missing or empty.';
                continue;
            }
            $valid = match ($type) {
                'string' => true,
                'int' => filter_var($value, FILTER_VALIDATE_INT) !== false,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null,
                'float' => is_numeric($value) && is_finite((float) $value),
            };
            if ($valid) {
                continue;
            }
            $errors[] = 'Required environment variable ' . $name . ' must have type ' . $type . '.';
        }

        return $errors;
    }
}
