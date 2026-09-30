<?php

declare(strict_types=1);

namespace SymPress\Runtime\Config;

use ArrayAccess;
use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** @implements ArrayAccess<string, mixed> */
final class Config implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $raw;

    /** @var array<string, Result> */
    private array $resolved = [];

    /** @var array<string, callable(mixed): mixed> */
    private array $customValidators = [];

    /** @var array<string, mixed> */
    private array $defaults;

    /** @param array<string, mixed> $values */
    public function __construct(array $values, private readonly Validator $validator, string $profile = 'native')
    {
        $this->defaults = Options::defaults($profile);
        $this->raw = array_replace($this->defaults, $values);
    }

    public function appendValidator(string $name, callable $callback): self
    {
        if (array_key_exists($name, Options::DEFAULTS)) {
            throw new InvalidArgumentException('Built-in validators cannot be replaced.');
        }
        $this->customValidators[$name] = $callback;
        unset($this->resolved[$name]);

        return $this;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->raw);
    }

    public function offsetGet(mixed $offset): Result
    {
        if (!is_string($offset) || !$this->offsetExists($offset)) {
            return Result::none();
        }
        if (!array_key_exists($offset, $this->resolved)) {
            $this->resolved[$offset] = $this->validateValue($offset, $this->raw[$offset]);
        }

        return $this->resolved[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_string($offset)) {
            return;
        }
        $current = $this[$offset]->unwrapOrFallback();
        if ($current !== null && (!array_key_exists($offset, $this->defaults) || $current !== $this->defaults[$offset])) {
            throw new BadMethodCallException('A non-default configuration value cannot be replaced: ' . $offset);
        }
        $this->raw[$offset] = $value;
        unset($this->resolved[$offset]);
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Configuration entries cannot be removed.');
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        $errors = [];
        foreach (array_keys($this->raw) as $key) {
            try {
                $this[$key]->unwrap();
            } catch (Throwable $error) {
                $errors[$key] = $error->getMessage();
            }
        }

        return $errors;
    }

    private function validateValue(string $key, mixed $value): Result
    {
        try {
            if (isset($this->customValidators[$key])) {
                return Result::ok(($this->customValidators[$key])($value));
            }

            return $this->validator->validate($key, $value);
        } catch (Throwable $error) {
            return Result::error($error);
        }
    }
}
