<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Options;
use SymPress\Runtime\Env\SecureFileWriter;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\WordPress\DropinCatalog;
use Symfony\Component\Filesystem\Path;

/** Read-only comparison with the outputs of the last successful setup selection. */
final readonly class SetupDrift
{
    public function __construct(private Config $config, private Paths $paths, private RunContext $context)
    {
    }

    /** @param list<string> $steps */
    public function record(array $steps): void
    {
        $file = $this->file($steps);
        $this->safeOutput($file, false);
        $data = ['format' => 1, 'steps' => $steps, 'inputs' => $this->inputs($steps), 'outputs' => $this->outputs($steps)];
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
            throw new RuntimeException('Cannot create setup drift record directory.');
        }
        $content = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (!SecureFileWriter::write($file, $content, 0600)) {
            throw new RuntimeException('Cannot publish setup drift record.');
        }
    }

    /**
     * @param list<string> $steps
     * @return array{changes: list<array{path: string, reason: string}>, unknown: list<string>, exit: int}
     */
    public function inspect(array $steps): array
    {
        $file = $this->file($steps);
        $this->safeOutput($file, false);
        $unknown = [];
        foreach (['custom-steps', 'steps', 'scripts'] as $option) {
            $value = $this->config[$option]->unwrap();
            if ($value === null || $value === []) {
                continue;
            }

            $unknown[] = 'Custom steps and callbacks need their own read-only verification: ' . $option;
        }
        if ($this->config['autoload']->notEmpty()) {
            $unknown[] = 'The run-only service provider is not executed during drift inspection.';
        }
        foreach ((new PackageFinder($this->context))->all() as $package) {
            if (!in_array($package->getType(), ['sympress-runtime-extension', 'wpstarter-extension'], true)) {
                continue;
            }

            $unknown[] = 'Extension steps and providers need their own read-only verification: ' . $package->getName();
        }
        if (in_array('wpcli', $steps, true)) {
            foreach (['wp-cli-commands', 'wp-cli-files'] as $option) {
                if (!$this->config[$option]->notEmpty()) {
                    continue;
                }

                $unknown[] = 'WP-CLI command effects are not executed during drift inspection: ' . $option;
            }
        }
        if (in_array('kernel-cache', $steps, true)) {
            $unknown[] = 'Kernel cache contents need their own read-only verification.';
        }
        if (!is_file($file) || is_link($file)) {
            return ['changes' => [], 'unknown' => [...$unknown, 'No successful setup record for this selection; run setup first.'], 'exit' => 2];
        }
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || ($data['steps'] ?? null) !== $steps || !is_array($data['outputs'] ?? null) || !is_string($data['inputs'] ?? null)) {
            throw new RuntimeException('Setup drift record is invalid.');
        }
        $current = $this->outputs($steps);
        $inputsChanged = !hash_equals($data['inputs'], $this->inputs($steps));
        $changes = [];
        foreach (array_unique([...array_keys($current), ...array_keys($data['outputs'])]) as $path) {
            if (!is_string($path) || Path::isAbsolute($path) || Path::canonicalize($path) !== $path || str_starts_with($path, '../')) {
                throw new RuntimeException('Setup drift path is invalid.');
            }
            if (($data['outputs'][$path] ?? null) !== ($current[$path] ?? null)) {
                $changes[] = ['path' => $path, 'reason' => 'artifact-changed'];
            } elseif ($inputsChanged) {
                $changes[] = ['path' => $path, 'reason' => 'setup-inputs-changed'];
            }
        }
        if ($inputsChanged && $changes === []) {
            $changes[] = ['path' => 'composer.json', 'reason' => 'setup-inputs-changed'];
        }

        return ['changes' => $changes, 'unknown' => $unknown, 'exit' => $changes !== [] ? 1 : ($unknown !== [] ? 2 : 0)];
    }

    /** @param list<string> $steps */
    private function file(array $steps): string
    {
        return $this->paths->root('var/runtime/setup-' . hash('sha256', json_encode($steps, JSON_THROW_ON_ERROR)) . '.json');
    }

    /** @param list<string> $steps */
    private function inputs(array $steps): string
    {
        $files = [$this->context->manifestPath(), $this->paths->root('composer.lock'), $this->paths->root('sympress-runtime.lock'), $this->paths->root('sympress-runtime.json'), $this->paths->vendor('composer/installed.json')];
        foreach (['templates-dir', 'autoload', 'content-dev-dir'] as $key) {
            $path = $this->config[$key]->unwrap();
            if (!is_string($path)) {
                continue;
            }

            $files[] = $path;
        }
        $dropins = $this->config['dropins']->unwrapOrFallback([]);
        if (is_array($dropins)) {
            foreach ($dropins as $source) {
                if (!is_string($source) || filter_var($source, FILTER_VALIDATE_URL) !== false) {
                    continue;
                }

                $files[] = Path::makeAbsolute($source, $this->paths->root());
            }
        }
        foreach ((new PackageFinder($this->context))->findByType('wordpress-dropin') as $package) {
            $files[] = $package->getInstallPath();
        }
        $example = $this->config['env-example']->unwrap();
        if (is_string($example) && $example !== 'ask' && filter_var($example, FILTER_VALIDATE_URL) === false) {
            $files[] = Path::makeAbsolute($example, $this->paths->root());
        }
        if (in_array('muloader', $steps, true)) {
            $files[] = $this->paths->wpContent('mu-plugins/wpstarter-mu-loader.php');
        }
        $files[] = $this->paths->absolute('runtime', 'src');
        $files[] = $this->paths->absolute('runtime', 'templates');
        $hashes = [];
        foreach ($files as $file) {
            $hashes[Path::makeRelative($file, $this->paths->root())] = $this->digest($file);
        }
        if (in_array('muloader', $steps, true)) {
            $plugins = (new MuPluginList(new PackageFinder($this->context), $this->paths))->pluginsList($this->config);
            $discovery = [];
            foreach ($plugins as $name => $file) {
                $header = file_get_contents($file, false, null, 0, 8192);
                if ($header === false) {
                    throw new RuntimeException('Cannot inspect a discovered MU plugin header.');
                }
                $discovery[$name] = ['path' => $file, 'header' => hash('sha256', $header), 'readable' => is_readable($file)];
            }
            $hashes['mu-discovery'] = $discovery;
        }
        if (in_array('envexample', $steps, true)) {
            $environment = $this->option('env-dir', $this->paths->root()) . '/' . $this->option('env-file', '.env');
            $hashes['envexample-active-environment-exists'] = is_file($environment);
        }
        foreach (array_keys(Options::DEFAULTS) as $key) {
            if (in_array($key, Options::INTERNAL, true)) {
                continue;
            }
            $hashes['option:' . $key] = $this->config[$key]->unwrap();
        }

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $steps
     * @return array<string, string|null>
     */
    private function outputs(array $steps): array
    {
        $targets = [];
        $config = $this->option('wp-config-php-path', $this->config['compatibility-profile']->is('release-3.0.1') ? $this->paths->wpParent('wp-config.php') : $this->paths->root('wp-config.php'));
        $map = [
            'wpconfig' => [$config, $this->paths->wpParent('wp-config.php')],
            'index' => [$this->paths->wpParent('index.php')],
            'muloader' => [$this->paths->wpContent('mu-plugins/sympress-runtime-mu-loader.php')],
            'wpcliconfig' => [$this->paths->root('wp-cli.yml')],
            'wpcli' => [$this->paths->root('wp-cli.phar'), ...(glob($this->paths->root('wp-cli-*.phar')) ?: [])],
            'vcsignorecheck' => [$this->paths->root('.gitignore'), $this->paths->root('.hgignore')],
            'envexample' => [$this->option('env-dir', $this->paths->root()) . '/.env.example'],
            'kernel-boot' => [$this->paths->wpContent('mu-plugins/sympress-runtime-kernel.php')],
        ];
        if (in_array('wpconfig', $steps, true)) {
            $this->safeOutput($config, false);
        }
        if (in_array('wpconfig', $steps, true) && is_file($config)) {
            $content = (string) file_get_contents($config);
            preg_match_all('~/var/runtime/([0-9a-f]{64})/~', $content, $matches);
            foreach (array_unique($matches[1]) as $id) {
                $targets[] = $this->paths->root('var/runtime/' . $id);
            }
        }
        $dropins = $this->config['dropins']->unwrapOrFallback([]);
        if (in_array('dropins', $steps, true) && is_array($dropins)) {
            foreach (array_keys($dropins) as $name) {
                if (!is_string($name)) {
                    continue;
                }

                $targets[] = $this->paths->wpContent($name);
            }
        }
        if (in_array('dropins', $steps, true)) {
            foreach ((new PackageFinder($this->context))->findByType('wordpress-dropin') as $package) {
                array_push($targets, ...$this->catalogTargets($package->getInstallPath()));
            }
        }
        if (in_array('movecontent', $steps, true) && $this->config['move-content']->not(false) && $this->config['register-theme-folder']->is(false)) {
            $targets[] = $this->paths->wp('wp-content');
            $targets[] = $this->paths->wpContent();
        }
        $source = $this->config['content-dev-dir']->unwrap();
        if (in_array('publishcontentdev', $steps, true) && is_string($source)) {
            foreach (['plugins', 'themes', 'mu-plugins', 'languages'] as $type) {
                foreach (glob($source . '/' . $type . '/*') ?: [] as $path) {
                    $targets[] = $this->paths->wpContent($type . '/' . basename($path));
                }
            }
        }
        if (in_array('publishcontentdev', $steps, true) && is_string($source)) {
            array_push($targets, ...$this->catalogTargets($source));
        }
        foreach ($steps as $step) {
            array_push($targets, ...($map[$step] ?? []));
        }
        $result = [];
        foreach (array_unique($targets) as $target) {
            $this->safeOutput($target);
            $result[Path::makeRelative($target, $this->paths->root())] = $this->digest($target);
        }
        if (in_array('checkpaths', $steps, true)) {
            $directories = [$this->paths->wpContent()];
            if ($this->config['move-content']->not(true)) {
                $directories[] = $this->paths->wpContent('themes');
                $directories[] = $this->paths->wpContent('plugins');
            }
            foreach ($directories as $directory) {
                $this->safeOutput($directory);
                $relative = Path::makeRelative($directory, $this->paths->root());
                // A move-content tree already has a stronger recursive fingerprint.
                if (array_key_exists($relative, $result)) {
                    continue;
                }

                $result[$relative] = is_link($directory) ? $this->digest($directory) : (is_dir($directory) ? 'directory:' . (string) fileperms($directory) : null);
            }
        }
        ksort($result);

        return $result;
    }

    /** @return list<string> */
    private function catalogTargets(string $source): array
    {
        $targets = [];
        foreach (DropinCatalog::FILES as $name) {
            if (!is_file($source . '/' . $name)) {
                continue;
            }

            $targets[] = $this->paths->wpContent($name);
        }
        return $targets;
    }

    private function safeOutput(string $path, bool $allowLeafLink = true): void
    {
        $root = $this->paths->root();
        if (Path::canonicalize($path) !== $path || !Path::isBasePath($root, $path)) {
            throw new RuntimeException('Setup drift targets must remain inside the project.');
        }
        // Read-only checks must not follow an ancestor link, even one pointing inside.
        for ($ancestor = $allowLeafLink ? dirname($path) : $path; $ancestor !== $root; $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new RuntimeException('Setup drift target has a symlink ancestor.');
            }
        }
        (new ProjectBoundary($this->paths))->assertWritablePath($allowLeafLink ? dirname($path) : $path);
    }

    private function digest(string $path): ?string
    {
        if (is_link($path)) {
            return 'link:' . readlink($path);
        }
        if (is_file($path)) {
            $mode = fileperms($path);
            $hash = hash_file('sha256', $path);
            if ($mode === false || $hash === false) {
                throw new RuntimeException('Cannot fingerprint setup artifact.');
            }
            return hash('sha256', $mode . ':' . $hash);
        }
        if (!is_dir($path)) {
            return null;
        }
        $entries = [];
        $names = scandir($path);
        $mode = fileperms($path);
        if ($names === false || $mode === false) {
            throw new RuntimeException('Cannot fingerprint setup directory.');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $name === '.git') {
                continue;
            }
            $entries[$name] = $this->digest($path . '/' . $name);
        }

        return hash('sha256', json_encode([$mode, $entries], JSON_THROW_ON_ERROR));
    }

    private function option(string $key, string $fallback): string
    {
        $value = $this->config[$key]->unwrapOrFallback($fallback);
        if (!is_string($value)) {
            throw new RuntimeException('Invalid setup path option.');
        }

        return $value;
    }
}
