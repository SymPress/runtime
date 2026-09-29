<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\Builtin\EnvExampleStep;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class DefaultArtifactStepsTest extends TemporaryProject
{
    /** @param array<string, mixed> $settings */
    private function fixture(array $settings = [], string $wordpress = 'public/wp'): void
    {
        $this->write('composer.json', json_encode(['extra' => ['wordpress-install-dir' => $wordpress, 'wordpress-content-dir' => 'public/content', 'sympress-runtime' => array_replace(['require-wp' => false, 'db-check' => false], $settings)]], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $steps */
    private function generate(array $steps): Process
    {
        $package = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', '-n', ...$steps], $this->root, ['COMPOSER_VENDOR_DIR' => $package . '/vendor', 'COMPOSER' => false]);
        $process->run();

        return $process;
    }

    #[Group('PAR-STEP-005')]
    #[Group('PAR-MU-003')]
    public function testMuLoaderHandlesQuotedCommaPathsMissingFilesAndRequireOnce(): void
    {
        $this->fixture();
        self::assertSame(0, $this->generate(['muloader'])->getExitCode());
        $loader = $this->root . '/public/content/mu-plugins/sympress-runtime-mu-loader.php';
        self::assertFileDoesNotExist($loader);
        $this->write("public/content/mu-plugins/plugin, 'one/main.php", "<?php\n// Plugin Name: First\n\$GLOBALS['first'] = (\$GLOBALS['first'] ?? 0) + 1;");
        $this->write('public/content/mu-plugins/second/main.php', "<?php\n// Plugin Name: Second\nthrow new RuntimeException('removed entry must be skipped');");
        $created = $this->generate(['muloader']);
        self::assertSame(0, $created->getExitCode(), $created->getErrorOutput());
        $before = file_get_contents($loader);
        touch($loader, 1000000000);
        self::assertSame(0, $this->generate(['muloader'])->getExitCode());
        clearstatcache();
        self::assertSame($before, file_get_contents($loader));
        self::assertSame(1000000000, filemtime($loader));
        unlink($this->root . '/public/content/mu-plugins/second/main.php');
        $probe = 'function wp_normalize_path($path) { return str_replace("\\\\", "/", $path); } require ' . var_export($loader, true) . '; require ' . var_export($loader, true) . '; echo json_encode([$GLOBALS["first"], isset($runtimeMuPlugin), isset($runtimeMuPath)]);';
        $runtime = new Process([PHP_BINARY, '-r', $probe], $this->root);
        $runtime->mustRun();
        self::assertSame([1, false, false], json_decode($runtime->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('legacyLoaderVariants')]
    #[Group('PAR-SYM-010')]
    #[Group('PAR-TPL-003')]
    public function testMigrationRetiresOnlyTheExactGeneratedLegacyMuLoader(bool $custom, bool $plugins): void
    {
        $this->fixture(['compatibility' => false]);
        $content = str_replace('{{{MU_PLUGINS_LIST}}}', 'first/main.php', (string) file_get_contents(dirname(__DIR__, 2) . '/resources/legacy-mu-loader.php.txt'));
        if ($custom) {
            $content .= "\n// User customization must survive.\n";
        }
        $relative = 'public/content/mu-plugins/wpstarter-mu-loader.php';
        $this->write($relative, $content);
        if ($plugins) {
            $this->write('public/content/mu-plugins/first/main.php', "<?php\n// Plugin Name: First\n\$GLOBALS['legacy_migrated_boots'] = (\$GLOBALS['legacy_migrated_boots'] ?? 0) + 1;");
        }
        $result = $this->generate(['muloader']);
        $legacy = $this->root . '/' . $relative;
        $native = dirname($legacy) . '/sympress-runtime-mu-loader.php';
        if ($custom) {
            self::assertNotSame(0, $result->getExitCode());
            self::assertSame($content, file_get_contents($legacy));
            self::assertFileDoesNotExist($native);
            self::assertFileDoesNotExist($legacy . '.sympress-backup');

            return;
        }
        self::assertSame(0, $result->getExitCode(), $result->getOutput() . $result->getErrorOutput());
        self::assertFileDoesNotExist($legacy);
        self::assertSame($content, file_get_contents($legacy . '.sympress-backup'));
        self::assertSame(0, $this->generate(['muloader'])->getExitCode());
        $probe = 'function wp_normalize_path($path) { return $path; } foreach (glob(' . var_export(dirname($native) . '/*.php', true) . ') as $file) { require $file; } echo $GLOBALS["legacy_migrated_boots"] ?? 0;';
        $runtime = new Process([PHP_BINARY, '-r', $probe], $this->root);
        $runtime->mustRun();
        self::assertSame($plugins ? '1' : '0', $runtime->getOutput());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function legacyLoaderVariants(): iterable
    {
        yield 'generated loader with plugin' => [false, true];
        yield 'generated loader without remaining plugins' => [false, false];
        yield 'custom loader with plugin' => [true, true];
        yield 'custom loader without discovered plugins' => [true, false];
    }

    #[Group('PAR-STEP-006')]
    #[Group('PAR-OPT-016')]
    #[Group('PAR-TPL-001')]
    public function testEnvironmentExampleUsesConfiguredDirectoryAndSkipsExistingEnvironment(): void
    {
        $this->fixture(['env-dir' => 'configuration', 'env-file' => 'site.env', 'env-example' => 'ask']);
        $created = $this->generate(['envexample']);
        self::assertSame(0, $created->getExitCode(), $created->getErrorOutput());
        $example = $this->root . '/configuration/.env.example';
        $content = file_get_contents($example);
        self::assertStringContainsString('WP_ENVIRONMENT_TYPE=development', $content);
        self::assertFileDoesNotExist($this->root . '/.env.example');
        $this->write('configuration/site.env', 'DB_NAME=existing');
        $this->write('custom/example.env', 'DB_NAME=replacement');
        $this->fixture(['env-dir' => 'configuration', 'env-file' => 'site.env', 'env-example' => 'custom/example.env']);
        self::assertSame(0, $this->generate(['envexample', '--force'])->getExitCode());
        self::assertSame($content, file_get_contents($example));
        unlink($this->root . '/configuration/site.env');
        self::assertSame(0, $this->generate(['envexample'])->getExitCode());
        self::assertStringEndsWith('DB_NAME=replacement', file_get_contents($example));
        $this->fixture(['env-example' => false]);
        self::assertSame(0, $this->generate(['envexample'])->getExitCode());
        self::assertFileDoesNotExist($this->root . '/.env.example');
    }

    #[Group('PAR-STEP-006')]
    #[Group('PAR-OPT-016')]
    public function testRemoteExampleVerifiesChecksumAndPreservesExistingContentOnFailure(): void
    {
        $paths = new Paths($this->root);
        $url = 'https://example.test/example.env';
        $config = new Config(['env-example' => $url, 'download-checksums' => [$url => hash('sha256', '')]], new Validator($paths));
        $files = new Filesystem();
        $step = new EnvExampleStep($config, $files, new UrlDownloader(new MockHttpClient(new MockResponse('')), $files, $config));
        self::assertSame(StepInterface::SUCCESS, $step->run($config, $paths));
        $target = $this->root . '/.env.example';
        $before = file_get_contents($target);
        $failed = new EnvExampleStep($config, $files, new UrlDownloader(new MockHttpClient(new MockResponse('different')), $files, $config));
        self::assertSame(StepInterface::ERROR, $failed->run($config, $paths));
        self::assertStringContainsString('SHA256', $failed->error());
        self::assertSame($before, file_get_contents($target));
    }

    #[Group('PAR-STEP-011')]
    public function testWpCliYamlEscapesPathsAndExecSetsTheActualConfigTarget(): void
    {
        rename($this->root, $this->root . "-'quoted");
        $this->root .= "-'quoted";
        $this->fixture(wordpress: "public/word'press");
        $created = $this->generate(['wpcliconfig']);
        self::assertSame(0, $created->getExitCode(), $created->getErrorOutput());
        $file = $this->root . '/wp-cli.yml';
        $content = file_get_contents($file);
        preg_match('/^path: (.+)$/m', $content, $path);
        preg_match('/^exec: (.+)$/m', $content, $exec);
        self::assertSame("public/word'press", json_decode($path[1], flags: JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, '-r', json_decode($exec[1], flags: JSON_THROW_ON_ERROR) . 'echo getenv("WP_CONFIG_PATH");'], $this->root);
        $process->mustRun();
        self::assertSame($this->root . '/wp-config.php', $process->getOutput());
        self::assertSame(0, $this->generate(['wpcliconfig'])->getExitCode());
        self::assertSame($content, file_get_contents($file));
        $this->write('wp-cli.yml', 'path: user-owned');
        self::assertSame(0, $this->generate(['wpcliconfig'])->getExitCode());
        self::assertSame('path: user-owned', file_get_contents($file));
        self::assertSame(0, $this->generate(['wpcliconfig', '--force'])->getExitCode());
        self::assertSame($content, file_get_contents($file));
    }

    #[Group('PAR-STEP-006')]
    #[Group('PAR-STEP-011')]
    public function testReleaseProfileKeepsRootExampleAndPathOnlyWpCliConfiguration(): void
    {
        $this->write('configuration/keep', 'fixture');
        $this->fixture(['compatibility-profile' => 'release-3.0.1', 'env-dir' => 'configuration']);
        $generated = $this->generate(['envexample', 'wpcliconfig']);
        self::assertSame(0, $generated->getExitCode(), $generated->getErrorOutput());
        self::assertFileExists($this->root . '/.env.example');
        self::assertFileDoesNotExist($this->root . '/configuration/.env.example');
        self::assertStringNotContainsString('exec:', file_get_contents($this->root . '/wp-cli.yml'));
    }
}
