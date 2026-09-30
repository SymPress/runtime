<?php

declare(strict_types=1);

namespace SymPress\Runtime\Compatibility;

use InvalidArgumentException;
use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Config\Options;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\ArtifactWriter;
use Symfony\Component\Filesystem\Path;

final readonly class Migration
{
    public function __construct(private RunContext $context, private Paths $paths)
    {
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{target: string, status: string, ready: bool, profile: string, provenance: array<string, string>, findings: list<array{file: string, line: int, symbol: string, replacement: string, guidance: string}>, next: list<string>}
     */
    public function run(array $extra, string $output = 'sympress-runtime.json', bool $force = false, bool $dryRun = false): array
    {
        $loader = new ConfigLoader();
        $loaded = $loader->load($this->context->root, $extra);
        $values = $loaded->values;
        $provenance = $loaded->provenance;
        // Preserve profile-dependent defaults even after the old source is removed.
        foreach (Options::defaults($loaded->profile) as $key => $value) {
            if (in_array($key, Options::INTERNAL, true) || $value === Options::DEFAULTS[$key] || array_key_exists($key, $values)) {
                continue;
            }
            $values[$key] = $value;
            $provenance[$key] = $loaded->profile . ' default';
        }
        if (filter_var($values['skip-db-check'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $values['db-check'] = false;
            $provenance['db-check'] = ($provenance['skip-db-check'] ?? 'legacy') . ' (skip-db-check)';
        }
        unset($values['skip-db-check']);
        $errors = (new Config($values, new Validator($this->paths, $loaded->profile), $loaded->profile))->errors();
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid migration options: ' . implode(', ', array_keys($errors)) . '.');
        }
        ksort($values);
        $content = json_encode($values, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $target = Path::makeAbsolute($output, $this->context->root);
        $boundary = new ProjectBoundary($this->paths);
        $boundary->assertWritablePath($target);
        $sources = [$this->context->root . '/wpstarter.json', $this->context->manifestPath()];
        if (is_string($extra['wpstarter'] ?? null)) {
            $sources[] = Path::makeAbsolute(ltrim($extra['wpstarter'], '/'), $this->context->root);
        }
        foreach ($sources as $source) {
            if (Path::canonicalize($source) === $target || (is_file($source) && realpath($source) === realpath($target))) {
                throw new InvalidArgumentException('Migration must retain the original configuration; choose a new target.');
            }
        }
        if (is_link($target) || is_dir($target)) {
            throw new InvalidArgumentException('Migration target must be a regular file, never a symlink or directory.');
        }
        $same = is_file($target) && file_get_contents($target) === $content;
        if (is_file($target) && !$same && !$force) {
            throw new InvalidArgumentException('Migration target differs; review it and use --force to replace it.');
        }
        $scan = [$this->context->root];
        $autoload = $values['autoload'] ?? Options::defaults($loaded->profile)['autoload'];
        if (is_string($autoload)) {
            $scan[] = Path::makeAbsolute($autoload, $this->context->root);
        }
        foreach (['wp-cli-files', 'wp-cli-commands', 'templates-dir'] as $option) {
            $entries = $values[$option] ?? [];
            foreach (is_array($entries) ? $entries : [$entries] as $entry) {
                if (!is_string($entry) || str_contains($entry, "\0")) {
                    continue;
                }
                $scan[] = Path::makeAbsolute($entry, $this->context->root);
            }
        }
        // Read JSON metadata only; installed.php and Composer's autoload files are executable.
        $installed = $this->context->vendor . '/composer/installed.json';
        if (is_file($installed)) {
            $metadata = $loader->readObject($installed);
            $packages = $metadata['packages'] ?? [];
            foreach (is_array($packages) ? $packages : [] as $package) {
                if (!is_array($package) || !in_array($package['type'] ?? null, ['wpstarter-extension', 'sympress-runtime-extension'], true) || !is_string($package['install-path'] ?? null)) {
                    continue;
                }
                $scan[] = Path::makeAbsolute($package['install-path'], dirname($installed));
            }
        }
        $findings = (new PhpMigrationAnalyzer())->analyze($scan, [Path::makeRelative($this->paths->wp(), $this->context->root)]);
        if (!$dryRun && !$same && !(new ArtifactWriter($boundary))->write($target, $content)) {
            throw new RuntimeException('Cannot write migrated configuration.');
        }

        return [
            'target' => $target,
            'status' => $same ? 'unchanged' : ($dryRun ? 'would-write' : 'written'),
            'ready' => $findings === [],
            'profile' => $loaded->profile,
            'provenance' => $provenance,
            'findings' => $findings,
            'next' => [
                'wp-config-autoload preserves early project Composer autoload in legacy profiles. Keep it enabled when environment PHP files, early hooks or plugins need vendor classes or Composer files hooks; native defaults to false.',
                'Review PHP findings, custom templates and generated files; dynamic references require manual inspection.',
                'Remove extra.wpstarter and archive wpstarter.json after review; originals are retained by this command.',
                'Use extra.sympress-runtime for a non-default output path; option paths remain relative to the project root.',
                'Replace wecodemore/wpstarter and its allow-plugins entry with sympress/runtime, then update the lockfile.',
                'Regenerate configuration and MU loader, validate, and run doctor before deployment.',
            ],
        ];
    }
}
