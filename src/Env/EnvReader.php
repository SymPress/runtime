<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- This adapter owns the environment/superglobal boundary.
use BadMethodCallException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;

/** Environment implementation also used by the independent generated bootstrap. */
// phpcs:ignore SymPress.Classes.PropertyLimit.TooManyProperties -- Separate origin, cache and lifecycle state preserves environment precedence.
final class EnvReader
{
    public const string CACHE_DUMP_FILE = '/.env.cached.php';
    public const string BUILD_DUMP_FILE = '/.env.dump.php';
    public const string CUSTOM_ENV_TO_CONST_VAR_NAME = 'WP_STARTER_ENV_TO_CONST';
    public const string DB_TABLE_PREFIX_VAR_NAME = 'DB_TABLE_PREFIX';
    public const string WP_ADMIN_COLOR_VAR_NAME = 'WP_ADMIN_COLOR';
    public const string WP_FORCE_SSL_FORWARDED_PROTO_VAR_NAME = 'WP_FORCE_SSL_FORWARDED_PROTO';
    public const string WP_INSTALLED_VAR_NAME = 'WP_INSTALLED';
    public const string WPDB_ENV_VALID_VAR_NAME = 'WPDB_ENV_VALID';
    public const string WPDB_EXISTS_VAR_NAME = 'WPDB_EXISTS';
    public const array WP_CONSTANTS = ConstantCatalog::TYPES;
    public const array ENV_TYPES = EnvironmentName::ALIASES;
    public const array WP_STARTER_ENV_VARS = ['WP_ENV', 'WORDPRESS_ENV', 'WP_ENVIRONMENT_TYPE'];
    public const array WP_STARTER_VARS = [
        'WP_STARTER_ENV_TO_CONST' => 'string',
        'SYMPRESS_RUNTIME_ENV_TO_CONST' => 'string',
        'DB_TABLE_PREFIX' => 'table-prefix',
        'WP_ADMIN_COLOR' => 'string',
        'WP_FORCE_SSL_FORWARDED_PROTO' => 'bool',
        'WP_INSTALLED' => 'bool',
        'WPDB_ENV_VALID' => 'bool',
        'WPDB_EXISTS' => 'bool',
    ];

    /** @var array<string, string> */
    private array $raw = [];
    /** @var array<string, string> */
    private array $external = [];
    /** @var array<string, string> */
    private array $ownedProcess = [];
    /** @var array<string, array{string, bool|int|float|string|null}> */
    private array $cache = [];
    /** @var array<string, string> */
    private array $customTypes = [];
    /** @var list<string> */
    private array $definedConstants = [];
    private bool $loaded = false;
    private bool $fromCache = false;
    private bool $constantsSet = false;
    private bool $wordPressSetup = false;
    private ?string $environment = null;
    private readonly Dotenv $dotenv;
    private readonly Filters $filters;

    public function __construct(?Dotenv $dotenv = null, private readonly string $profile = 'native', private readonly bool $compatibility = true)
    {
        $this->dotenv = $dotenv ?? new Dotenv($profile === 'native' ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENV', 'WP_DEBUG');
        $this->filters = new Filters($profile);
        $owned = self::loadedVars();
        $process = getenv();
        $process = is_array($process) ? $process : [];
        $values = array_replace($_SERVER, $_ENV, $profile === 'native' ? $process : []);
        foreach ($values as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($name, 'HTTP_')) {
                $value = $_ENV[$name] ?? null;
                if (!is_scalar($value)) {
                    continue;
                }
            }
            if (isset($owned[$name])) {
                $this->raw[$name] = (string) $value;
                if (isset($process[$name])) {
                    $this->ownedProcess[$name] = $process[$name];
                }
                continue;
            }
            $this->external[$name] = (string) $value;
        }
    }

    /** @return array<string, true> */
    public static function loadedVars(): array
    {
        $names = $_ENV['SYMFONY_DOTENV_VARS'] ?? $_SERVER['SYMFONY_DOTENV_VARS'] ?? getenv('SYMFONY_DOTENV_VARS');
        $loaded = [];
        foreach (is_string($names) ? explode(',', $names) : [] as $name) {
            if ($name === '') {
                continue;
            }

            $loaded[$name] = true;
        }

        return $loaded;
    }

    public function load(string $file = '.env', ?string $path = null): void
    {
        $this->loadFile(rtrim($path ?? (getcwd() ?: '.'), '/\\') . '/' . $file);
    }

    public function loadFile(string $path): void
    {
        if ($this->loaded || $this->sentinel()) {
            return;
        }
        $this->loaded = true;
        $this->parseFile($path);
    }

    public function loadAppended(string $file, ?string $path = null): void
    {
        if ($this->sentinel()) {
            return;
        }
        $this->loaded = true;
        $this->parseFile(rtrim($path ?? (getcwd() ?: '.'), '/\\') . '/' . $file);
    }

