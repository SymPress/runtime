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
    public const string AUTOLOAD = 'autoload';
    public const string CACHE_ENV = 'cache-env';
    public const string CHECK_VCS_IGNORE = 'check-vcs-ignore';
    public const string COMMAND_STEPS = 'command-steps';
    public const string COMPOSER_UPDATED_PACKAGES = 'composer-updated-packages';
    public const string CONTENT_DEV_DIR = 'content-dev-dir';
    public const string CONTENT_DEV_OPERATION = 'content-dev-op';
    public const string CREATE_VCS_IGNORE_FILE = 'create-vcs-ignore-file';
    public const string CUSTOM_STEPS = 'custom-steps';
    public const string DB_CHECK = 'db-check';
    public const string DROPINS = 'dropins';
    public const string DROPINS_OPERATION = 'dropins-op';
    public const string EARLY_HOOKS_FILE = 'early-hook-file';
    public const string ENV_BOOTSTRAP_DIR = 'env-bootstrap-dir';
    public const string ENV_DIR = 'env-dir';
    public const string ENV_EXAMPLE = 'env-example';
    public const string ENV_FILE = 'env-file';
    public const string INSTALL_WP_CLI = 'install-wp-cli';
    public const string IS_COMPOSER_INSTALL = 'is-composer-install';
    public const string IS_COMPOSER_UPDATE = 'is-composer-update';
    public const string IS_WPSTARTER_COMMAND = 'is-wpstarter-command';
    public const string IS_WPSTARTER_SELECTED_COMMAND = 'is-wpstarter-selected-command';
    public const string MOVE_CONTENT = 'move-content';
    public const string PREVENT_OVERWRITE = 'prevent-overwrite';
    public const string REGISTER_THEME_FOLDER = 'register-theme-folder';
    public const string REQUIRE_WP = 'require-wp';
    public const string SCRIPTS = 'scripts';
    public const string SKIP_DB_CHECK = 'skip-db-check';
    public const string SKIP_STEPS = 'skip-steps';
    public const string TEMPLATES_DIR = 'templates-dir';
    public const string WP_CLI_COMMANDS = 'wp-cli-commands';
    public const string WP_CLI_FILES = 'wp-cli-files';
    public const string WP_CONFIG_PATH = 'wp-config-php-path';
    public const string WP_VERSION = 'wp-version';

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
