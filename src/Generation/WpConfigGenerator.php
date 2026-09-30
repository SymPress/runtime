<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

use PhpToken;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class WpConfigGenerator
{
    public function __construct(private Config $config, private Paths $paths, private Io $io, private FileContentBuilder $templates, private SaltStore $salts, private RuntimeBundleBuilder $bundleBuilder, private OverwritePolicy $overwrite, private ProjectBoundary $boundary, private ArtifactWriter $writer, private SectionMerger $sections)
    {
    }

    public function target(): string
    {
        $fallback = $this->config['compatibility-profile']->is('release-3.0.1') ? $this->paths->wpParent('wp-config.php') : $this->paths->root('wp-config.php');
        $path = $this->config['wp-config-php-path']->unwrapOrFallback($fallback);
        if (!is_string($path)) {
            throw new RuntimeException('Invalid configuration target path.');
        }

        return $path;
    }

    public function generate(bool $force = false, bool $primaryApproved = false): bool
    {
        $target = $this->target();
        $proxy = $this->paths->wpParent('wp-config.php');
        foreach (array_unique([$target, $proxy]) as $file) {
            $this->boundary->assertWritablePath($file);
            if (!($primaryApproved && $file === $target) && !$this->overwrite->shouldOverwrite($file, $force)) {
                throw new RuntimeException('Configuration target is protected: ' . $file);
            }
        }
        $existing = is_file($target) ? file_get_contents($target) : null;
        if ($existing === false) {
            throw new RuntimeException('Cannot read existing configuration.');
        }
        $oldSections = $existing === null ? [] : $this->sections->sections($existing);
        if ($existing !== null && isset($oldSections['KEYS'])) {
            // Only the retained KEYS body may contain dynamic definitions.
            $this->salts->existingKeys($this->sections->replace($existing, 'KEYS', ''));
        }
        $keys = $this->salts->keys($existing, allowDynamic: isset($oldSections['KEYS']));
        $bundle = $this->bundleBuilder->build();
        $directory = dirname($target);
        $environment = $this->stringOption('env-dir', $this->paths->root());
        $envBootstrap = $this->stringOption('env-bootstrap-dir', $environment);
        $earlyHook = $this->stringOption('early-hook-file', '');
        (new Filesystem())->mkdir($environment);
        $register = $this->config['register-theme-folder']->unwrapOrFallback(false);
        if ($register === 'ask') {
            $register = $this->io->askConfirm('Register themes from the WordPress core package?', true);
        }
        $compatibility = $this->config['compatibility']->is(true);
        $vars = [
            'CORE_PATH' => $this->expression($directory, $this->paths->wp()),
            'ROOT_PATH' => $this->expression($directory, $this->paths->root()),
            'ENV_PATH' => $this->expression($directory, $environment),
            'LEGACY_PROJECT_PATH' => $this->expression($directory, $this->config['compatibility-profile']->is('release-3.0.1') ? $environment : $this->paths->root()),
            'CONTENT_PATH' => $this->expression($directory, $this->paths->wpContent()),
            'PAYLOAD_PATH' => $this->expression($directory, $bundle->loader),
            'PAYLOAD_ID' => var_export($bundle->fingerprint, true),
            'PROFILE' => var_export($this->config['compatibility-profile']->unwrap(), true),
            'ENV_FILENAME' => var_export($this->config['env-file']->unwrap(), true),
            'KERNEL_BUILD_ID' => var_export($this->config['kernel-build-id']->unwrap(), true),
            'ENV_BOOTSTRAP_PATH' => $this->expression($directory, $envBootstrap),
            'EARLY_HOOK_PATH' => $earlyHook === '' ? "''" : $this->expression($directory, $earlyHook),
            'CORE_URL_PATH' => var_export($this->urlPath($this->paths->wp()), true),
            'CONTENT_URL_PATH' => var_export($this->urlPath($this->paths->wpContent()), true),
            'COMPATIBILITY' => $compatibility ? 'true' : 'false',
            'CACHE_ENABLED' => $this->config['cache-env']->is(true) ? 'true' : 'false',
            'LOCAL_OVERRIDES' => $this->config['env-local-overrides']->is(true) ? 'true' : 'false',
            'REGISTER_THEMES' => $register === true ? 'true' : 'false',
            'SALT_DEFINITIONS' => $this->saltDefinitions($keys),
        ];
        $vars += $this->legacyVariables($directory, $environment, $envBootstrap, $earlyHook, $register === true, $keys);
        if ($this->paths->template('wp-config.php') !== $this->paths->absolute('runtime', 'templates/wp-config.php')) {
            $this->io->comment('Custom wp-config.php template controls the bootstrap. Verify its independent runtime behavior.');
        }
        $generated = $this->templates->build($this->paths, 'wp-config.php', $vars);
        if (isset($oldSections['KEYS'])) {
            $known = array_fill_keys($this->salts->definedKeys('<?php ' . $oldSections['KEYS']), true);
            $missing = array_diff_key($keys, $known);
            $body = $oldSections['KEYS'];
            if ($missing !== []) {
                $body = rtrim($body) . "\n" . $this->saltDefinitions($missing) . "\n";
            }
            $generated = $this->sections->replace($generated, 'KEYS', $body);
        }
        $merged = $this->sections->merge($generated, $existing);
        foreach ($merged['preserved'] as $name) {
            $this->io->comment('Preserved edited configuration section: ' . $name);
        }
        $proxyContent = $proxy === $target ? null : $this->templates->build($this->paths, 'wp-config-loader.php', [
            'CONFIG_PATH' => $this->expression(dirname($proxy), $target),
            'WP_CONFIG_PATH' => $this->escapedRelative(dirname($proxy), $target),
            'COMPATIBILITY' => $compatibility ? 'true' : 'false',
        ]);
        // Validate both files before publishing either target.
        PhpToken::tokenize($merged['content'], TOKEN_PARSE);
        if ($proxyContent !== null) {
            PhpToken::tokenize($proxyContent, TOKEN_PARSE);
        }
        if (!$this->writer->write($target, $merged['content'])) {
            return false;
        }

        return $proxyContent === null || $this->writer->write($proxy, $proxyContent);
    }

    private function stringOption(string $option, string $fallback): string
    {
        $value = $this->config[$option]->unwrapOrFallback($fallback);
        if (!is_string($value)) {
            throw new RuntimeException('Invalid string option: ' . $option);
        }

        return $value;
    }

    private function expression(string $from, string $to): string
    {
        $relative = Path::makeRelative($to, $from);

        return $relative === '' ? '__DIR__' : '__DIR__ . ' . var_export('/' . $relative, true);
    }

    private function escapedRelative(string $from, string $to): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], '/' . Path::makeRelative($to, $from));
    }

    private function urlPath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', Path::makeRelative($path, $this->paths->wpParent()))));
    }

    /** @param array<string, string> $keys */
    private function saltDefinitions(array $keys): string
    {
        $lines = [];
        foreach ($keys as $name => $value) {
            $literal = var_export($name, true);
            $lines[] = '    defined(' . $literal . ') || define(' . $literal . ', ' . var_export($value, true) . ');';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $keys
     * @return array<string, string>
     */
    private function legacyVariables(string $directory, string $environment, string $bootstrap, string $early, bool $themes, array $keys): array
    {
        $vars = [
            'AUTOLOAD_PATH' => $this->escapedRelative($directory, $this->paths->vendor('autoload.php')),
            'CACHE_ENV' => $this->config['cache-env']->is(true) ? '1' : '',
            'EARLY_HOOKS_FILE' => $early === '' ? '' : $this->escapedRelative($directory, $early),
            'ENV_BOOTSTRAP_DIR' => $this->escapedRelative($directory, $bootstrap),
            'ENV_FILE_NAME' => $this->stringOption('env-file', '.env'),
            'WPSTARTER_PATH' => $this->escapedRelative($directory, $this->config['compatibility-profile']->is('release-3.0.1') ? $environment : $this->paths->root()),
            'ENV_REL_PATH' => $this->escapedRelative($directory, $environment),
            'REGISTER_THEME_DIR' => $themes ? 'true' : 'false',
            'WP_CONTENT_PATH' => $this->escapedRelative($directory, $this->paths->wpContent()),
            'WP_CONTENT_URL_RELATIVE' => $this->urlPath($this->paths->wpContent()),
            'WP_INSTALL_PATH' => $this->escapedRelative($directory, $this->paths->wp()),
            'WP_SITEURL_RELATIVE' => $this->urlPath($this->paths->wp()),
        ];
        foreach ($keys as $name => $value) {
            $vars[$name] = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        }

        return $vars;
    }
}
