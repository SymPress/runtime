<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use PhpToken;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Env\EnvironmentName;
use SymPress\Runtime\Env\TrustedProxy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\SectionMerger;
use Symfony\Component\Filesystem\Path;

/** @internal */
final readonly class ProductionChecks
{
    public function __construct(private Config $config, private Paths $paths)
    {
    }

    /** @return list<array{id: string, status: string, detail: string}> */
    public function inspect(EnvReader $env, bool $dump, ?string $phpUser = null, ?string $webroot = null): array
    {
        $checks = [];
        $record = static function (string $id, ?bool $pass, string $detail) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $pass === null ? 'unknown' : ($pass ? 'pass' : 'fail'), 'detail' => $detail];
        };
        $configFile = $this->config['wp-config-php-path']->unwrapOrFallback($this->paths->root('wp-config.php'));
        $content = is_string($configFile) && is_readable($configFile) ? file_get_contents($configFile) : false;
        $content = is_string($content) ? $content : '';
        [$predefined, $opaque] = $this->bootstrapEvidence($content, $env);
        $record('production.bootstrap', $opaque ? null : true, 'Static bootstrap inspection; opaque PHP, hooks or autoload require verification in the deployed PHP runtime.');
        $canonical = EnvironmentName::canonical($env->determineEnvType());
        $defaults = $this->matchesGeneratedSection($content, 'DEFAULT_ENV');
        $managed = $this->config['composer-managed']->unwrap();
        $managedDefaults = ($managed === true || ($managed === 'auto' && in_array($canonical, ['staging', 'production'], true))) && $this->matchesGeneratedSection($content, 'COMPOSER_MANAGED');
        $home = $predefined['WP_HOME'] ?? $env->rawValue('WP_HOME');
        $record('production.home', $opaque ? null : (is_string($home) && filter_var($home, FILTER_VALIDATE_URL) !== false && parse_url($home, PHP_URL_SCHEME) === 'https' && parse_url($home, PHP_URL_USER) === null), 'WP_HOME must be an explicit HTTPS URL without credentials.');
        $record('production.debug-display', $this->effectiveBoolean($env, 'WP_DEBUG_DISPLAY', false, $defaults && in_array($canonical, ['staging', 'production'], true), $predefined, $opaque), 'Effective WP_DEBUG_DISPLAY must be false.');
        $record('production.debug', $this->effectiveBoolean($env, 'WP_DEBUG', false, $defaults && $canonical === 'production', $predefined, $opaque), 'Effective WP_DEBUG must be false for production.');
        $record('production.dump', $dump, 'A readable deployment environment dump is required.');
        $secret = $env->rawValue('APP_SECRET');
        $record('production.app-secret', is_string($secret) && strlen($secret) >= 32, 'APP_SECRET requires at least 32 bytes generated with a cryptographically secure random source.');
        $record('production.env-cache', !$this->config['cache-env']->is(false), 'Production must not explicitly disable environment caching.');
        $record('production.proxy-trust', $this->hasProxyTrust($env), 'A forwarded scheme header or enabled forwarded SSL requires explicit trusted proxy addresses or CIDRs.');
        $record('production.file-mods', $this->effectiveBoolean($env, 'DISALLOW_FILE_MODS', true, $managedDefaults, $predefined, $opaque), 'Effective DISALLOW_FILE_MODS must be true, explicitly or through the generated Composer-managed defaults.');
        $record('production.auto-updates', $this->both($this->effectiveBoolean($env, 'AUTOMATIC_UPDATER_DISABLED', true, $managedDefaults, $predefined, $opaque), $this->effectiveBoolean($env, 'WP_AUTO_UPDATE_CORE', false, $managedDefaults, $predefined, $opaque)), 'Effective automatic updates must be disabled, explicitly or through Composer-managed defaults.');
        $record('production.wordpress-hardening', $this->effectiveBoolean($env, 'SYMPRESS_ENABLE_WORDPRESS_HARDENING', true, $managedDefaults, $predefined, $opaque), 'The WordPress hardening switch must be enabled; activation of its consumer remains a separate bootstrap acceptance check.');
        $record('production.unfiltered-html', $this->effectiveBoolean($env, 'DISALLOW_UNFILTERED_HTML', true, $managedDefaults, $predefined, $opaque), 'Effective DISALLOW_UNFILTERED_HTML must be true.');
        $record('production.unfiltered-uploads', $this->effectiveBoolean($env, 'ALLOW_UNFILTERED_UPLOADS', false, $managedDefaults, $predefined, $opaque), 'Effective ALLOW_UNFILTERED_UPLOADS must be false.');
        $record('production.external-http', $this->effectiveBoolean($env, 'WP_HTTP_BLOCK_EXTERNAL', true, $managedDefaults, $predefined, $opaque), 'Effective WP_HTTP_BLOCK_EXTERNAL must be true; approve required integration hosts explicitly through WP_ACCESSIBLE_HOSTS.');
        $record('production.commands', !(new EnvironmentFiles($this->config, $this->paths))->containsCommands(), 'Environment source files must not contain shell command substitutions.');
        $public = Path::canonicalize($webroot === null ? $this->paths->wpParent() : Path::makeAbsolute($webroot, $this->paths->root()));
        $files = (new EnvironmentFiles($this->config, $this->paths))->activeFiles();
        $configuredDirectory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
        $directory = is_string($configuredDirectory) ? rtrim($configuredDirectory, '/') : $this->paths->root();
        foreach ([EnvReader::BUILD_DUMP_FILE, EnvReader::CACHE_DUMP_FILE] as $suffix) {
            if (!is_file($directory . $suffix) && !is_link($directory . $suffix)) {
                continue;
            }
            $files[] = $directory . $suffix;
        }
        foreach ($files as $index => $file) {
            $resolved = realpath($file) ?: $file;
            $publicResolved = realpath($public) ?: $public;
            $record('production.env-location.' . $index, !Path::isBasePath($public, Path::canonicalize($file)) && !Path::isBasePath($publicResolved, $resolved), 'Environment files must be outside the declared webroot. Server deny rules require a separate HTTP acceptance check.');
            $record('production.env-symlink.' . $index, !is_link($file), 'Environment artifacts must be regular files, not symlinks.');
            $mode = @fileperms($file);
            $record('production.env-mode.' . $index, $mode !== false && in_array($mode & 0777, [0600, 0640], true), 'Environment files require private 0600 or group-readable 0640 permissions.');
        }
        if (is_string($configFile)) {
            $files[] = $configFile;
            $mode = @fileperms($configFile);
            $record('production.config-mode', $mode !== false && in_array($mode & 0777, [0600, 0640], true), 'Generated configuration requires private 0600 or group-readable 0640 permissions.');
            $record('production.config-symlink', !is_link($configFile), 'Generated configuration must be a regular file, not a symlink.');
            $record('production.salts', $this->hasPrivateSalts($content, $env), 'All eight authentication keys and salts must be non-default values.');
        }
        foreach (array_values(array_unique($files)) as $index => $file) {
            $readable = $phpUser === null ? $this->currentIdentityReadable($file) : $this->readableBy($file, $phpUser);
            $checks[] = ['id' => 'production.readable.' . $index, 'status' => $readable === null ? 'unknown' : ($readable ? 'pass' : 'fail'), 'detail' => $phpUser === null ? 'Readable by this PHP process; run doctor as the PHP-FPM identity for authoritative access checks.' : 'Requested identity checked against file and parent-directory POSIX modes; another identity remains unknown because ACLs require an actual access check.'];
        }

        return $checks;
    }

    private function hasProxyTrust(EnvReader $env): bool
    {
        // phpcs:ignore SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable.DisallowedSuperGlobalVariable -- Read-only diagnostic of the current request's proxy header.
        $header = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if ($header === null && $env->read('WP_FORCE_SSL_FORWARDED_PROTO') !== true) {
            return true;
        }
        $trusted = $env->rawValue('SYMPRESS_RUNTIME_TRUSTED_PROXIES');
        if ($trusted === null) {
            return false;
        }
        foreach (explode(',', $trusted) as $entry) {
            $peer = explode('/', trim($entry))[0];
            if (TrustedProxy::forwardsHttps($peer, 'https', $entry)) {
                return true;
            }
        }

        return false;
    }

    private function hasPrivateSalts(string $content, EnvReader $env): bool
    {
        $literals = $this->literalDefinitions($content);
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $value = $env->rawValue($name);
            if ($value === null) {
                $value = $literals[$name] ?? null;
            }
            if ($value === null || strlen($value) < 32 || stripos($value, 'put your unique phrase here') !== false) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, bool|string> $predefined */
    private function effectiveBoolean(EnvReader $env, string $name, bool $expected, bool $defaultApplies, array $predefined, bool $opaque): ?bool
    {
        if (array_key_exists($name, $predefined)) {
            return $predefined[$name] !== $expected ? false : ($opaque ? null : true);
        }
        if ($opaque) {
            return null;
        }
        if ($env->rawValue($name) !== null) {
            return $env->read($name) === $expected;
        }

        return $defaultApplies;
    }

    private function both(?bool $first, ?bool $second): ?bool
    {
        if ($first === false || $second === false) {
            return false;
        }

        return $first === null || $second === null ? null : true;
    }

    /** @return array{array<string, bool|string>, bool} */
    private function bootstrapEvidence(string $content, EnvReader $env): array
    {
        $merger = new SectionMerger();
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/wp-config.php');
        $templates = is_string($template) ? $merger->sections($template) : [];
        preg_match('/^[A-Z][A-Z0-9_]*\s*:\s*\{/m', $content, $first, PREG_OFFSET_CAPTURE);
        $prefix = isset($first[0][1]) ? substr($content, 0, $first[0][1]) : $content;
        [$definitions, $opaque] = $this->standaloneDefinitions($prefix);
        $outside = preg_replace('/^[A-Z][A-Z0-9_]*\s*:\s*\{.*?\}\s*#@@\/[A-Z][A-Z0-9_]*\b/ms', '', $content) ?? $content;
        $outside = str_replace("require_once ABSPATH . 'wp-settings.php';", '', $outside);
        [$outsideDefinitions, $outsideOpaque] = $this->standaloneDefinitions($outside);
        // Definitions between sections need execution-order evidence; never infer defaults over them.
        $opaque = $opaque || $outsideOpaque || $outsideDefinitions !== $definitions;
        foreach ($merger->sections($content) as $name => $body) {
            if ($name === 'KEYS') {
                [$keys, $keysOpaque] = $this->standaloneDefinitions('<?php ' . $body);
                $saltNames = array_fill_keys(['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'], true);
                $opaque = $opaque || $keysOpaque || array_diff_key($keys, $saltNames) !== [];
                continue;
            }
            if (isset($templates[$name]) && $this->matchesTemplateShape($body, $templates[$name])) {
                continue;
            }

            $opaque = true;
        }
        $early = $this->config['early-hook-file']->unwrapOrFallback('');
        $environmentDirectory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
        $bootstrap = $this->config['env-bootstrap-dir']->unwrapOrFallback($environmentDirectory);
        $hasBootstrap = is_string($bootstrap) && is_file(rtrim($bootstrap, '/') . '/' . $env->determineEnvType() . '.php');
        $opaque = $opaque || $this->config['wp-config-autoload']->is(true) || (is_string($early) && $early !== '') || $hasBootstrap || $this->config['templates-dir']->not(null);

        return [$definitions, $opaque];
    }

    private function matchesTemplateShape(string $actual, string $template): bool
    {
        $target = $this->config['wp-config-php-path']->unwrapOrFallback($this->paths->root('wp-config.php'));
        $directory = is_string($target) ? dirname($target) : $this->paths->root();
        $envDirectory = $this->config['env-dir']->unwrapOrFallback($this->paths->root());
        $bootstrap = $this->config['env-bootstrap-dir']->unwrapOrFallback($envDirectory);
        $early = $this->config['early-hook-file']->unwrapOrFallback('');
        $template = strtr($template, [
            '{{{PROJECT_AUTOLOAD_ENABLED}}}' => $this->config['wp-config-autoload']->is(true) ? 'true' : 'false',
            '{{{ENV_BOOTSTRAP_PATH}}}' => is_string($bootstrap) ? $this->pathExpression($directory, $bootstrap) : "''",
            '{{{EARLY_HOOK_PATH}}}' => is_string($early) && $early !== '' ? $this->pathExpression($directory, $early) : "''",
        ]);
        $parts = preg_split('/(\{\{\{[A-Z_]+\}\}\})/', implode('', $this->codeTokens($template)), flags: PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return false;
        }
        $literal = "(?:'(?:[^'\\\\]|\\\\.)*'|true|false|null|NULL|[0-9]+)";
        $expression = '(?:__DIR__(?:\.' . $literal . ')?|' . $literal . ')';
        $pattern = '';
        foreach ($parts as $part) {
            $pattern .= str_starts_with($part, '{{{') ? $expression : preg_quote($part, '~');
        }

        return preg_match('~^' . $pattern . '$~sD', implode('', $this->codeTokens($actual))) === 1;
    }

    private function pathExpression(string $from, string $to): string
    {
        $relative = Path::makeRelative($to, $from);

        return $relative === '' ? '__DIR__' : '__DIR__ . ' . var_export('/' . $relative, true);
    }

    /** Only unconditional top-level literal definitions provide precedence evidence.
     *
     * @return array{array<string, bool|string>, bool}
     */
    private function standaloneDefinitions(string $source): array
    {
        $tokens = $this->codeTokens(str_starts_with($source, '<?php') ? substr($source, 5) : $source);
        $definitions = [];
        $index = 0;
        while ($index < count($tokens)) {
            if (array_slice($tokens, $index, 7) === ['declare', '(', 'strict_types', '=', '1', ')', ';']) {
                $index += 7;
                continue;
            }
            if (($tokens[$index] ?? '') === 'defined' && ($tokens[$index + 1] ?? '') === '(' && ($tokens[$index + 3] ?? '') === ')' && ($tokens[$index + 4] ?? '') === '||' && ($tokens[$index + 2] ?? '') === ($tokens[$index + 7] ?? '')) {
                $index += 5;
            }
            if (($tokens[$index] ?? '') !== 'define' || ($tokens[$index + 1] ?? '') !== '(' || ($tokens[$index + 3] ?? '') !== ',' || ($tokens[$index + 5] ?? '') !== ')' || ($tokens[$index + 6] ?? '') !== ';') {
                return [$definitions, true];
            }
            $name = $tokens[$index + 2];
            $value = $tokens[$index + 4];
            if (preg_match('/^[\"\'][A-Z_][A-Z0-9_]*[\"\']$/D', $name) !== 1) {
                return [$definitions, true];
            }
            if (!in_array(strtolower($value), ['true', 'false'], true) && (!str_starts_with($value, "'") || !str_ends_with($value, "'"))) {
                return [$definitions, true];
            }
            $name = trim($name, "\"'");
            $definitions[$name] ??= in_array(strtolower($value), ['true', 'false'], true) ? strtolower($value) === 'true' : substr($value, 1, -1);
            $index += 7;
        }

        return [$definitions, false];
    }

    private function matchesGeneratedSection(string $content, string $name): bool
    {
        $merger = new SectionMerger();
        $actual = $merger->sections($content)[$name] ?? null;
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/wp-config.php');
        $expected = is_string($template) ? ($merger->sections($template)[$name] ?? null) : null;
        if ($actual === null || $expected === null) {
            return false;
        }
        $expected = strtr($expected, [
            '{{{COMPOSER_MANAGED}}}' => var_export($this->config['composer-managed']->unwrap(), true),
            '{{{PROFILE}}}' => var_export($this->config['compatibility-profile']->unwrap(), true),
        ]);

        return $this->codeTokens($actual) === $this->codeTokens($expected);
    }

    /** @return list<string> */
    private function codeTokens(string $content): array
    {
        return array_values(array_map(static fn (PhpToken $token): string => $token->text, array_filter(PhpToken::tokenize('<?php ' . $content), static fn (PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]))));
    }

    /** Read only quoted define() literals; comments and expressions never count.
     *
     * @return array<string, string>
     */
    private function literalDefinitions(string $content): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($content), static fn (PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $definitions = [];
        foreach ($tokens as $index => $token) {
            if (strtolower($token->text) !== 'define' || ($tokens[$index + 1]->text ?? '') !== '(' || !($tokens[$index + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING) || ($tokens[$index + 3]->text ?? '') !== ',' || !($tokens[$index + 4] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING) || ($tokens[$index + 5]->text ?? '') !== ')') {
                continue;
            }
            $name = trim($tokens[$index + 2]->text, "\"'");
            $value = $tokens[$index + 4]->text;
            // Generated salts use single-quoted PHP literals. Decode without eval.
            $definitions[$name] = $value[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($value, 1, -1)) : stripcslashes(substr($value, 1, -1));
        }

        return $definitions;
    }

    private function currentIdentityReadable(string $file): bool
    {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $parent = dirname($file);
        while (true) {
            if (!is_executable($parent)) {
                return false;
            }
            $next = dirname($parent);
            if ($next === $parent) {
                return true;
            }
            $parent = $next;
        }
    }

    private function readableBy(string $file, string $user): ?bool
    {
        if (!function_exists('posix_getpwnam') || !function_exists('posix_getgrgid')) {
            return null;
        }
        $identity = posix_getpwnam($user);
        if ($identity === false) {
            return null;
        }
        if (function_exists('posix_geteuid') && $identity['uid'] === posix_geteuid()) {
            return $this->currentIdentityReadable($file);
        }
        $path = realpath($file);
        if ($path === false) {
            return false;
        }
        $directory = false;
        do {
            $stat = @stat($path);
            if ($stat === false) {
                return false;
            }
            $group = posix_getgrgid($stat['gid']);
            $sameGroup = $stat['gid'] === $identity['gid'] || ($group !== false && in_array($user, $group['members'], true));
            $bit = $stat['uid'] === $identity['uid'] ? ($directory ? 0100 : 0400) : ($sameGroup ? ($directory ? 0010 : 0040) : ($directory ? 0001 : 0004));
            if ($identity['uid'] !== 0 && ($stat['mode'] & $bit) === 0) {
                return false;
            }
            $next = dirname($path);
            $directory = true;
            if ($next === $path) {
                break;
            }
            $path = $next;
        } while (true);

        // Cross-identity mode bits cannot prove effective access in the presence of ACLs.
        return null;
    }
}