    public function loadChain(string $file = '.env', ?string $path = null, bool $localOverrides = true, ?string $environment = null): void
    {
        if ($environment !== null) {
            $this->selectRequestedEnvironment($environment);
        }
        if ($this->sentinel() || $this->loaded) {
            return;
        }
        $this->load($file, $path);
        $environment = $this->determineEnvType();
        if ($environment === 'example') {
            return;
        }
        if (str_contains($environment, '/') || str_contains($environment, '\\') || str_contains($environment, "\0")) {
            throw new InvalidArgumentException('Environment name cannot contain path separators.');
        }
        if ($localOverrides && $environment !== 'test') {
            $this->loadAppended($file . '.local', $path);
        }
        $this->loadAppended($file . '.' . $environment, $path);
        if (!$localOverrides) {
            return;
        }

        $this->loadAppended($file . '.' . $environment . '.local', $path);
    }

    private function selectRequestedEnvironment(string $environment): void
    {
        $environment = strtolower($environment);
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $environment) || str_contains($environment, '..')) {
            throw new InvalidArgumentException('Requested environment must be a simple name without traversal.');
        }
        $names = $this->profile === 'native' ? ['WP_ENVIRONMENT_TYPE', 'WP_ENV', 'WORDPRESS_ENV'] : self::WP_STARTER_ENV_VARS;
        foreach ($names as $name) {
            $actual = $this->externalValue($name);
            if ($actual === null || $actual === '') {
                continue;
            }
            if (strtolower($actual) !== $environment) {
                throw new InvalidArgumentException('Requested environment conflicts with the actual process environment.');
            }
            break;
        }
        $this->environment = $environment;
        $this->external[$names[0]] = $environment;
        $_ENV[$names[0]] = $environment;
        $_SERVER[$names[0]] = $environment;
        $this->read($names[0]);
    }

    public function determineEnvType(): string
    {
        if ($this->environment !== null) {
            return $this->environment;
        }
        $names = $this->profile === 'native' ? ['WP_ENVIRONMENT_TYPE', 'WP_ENV', 'WORDPRESS_ENV'] : self::WP_STARTER_ENV_VARS;
        foreach ($names as $name) {
            $value = $this->read($name);
            if (is_string($value) && $value !== '') {
                $this->environment = strtolower($value);

                return $this->environment;
            }
        }

        $this->environment = 'production';

        return $this->environment;
    }

    public function has(string $name): bool
    {
        return $this->read($name) !== null;
    }

    public function read(string $name): bool|int|float|string|null
    {
        $raw = $this->rawValue($name);
        if ($raw === null) {
            return null;
        }
        $type = self::WP_CONSTANTS[$name] ?? self::WP_STARTER_VARS[$name] ?? $this->customTypes[$name] ?? null;
        if (in_array($name, self::WP_STARTER_ENV_VARS, true)) {
            $type = 'string';
        }
        $value = $type === null ? $raw : $this->filters->filter($type, $raw);
        $this->cache[$name] = [$raw, $value];

        return $value;
    }

    public function rawValue(string $name): ?string
    {
        return $this->externalValue($name) ?? $this->raw[$name] ?? null;
    }

    /** @return array<string, bool|int|float|string|null> */
    public function readMany(string ...$names): array
    {
        $values = [];
        foreach ($names as $name) {
            $values[$name] = $this->read($name);
        }

        return $values;
    }

    public function write(string $name, string $value): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            throw new InvalidArgumentException('Invalid environment variable name.');
        }
        if ($this->externalValue($name) !== null) {
            throw new BadMethodCallException($name . ' is not a writable environment variable.');
        }
        $this->raw[$name] = $value;
        $_ENV[$name] = $value;
        if (!str_starts_with($name, 'HTTP_')) {
            $_SERVER[$name] = $value;
        }
        if ($this->profile !== 'native') {
            putenv($name . '=' . $value);
            $this->ownedProcess[$name] = $value;
        }
        $names = array_keys($this->raw);
        $_ENV['SYMFONY_DOTENV_VARS'] = implode(',', $names);
        $_SERVER['SYMFONY_DOTENV_VARS'] = implode(',', $names);
        $this->read($name);
    }

    public function hasCachedValues(): bool
    {
        return $this->fromCache && $this->cache !== [];
    }

    public static function buildFromCacheDump(string $file, string $profile = 'native', ?string $environment = null, bool $compatibility = true): self
    {
        $reader = new self(profile: $profile, compatibility: $compatibility);
        if (!is_file($file) || !is_readable($file)) {
            return $reader;
        }
        $header = file_get_contents($file, false, null, 0, 100);
        if (!is_string($header) || !str_starts_with($header, "<?php\n// @generated by sympress/runtime env-cache:v1\n")) {
            throw new RuntimeException('Environment cache format is not supported.');
        }
        try {
            $data = (static fn (string $path): mixed => require $path)($file);
        } catch (Throwable) {
            throw new RuntimeException('Cannot read environment cache.');
        }
        $reader->restoreCache($data, $environment);

        return $reader;
    }

    public function dumpCached(string $file): bool
    {
        if ($this->fromCache || is_link($file) || is_dir($file)) {
            return false;
        }
        foreach (array_keys($this->raw) as $name) {
            $this->read($name);
        }
        if ($this->cache === []) {
            return false;
        }
        $payload = [
            'format' => 1,
            'profile' => $this->profile,
            'compatibility' => $this->compatibility,
            'environment' => $this->determineEnvType(),
            'values' => $this->cache,
            'loaded' => array_keys($this->raw),
            'types' => $this->customTypes,
            'constants' => array_values(array_unique([...$this->definedConstants, ...array_keys(array_intersect_key($this->cache, self::WP_CONSTANTS))])),
        ];
        $header = "<?php\n// @generated by sympress/runtime env-cache:v1\n";
        $content = $header . 'return ' . var_export($payload, true) . ";\n";
        if (is_file($file)) {
            $current = file_get_contents($file);
            if (!is_string($current) || !str_starts_with($current, $header)) {
                return false;
            }
            if ($content === $current) {
                return chmod($file, 0600);
            }
        }
        if (!is_dir(dirname($file))) {
            return false;
        }
        $temporary = @tempnam(dirname($file), '.sympress-env-');
        if ($temporary === false) {
            return false;
        }
        try {
            return chmod($temporary, 0600) && file_put_contents($temporary, $content) !== false && rename($temporary, $file);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function restoreCache(mixed $data, ?string $environment): void
    {
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || ($data['profile'] ?? null) !== $this->profile || !is_string($data['environment'] ?? null)) {
            throw new RuntimeException('Environment cache format or profile is invalid.');
        }
        if (($data['compatibility'] ?? true) !== $this->compatibility) {
            throw new RuntimeException('Environment cache compatibility mode changed; flush the cache or rebuild the environment dump.');
        }
        if ($environment === null) {
            foreach ($this->profile === 'native' ? ['WP_ENVIRONMENT_TYPE', 'WP_ENV', 'WORDPRESS_ENV'] : self::WP_STARTER_ENV_VARS as $name) {
                $external = $this->externalValue($name);
                if ($external !== null && $external !== '') {
                    $environment = strtolower($external);
                    break;
                }
            }
        }
        if ($environment !== null && $environment !== $data['environment']) {
            throw new RuntimeException('Environment cache does not match the requested environment.');
        }
        foreach (['values', 'loaded', 'types', 'constants'] as $field) {
            if (!is_array($data[$field] ?? null)) {
                throw new RuntimeException('Environment cache data is invalid.');
            }
        }
        $types = [];
        foreach ($data['types'] as $name => $type) {
            if (!is_string($name) || !is_string($type)) {
                throw new RuntimeException('Environment cache type map is invalid.');
            }
            $types[$name] = $type;
        }
        $values = [];
        foreach ($data['values'] as $name => $pair) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_array($pair) || count($pair) !== 2 || !is_string($pair[0] ?? null) || !array_key_exists(1, $pair) || (!is_scalar($pair[1]) && $pair[1] !== null)) {
                throw new RuntimeException('Environment cache value is invalid.');
            }
            $values[$name] = [$pair[0], $pair[1]];
        }
        foreach (['loaded', 'constants'] as $field) {
            if (!is_array($data[$field]) || !array_is_list($data[$field])) {
                throw new RuntimeException('Environment cache name list is invalid.');
            }
            foreach ($data[$field] as $name) {
                if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                    throw new RuntimeException('Environment cache name is invalid.');
                }
            }
        }
        $this->customTypes = $types;
        $this->environment = $data['environment'];
        foreach ($values as $name => $pair) {
            $this->cache[$name] = $pair;
            $external = $this->externalValue($name);
            if ($external === null) {
                $this->write($name, $pair[0]);
                continue;
            }
            $_ENV[$name] = $external;
            if (str_starts_with($name, 'HTTP_')) {
                continue;
            }

            $_SERVER[$name] = $external;
        }
        foreach ($data['constants'] as $name) {
            if (!is_string($name)) {
                throw new RuntimeException('Environment cache name is invalid.');
            }
            if (in_array($name, ['WP_ENV', 'WP_ENVIRONMENT_TYPE'], true)) {
                $this->setupEnvConstants();
                continue;
            }
            $this->define($name);
        }
        $this->fromCache = $this->cache !== [];
        $this->loaded = $this->fromCache;
        $this->wordPressSetup = defined('DB_NAME') && defined('DB_USER');
    }

    public function setupKernelBuildId(string $directory, ?string $configured = null): void
    {
        if (defined('SYMPRESS_KERNEL_BUILD_ID')) {
            return;
        }
        $id = $this->rawValue('SYMPRESS_KERNEL_BUILD_ID') ?? $configured;
        $environment = EnvironmentName::canonical($this->determineEnvType());
        $file = rtrim($directory, '/\\') . '/' . $environment . '/kernel-build-id.json';
        if ($id === null && is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || ($data['environment'] ?? null) !== $environment || !is_string($data['id'] ?? null)) {
                throw new RuntimeException('Invalid persisted kernel build ID.');
            }
            $id = $data['id'];
        }
        if ($id === null) {
            return;
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{1,128}$/D', $id)) {
            throw new RuntimeException('Invalid kernel build ID.');
        }
        define('SYMPRESS_KERNEL_BUILD_ID', $id);
    }

    public function setupConstants(): void
    {
        if ($this->constantsSet) {
            return;
        }
        $this->constantsSet = true;
        $this->setupEnvConstants();
        foreach (array_keys(self::WP_CONSTANTS) as $name) {
            $this->define($name);
        }
        foreach (['WP_STARTER_ENV_TO_CONST', 'SYMPRESS_RUNTIME_ENV_TO_CONST'] as $option) {
            if ($option === 'WP_STARTER_ENV_TO_CONST' && !$this->compatibility) {
                continue;
            }
            $list = $this->read($option);
            if ($option === 'WP_STARTER_ENV_TO_CONST' && is_string($list) && $list !== '') {
                LegacyDeprecation::report($option, 'SYMPRESS_RUNTIME_ENV_TO_CONST');
            }
            foreach (is_string($list) ? explode(',', $list) : [] as $item) {
                [$name, $type] = array_pad(explode(':', trim($item), 2), 2, '');
                if ($name === '') {
                    continue;
                }
                $resolved = Filters::resolveFilterName($type, $this->profile);
                if ($resolved !== '') {
                    $this->customTypes[$name] = $resolved;
                }
                $this->define($name);
            }
        }
        $this->wordPressSetup = $this->has('DB_NAME') && $this->has('DB_USER');
    }

    /** @return list<string> */
    public function setupEnvConstants(): array
    {
        $defined = [];
        $raw = $this->determineEnvType();
        $canonical = $this->profile === 'native' ? $raw : $this->read('WP_ENVIRONMENT_TYPE');
        foreach (['WP_ENV' => $raw, 'WP_ENVIRONMENT_TYPE' => EnvironmentName::canonical(is_string($canonical) && $canonical !== '' ? $canonical : $raw)] as $name => $value) {
            if (defined($name)) {
                continue;
            }

            define($name, $value);
            $defined[] = $name;
            $this->definedConstants[] = $name;
        }

        return $defined;
    }

    public function isWpSetup(): bool
    {
        return $this->wordPressSetup;
    }

    private function define(string $name): void
    {
        $value = $this->read($name);
        if ($value !== null && !defined($name)) {
            define($name, $value);
        }
        if ($value === null) {
            return;
        }

        $this->definedConstants[] = $name;
    }

    private function sentinel(): bool
    {
        foreach (['SYMPRESS_RUNTIME_ENV_LOADED', 'WPSTARTER_ENV_LOADED'] as $name) {
            if ($name === 'WPSTARTER_ENV_LOADED' && !$this->compatibility) {
                continue;
            }
            if (!array_key_exists($name, $_ENV) && !array_key_exists($name, $_SERVER) && getenv($name) === false) {
                continue;
            }
            if ($name === 'WPSTARTER_ENV_LOADED') {
                LegacyDeprecation::report($name, 'SYMPRESS_RUNTIME_ENV_LOADED');
            }
            return true;
        }

        return false;
    }

    private function externalValue(string $name): ?string
    {
        if ($this->profile === 'native' && !str_starts_with($name, 'HTTP_')) {
            $process = getenv($name);
            if ($process !== false && ($this->ownedProcess[$name] ?? null) !== $process) {
                return $process;
            }
        }

        return $this->external[$name] ?? null;
    }

    private function parseFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read environment file.');
        }
        // Dotenv interpolation must see actual process values before resolving file variables.
        foreach ($this->external as $name => $value) {
            $_ENV[$name] = $this->externalValue($name) ?? $value;
        }
        try {
            $values = $this->dotenv->parse($content, $path);
        } catch (Throwable) {
            throw new RuntimeException('Cannot parse environment file: ' . $path);
        }
        foreach ($values as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new RuntimeException('Environment parser returned an invalid value.');
            }
            if ($this->externalValue($name) !== null) {
                continue;
            }
            $this->write($name, $value);
        }
    }
}
