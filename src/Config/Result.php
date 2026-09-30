<?php

declare(strict_types=1);

namespace SymPress\Runtime\Config;

use Closure;
use Error;
use LogicException;
use Throwable;

final class Result
{
    private bool $resolving = false;

    private function __construct(
        private mixed $value = null,
        private ?Throwable $failure = null,
        private ?Closure $provider = null,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return match (true) {
            $value instanceof self => self::promise($value->unwrap(...)),
            $value instanceof Throwable => self::error($value),
            default => new self($value),
        };
    }

    public static function none(): self
    {
        return new self();
    }

    public static function error(?Throwable $error = null): self
    {
        return new self(failure: $error ?? new Error('Error.'));
    }

    public static function errored(string $message): self
    {
        return self::error(new Error($message));
    }

    public static function promise(callable $provider): self
    {
        return new self(provider: Closure::fromCallable($provider));
    }

    public function unwrap(): mixed
    {
        $this->resolve();
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->value;
    }

    public function unwrapOrFallback(mixed $fallback = null): mixed
    {
        return $this->notEmpty() ? $this->value : $fallback;
    }

    public function notEmpty(): bool
    {
        $this->resolve();

        return $this->failure === null && $this->value !== null;
    }

    public function is(mixed $compare): bool
    {
        $this->resolve();

        return $this->failure === null && $this->value === $compare;
    }

    public function not(mixed $compare): bool
    {
        return !$this->is($compare);
    }

    public function either(mixed $thing, mixed ...$things): bool
    {
        $this->resolve();

        return $this->failure === null && in_array($this->value, [$thing, ...$things], true);
    }

    private function resolve(): void
    {
        if ($this->resolving) {
            throw new LogicException('A Result promise cannot resolve itself.');
        }
        if ($this->provider === null) {
            return;
        }
        $provider = $this->provider;
        $this->provider = null;
        $this->resolving = true;
        try {
            $value = $provider();
            while ($value instanceof self) {
                $value = $value->unwrap();
            }
            if ($value instanceof Throwable) {
                throw $value;
            }
            $this->value = $value;
        } catch (Throwable $error) {
            $this->failure = $error;
            $this->value = null;
        } finally {
            $this->resolving = false;
        }
    }
}
