<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

use ReflectionClass;
use RuntimeException;
use SymPress\Runtime\Env\EnvReader;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Publishes immutable, scoped parser payloads; never loads Composer at request time. */
final readonly class RuntimeBundleBuilder
{
    public function __construct(private Paths $paths, private ProjectBoundary $boundary)
    {
    }

    public function build(): RuntimeBundle
    {
        $sources = $this->sources();
        ksort($sources);
        $fingerprint = hash('sha256', 'sympress-runtime-bundle:v1:' . $this->read(__FILE__) . json_encode($sources, JSON_THROW_ON_ERROR));
        $prefix = 'SymPress\\Runtime\\Embedded\\V' . $fingerprint;
        $replacements = [
            'SymPress\\Runtime\\Env' => $prefix . '\\Env',
            'Symfony\\Component\\Dotenv' => $prefix . '\\Dotenv',
            'Symfony\\Component\\Process' => $prefix . '\\Process',
        ];
        foreach ($sources as $file => $source) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $sources[$file] = strtr($source, $replacements);
        }
        $reader = $prefix . '\\Env\\EnvReader';
        $sources['bootstrap.php'] = $this->loader($prefix, $reader);
        $hashes = array_map(static fn (string $content): string => hash('sha256', $content), $sources);
        $sources['manifest.json'] = json_encode(['format' => 1, 'fingerprint' => $fingerprint, 'files' => $hashes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        $directory = $this->paths->root('var/runtime/' . $fingerprint);
        $this->boundary->assertWritablePath($directory);
        $bundle = new RuntimeBundle($directory . '/bootstrap.php', $reader, $fingerprint);
        if (is_dir($directory)) {
            $this->verify($directory, $sources);

            return $bundle;
        }
        $filesystem = new Filesystem();
        $parent = dirname($directory);
        $filesystem->mkdir($parent);
        $temporary = $parent . '/.build-' . bin2hex(random_bytes(12));
        $filesystem->mkdir($temporary, 0700);
        try {
            foreach ($sources as $file => $source) {
                $filesystem->dumpFile($temporary . '/' . $file, $source);
            }
            $filesystem->chmod($temporary, 0755);
            if (!@rename($temporary, $directory)) {
                // A concurrent builder may have published the same immutable payload.
                $this->verify($directory, $sources);
            }
        } finally {
            if (is_dir($temporary)) {
                $filesystem->remove($temporary);
            }
        }

        return $bundle;
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        $dotenv = dirname((string) (new ReflectionClass(Dotenv::class))->getFileName());
        $process = dirname((string) (new ReflectionClass(Process::class))->getFileName());
        $environment = dirname((string) (new ReflectionClass(EnvReader::class))->getFileName());
        $sources = [];
        foreach (['EnvReader', 'Filters', 'EnvironmentName', 'ConstantCatalog'] as $class) {
            $sources['Env/' . $class . '.php'] = $this->read($environment . '/' . $class . '.php');
        }
        $sources['Dotenv/Dotenv.php'] = $this->read($dotenv . '/Dotenv.php');
        foreach (glob($dotenv . '/Exception/*.php') ?: [] as $file) {
            $sources['Dotenv/Exception/' . basename($file)] = $this->read($file);
        }
        // Dotenv command substitutions use Process, including platform-specific pipes.
        foreach (['Process', 'ProcessUtils', 'ExecutableFinder'] as $class) {
            $sources['Process/' . $class . '.php'] = $this->read($process . '/' . $class . '.php');
        }
        foreach (['Exception', 'Pipes'] as $part) {
            foreach (glob($process . '/' . $part . '/*.php') ?: [] as $file) {
                if (basename($file) === 'RunProcessFailedException.php') {
                    continue;
                }
                $sources['Process/' . $part . '/' . basename($file)] = $this->read($file);
            }
        }
        $sources['Dotenv/LICENSE'] = $this->read($dotenv . '/LICENSE');
        $sources['Process/LICENSE'] = $this->read($process . '/LICENSE');
        $sources['LICENSE'] = $this->read(dirname(__DIR__, 2) . '/LICENSE');
        $sources['NOTICE'] = $this->read(dirname(__DIR__, 2) . '/NOTICE');

        return $sources;
    }

    private function read(string $file): string
    {
        $content = file_get_contents($file);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Cannot read a required runtime payload source.');
        }

        return $content;
    }

    /** @param array<string, string> $sources */
    private function verify(string $directory, array $sources): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Runtime payload target is unavailable or has an unexpected type.');
        }
        foreach ($sources as $file => $expected) {
            $path = $directory . '/' . $file;
            $this->boundary->assertWritablePath($path);
            if (is_link($path) || !is_file($path) || !hash_equals(hash('sha256', $expected), (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Existing runtime payload differs from its immutable build.');
            }
        }
    }

    private function loader(string $prefix, string $reader): string
    {
        $prefixLiteral = var_export($prefix . '\\', true);
        $readerLiteral = var_export($reader, true);

        return "<?php\n// @generated by sympress/runtime bootstrap:v1\n"
            . 'spl_autoload_register(static function (string $class): void {' . "\n"
            . '    $prefix = ' . $prefixLiteral . ";\n"
            . '    if (!str_starts_with($class, $prefix)) { return; }' . "\n"
            . '    $relative = substr($class, strlen($prefix));' . "\n"
            . '    if (!preg_match(\'/^[A-Za-z0-9_\\\\\\\\]+$/D\', $relative)) { return; }' . "\n"
            . '    $file = __DIR__ . "/" . str_replace("\\\\", "/", $relative) . ".php";' . "\n"
            . '    if (is_file($file)) { require $file; }' . "\n"
            . "});\nreturn " . $readerLiteral . ";\n";
    }
}
