<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step\Builtin;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Process\SystemProcess;
use SymPress\Runtime\Step\ConditionalStepInterface;
use SymPress\Runtime\Step\OptionalStepInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\ExecutableFinder;

/** @internal */
final readonly class VcsIgnoreCheckStep implements ConditionalStepInterface, OptionalStepInterface
{
    public function __construct(private Io $io, private SystemProcess $process, private ExecutableFinder $executables, private FileContentBuilder $templates, private Filesystem $files, private ProjectBoundary $boundary)
    {
    }

    public function name(): string
    {
        return 'vcsignorecheck';
    }

    public function allowed(Config $config, Paths $paths): bool
    {
        return !$config['check-vcs-ignore']->is(false);
    }

    public function askConfirm(Config $config, Io $io): bool
    {
        return !$config['check-vcs-ignore']->is(self::ASK) || $io->askConfirm('Check VCS ignore rules for generated and sensitive paths?', true);
    }

    public function run(Config $config, Paths $paths): int
    {
        $targets = $this->targets($config, $paths);
        if ($targets === []) {
            return self::NONE;
        }
        $vcs = $this->detect($paths);
        if ($vcs === null) {
            $this->io->comment('VCS ignore status is unknown: no Git, Mercurial or Subversion metadata found.');

            return self::NONE;
        }
        if ($vcs !== 'svn' && !$this->createIgnore($config, $paths, $vcs, $targets)) {
            return self::ERROR;
        }
        if ($this->executables->find($vcs) === null) {
            $this->io->comment('VCS ignore status is unknown: ' . $vcs . ' executable is unavailable.');

            return self::NONE;
        }
        $unprotected = [];
        foreach ($targets as $target) {
            if ($this->ignored($vcs, $target, $paths->root())) {
                continue;
            }
            $unprotected[] = $target;
        }
        if ($unprotected !== []) {
            $this->io->error('VCS ignore protection could not be verified for: ' . implode(', ', $unprotected));

            return self::ERROR;
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function targets(Config $config, Paths $paths): array
    {
        $envDir = $config['env-dir']->unwrapOrFallback('.');
        $envFile = $config['env-file']->unwrapOrFallback('.env');
        $targets = [$paths->vendor(), $paths->wp(), $paths->wpContent(), $paths->root('wp-config.php'), $paths->wpParent('wp-config.php'), $paths->wpParent('index.php'), $paths->root('wp-cli.yml'), $paths->root('var/runtime'), $paths->root('.sympress-runtime.lock.guard')];
        if (is_string($envDir) && is_string($envFile)) {
            $base = Path::makeAbsolute($envDir, $paths->root());
            foreach ([$envFile, $envFile . '.local', ltrim(EnvReader::CACHE_DUMP_FILE, '/'), ltrim(EnvReader::BUILD_DUMP_FILE, '/')] as $file) {
                $targets[] = $base . '/' . $file;
            }
            foreach (['local', 'development', 'staging', 'production'] as $environment) {
                $targets[] = $base . '/' . $envFile . '.' . $environment;
                $targets[] = $base . '/' . $envFile . '.' . $environment . '.local';
            }
        }
        $bootstrap = $config['env-bootstrap-dir']->unwrapOrFallback('');
        if (is_string($bootstrap) && $bootstrap !== '') {
            foreach (['local', 'development', 'staging', 'production'] as $environment) {
                $targets[] = Path::makeAbsolute($bootstrap, $paths->root()) . '/' . $environment . '.php';
            }
        }
        $result = [];
        foreach ($targets as $target) {
            if ((!file_exists($target) && !is_link($target)) || !Path::isBasePath($paths->root(), $target)) {
                continue;
            }
            $relative = Path::makeRelative($target, $paths->root());
            if ($relative === '' || in_array($relative, $result, true)) {
                continue;
            }
            $result[] = $relative;
        }

        return $result;
    }

    private function detect(Paths $paths): ?string
    {
        foreach (['git', 'hg', 'svn'] as $vcs) {
            $metadata = $paths->root('.' . $vcs);
            if (is_dir($metadata) || ($vcs === 'git' && is_file($metadata))) {
                return $vcs;
            }
        }

        return null;
    }

    /** @param list<string> $targets */
    private function createIgnore(Config $config, Paths $paths, string $vcs, array $targets): bool
    {
        $filename = '.' . $vcs . 'ignore';
        $target = $paths->root($filename);
        if (file_exists($target) || is_link($target)) {
            return true;
        }
        $create = $config['create-vcs-ignore-file']->unwrapOrFallback(true);
        if ($create === false || ($create === self::ASK && !$this->io->askConfirm('Create ' . $filename . ' for generated and sensitive paths?', true))) {
            return true;
        }
        $patterns = [];
        if ($config['compatibility-profile']->is('native') && !in_array('wp-cli.yml', $targets, true)) {
            $targets[] = 'wp-cli.yml';
        }
        if (!in_array('.sympress-runtime.lock.guard', $targets, true)) {
            $targets[] = '.sympress-runtime.lock.guard';
        }
        foreach ($targets as $path) {
            $patterns[] = $vcs === 'hg'
                ? '^' . preg_quote($path, '~') . '(?:/|$)'
                : '/' . strtr($path, ['\\' => '\\\\', '*' => '\\*', '?' => '\\?', '[' => '\\[', ']' => '\\]', ' ' => '\\ ', '#' => '\\#', '!' => '\\!']);
        }
        $content = $this->templates->build($paths, $filename, ['WPSTARTER_IGNORED_PATHS' => implode("\n", $patterns)]);
        $this->boundary->assertWritablePath($target);

        return $this->files->save($content, $target);
    }

    private function ignored(string $vcs, string $path, string $root): bool
    {
        if ($vcs === 'git') {
            [$tracked, , $valid] = $this->process->executeCapturing(['git', 'ls-files', '-z', '--', $path], $root);

            return $valid && $tracked === '' && $this->process->executeSilently(['git', 'check-ignore', '-q', '--', $path], $root);
        }
        if ($vcs === 'hg') {
            [$status, , $valid] = $this->process->executeCapturing(['hg', 'status', '-A', '-0', '--', $path], $root);
            $entries = array_filter(explode("\0", $status));

            return $valid && $entries !== [] && array_all($entries, static fn (string $entry): bool => str_starts_with($entry, 'I '));
        }
        [$status, , $valid] = $this->process->executeCapturing(['svn', 'status', '--xml', '--no-ignore', '--depth', 'infinity', '--', $path], $root);
        preg_match_all('/<wc-status\b[^>]*\bitem="([^"]+)"/', $status, $entries);

        return $valid && $entries[1] !== [] && array_all($entries[1], static fn (string $entry): bool => $entry === 'ignored');
    }

    public function success(): string
    {
        return 'VCS ignore protection verified for all generated and sensitive paths.';
    }

    public function error(): string
    {
        return 'VCS ignore protection is incomplete or could not be verified.';
    }

    public function conditionsNotMet(): string
    {
        return 'VCS ignore checking is disabled.';
    }

    public function skipped(): string
    {
        return 'VCS ignore checking skipped.';
    }
}
