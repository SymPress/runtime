<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Generation\SectionMerger;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ProductionDoctorTest extends TemporaryProject
{
    private function fixture(): void
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('composer.json', json_encode(['extra' => ['sympress-runtime' => ['wordpress-parent-dir' => 'public']]], JSON_THROW_ON_ERROR));
        $this->write('wordpress/wp-load.php', '<?php // never executed');
        $this->write('public/.keep', '');
        $this->write('wp-content/.keep', '');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nWP_HOME=https://example.test\nDB_NAME=fixture\nDB_USER=fixture\nDB_HOST=localhost\nDB_PASSWORD=private-credential\nWPDB_ENV_VALID=true\nWPDB_EXISTS=true\nWP_INSTALLED=true\n");
        chmod($this->root . '/.env', 0600);
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/wp-config.php');
        self::assertIsString($template);
        $sections = (new SectionMerger())->sections($template);
        $config = "<?php\n";
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $config .= "defined('" . $name . "') || define('" . $name . "', '" . str_repeat($name, 10) . "');\n";
        }
        foreach (['DEFAULT_ENV', 'COMPOSER_MANAGED'] as $name) {
            $config .= $name . ' : {' . strtr($sections[$name], ['{{{COMPOSER_MANAGED}}}' => "'auto'", '{{{PROFILE}}}' => "'native'"]) . '} #@@/' . $name . "\n";
        }
        $this->write('wp-config.php', $config);
        chmod($this->root . '/wp-config.php', 0600);
        $this->dump();
    }

    private function dump(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        (new Process([PHP_BINARY, '-r', 'require ' . var_export($autoload, true) . '; $r = new SymPress\\Runtime\\Env\\EnvReader(); $r->loadChain(); $r->setupConstants(); $r->dumpCached(".env.dump.php", immutable: true);'], $this->root))->mustRun();
    }

    /** @return array{int|null, array<string, string>, string} */
    private function diagnose(string ...$arguments): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/sympress-runtime', '-n', 'doctor', '--production', '--json', '--webroot=public', ...$arguments], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false]);
        $process->run();
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['checks']);
        /** @var list<array{id: string, status: string}> $checks */
        $checks = $report['checks'];

        return [$process->getExitCode(), array_column($checks, 'status', 'id'), $process->getOutput() . $process->getErrorOutput()];
    }

    public function testGeneratedDefaultsPassWithoutExecutingConfigOrLeakingSecrets(): void
    {
        $this->fixture();
        $this->write('.env.example', 'DB_NAME=example');
        chmod($this->root . '/.env.example', 0644);
        [$exit, $checks, $output] = $this->diagnose();
        self::assertSame(0, $exit, $output);
        foreach (['production.home', 'production.debug', 'production.debug-display', 'production.file-mods', 'production.auto-updates', 'production.salts'] as $name) {
            self::assertSame('pass', $checks[$name]);
        }
        self::assertFileDoesNotExist($this->root . '/executed');
        self::assertStringNotContainsString('private-credential', $output);
    }

    public function testBadHttpsDebugAndExplicitHardeningOverridesFail(): void
    {
        $this->fixture();
        file_put_contents($this->root . '/.env', "WP_HOME=http://example.test\nWP_DEBUG=true\nWP_DEBUG_DISPLAY=true\nDISALLOW_FILE_MODS=false\n", FILE_APPEND);
        $this->dump();
        [$exit, $checks] = $this->diagnose();
        self::assertSame(1, $exit);
        foreach (['production.home', 'production.debug', 'production.debug-display', 'production.file-mods'] as $name) {
            self::assertSame('fail', $checks[$name]);
        }
    }

    public function testMissingDumpPublicSecretsAndInsecurePermissionsFail(): void
    {
        $this->fixture();
        unlink($this->root . '/.env.dump.php');
        chmod($this->root . '/.env', 0644);
        [$exit, $checks] = $this->diagnose('--webroot=.');
        self::assertSame(1, $exit);
        self::assertSame('fail', $checks['production.dump']);
        self::assertSame('fail', $checks['production.env-mode.0']);
        self::assertSame('fail', $checks['production.env-location.0']);
    }

    public function testCommentedOrDefaultSaltsDoNotCountAsPrivateKeys(): void
    {
        $this->fixture();
        $this->write('wp-config.php', "<?php\n/* define('AUTH_KEY', '" . str_repeat('a', 64) . "'); */\ndefine('AUTH_KEY', 'put your unique phrase here');\n");
        [$exit, $checks] = $this->diagnose();
        self::assertSame(1, $exit);
        self::assertSame('fail', $checks['production.salts']);
    }

    public function testCommandSubstitutionCannotExecuteWithOrWithoutDump(): void
    {
        $this->fixture();
        file_put_contents($this->root . '/.env', 'EVIL=$(touch ' . $this->root . "/shell-executed)\n", FILE_APPEND);
        [$exit, $checks] = $this->diagnose();
        self::assertSame(1, $exit);
        self::assertSame('fail', $checks['production.commands']);
        unlink($this->root . '/.env.dump.php');
        [$exit] = $this->diagnose();
        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($this->root . '/shell-executed');
    }

    /** @return iterable<string, array{string}> */
    public static function customEnvironmentNames(): iterable
    {
        yield 'PHP suffix' => ['secrets.php'];
        yield 'example suffix' => ['secrets.example'];
        yield 'default example basename' => ['.env.example'];
    }

    #[DataProvider('customEnvironmentNames')]
    public function testCommandScanIncludesEveryExplicitDotenvFilename(string $name): void
    {
        $this->fixture();
        unlink($this->root . '/.env.dump.php');
        $this->write('composer.json', json_encode(['extra' => ['sympress-runtime' => ['env-file' => $name]]], JSON_THROW_ON_ERROR));
        $this->write($name, 'ATTACK=$(touch ' . $this->root . "/shell-executed)\n");
        [$exit, $checks] = $this->diagnose();
        self::assertSame(1, $exit);
        self::assertSame('fail', $checks['environment.commands']);
        self::assertFileDoesNotExist($this->root . '/shell-executed');
    }

    public function testCommandScanTreatsEnvironmentDirectoryAsLiteralPath(): void
    {
        $this->fixture();
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"env-dir":"config/[secrets]"}}}');
        $this->write('config/[secrets]/.env', 'ATTACK=$(touch ' . $this->root . "/shell-executed)\n");
        [$exit, $checks] = $this->diagnose();
        self::assertSame(1, $exit);
        self::assertSame('fail', $checks['environment.commands']);
        self::assertFileDoesNotExist($this->root . '/shell-executed');
    }

    public function testUnlistableTraversableEnvironmentDirectoryFailsBeforeParsing(): void
    {
        $this->fixture();
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"env-dir":"secrets"}}}');
        $this->write('secrets/.env', 'ATTACK=$(touch ' . $this->root . "/shell-executed)\n");
        $directory = $this->root . '/secrets';
        chmod($directory, 0111);
        try {
            self::assertTrue(is_readable($directory . '/.env'));
            // The CLI must fail closed when directory enumeration cannot establish scan coverage.
            [$exit, $checks] = $this->diagnose();
            self::assertSame(1, $exit);
            self::assertSame('fail', $checks['runtime-inspection']);
            self::assertFileDoesNotExist($this->root . '/shell-executed');
        } finally {
            chmod($directory, 0755);
        }
    }

    public function testEarlyLiteralDefinesOverrideEnvironmentAndUnchangedDefaultSections(): void
    {
        $this->fixture();
        file_put_contents($this->root . '/.env', "WP_DEBUG=false\nDISALLOW_FILE_MODS=true\n", FILE_APPEND);
        $this->dump();
        $file = $this->root . '/wp-config.php';
        $source = file_get_contents($file);
        self::assertIsString($source);
        file_put_contents($file, str_replace('<?php', "<?php define('WP_DEBUG', true); define('DISALLOW_FILE_MODS', false);", $source));
        [$exit, $checks] = $this->diagnose();
        self::assertSame(1, $exit);
        self::assertSame('fail', $checks['production.debug']);
        self::assertSame('fail', $checks['production.file-mods']);
    }

    /** @return iterable<string, array{string}> */
    public static function opaqueBootstrapCases(): iterable
    {
        foreach (['prefix', 'section', 'keys-section', 'between-sections', 'hook', 'environment-bootstrap', 'autoload'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('opaqueBootstrapCases')]
    public function testOpaqueBootstrapRequiresRuntimeEvidenceAndIsNotExecuted(string $case): void
    {
        $this->fixture();
        $file = $this->root . '/wp-config.php';
        $source = file_get_contents($file);
        self::assertIsString($source);
        $sideEffect = "file_put_contents(__DIR__ . '/executed', 'never');";
        if ($case === 'prefix') {
            file_put_contents($file, str_replace('<?php', '<?php ' . $sideEffect, $source));
        }
        if ($case === 'section') {
            file_put_contents($file, str_replace('DEFAULT_ENV : {', 'EARLY_HOOKS : { ' . $sideEffect . " } #@@/EARLY_HOOKS\nDEFAULT_ENV : {", $source));
        }
        if ($case === 'keys-section') {
            file_put_contents($file, str_replace('DEFAULT_ENV : {', "KEYS : { define('WP_DEBUG', true); } #@@/KEYS\nDEFAULT_ENV : {", $source));
        }
        if ($case === 'between-sections') {
            file_put_contents($file, str_replace('COMPOSER_MANAGED : {', "define('DISALLOW_FILE_MODS', false);\nCOMPOSER_MANAGED : {", $source));
        }
        if ($case === 'hook') {
            $this->write('early.php', '<?php ' . $sideEffect);
            $this->write('composer.json', '{"extra":{"sympress-runtime":{"early-hook-file":"early.php"}}}');
        }
        if ($case === 'environment-bootstrap') {
            $this->write('production.php', '<?php ' . $sideEffect);
        }
        if ($case === 'autoload') {
            $this->write('composer.json', '{"extra":{"sympress-runtime":{"wp-config-autoload":true}}}');
        }
        [$exit, $checks, $output] = $this->diagnose();
        self::assertSame(2, $exit, $output);
        foreach (['production.bootstrap', 'production.debug', 'production.file-mods', 'production.auto-updates'] as $name) {
            self::assertSame('unknown', $checks[$name]);
        }
        self::assertFileDoesNotExist($this->root . '/executed');
    }

    public function testCompleteGeneratedConfigurationRemainsStaticallyVerifiable(): void
    {
        $this->fixture();
        $this->write('composer.json', '{"extra":{"sympress-runtime":{"require-wp":false}}}');
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/sympress-runtime', '-n', 'wpconfig', '--force'], $this->root, ['COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => dirname(__DIR__, 2) . '/vendor']);
        $process->mustRun();
        [$exit, $checks, $output] = $this->diagnose();
        self::assertSame(0, $exit, $output);
        self::assertSame('pass', $checks['production.bootstrap']);
    }

    public function testActiveFilesRespectProfileProcessPrecedenceAndTestLocalRules(): void
    {
        $this->write('.env', "WP_ENVIRONMENT_TYPE=staging\nWP_ENV=production\n");
        foreach (['local', 'staging', 'staging.local', 'production', 'test', 'test.local', 'inactive'] as $suffix) {
            $this->write('.env.' . $suffix, "RTV_VALUE=fixture\n");
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . '; $paths = new SymPress\\Runtime\\Filesystem\\Paths(getcwd()); $config = new SymPress\\Runtime\\Config\\Config([], new SymPress\\Runtime\\Config\\Validator($paths), $argv[1]); echo json_encode(array_map("basename", (new SymPress\\Runtime\\Console\\EnvironmentFiles($config, $paths))->activeFiles()));';
        foreach ([['native', false, ['.env', '.env.local', '.env.staging', '.env.staging.local']], ['release-3.0.1', false, ['.env', '.env.production']], ['native', 'test', ['.env', '.env.test', '.env.test.local']]] as [$profile, $environment, $expected]) {
            $process = new Process([PHP_BINARY, '-r', $script, $profile], $this->root, ['WP_ENVIRONMENT_TYPE' => $environment, 'WP_ENV' => false, 'WORDPRESS_ENV' => false]);
            $process->mustRun();
            self::assertSame($expected, json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function testPhpIdentityChecksParentTraversal(): void
    {
        $this->fixture();
        $user = posix_getpwuid(posix_geteuid());
        self::assertIsArray($user);
        [$exit, $checks, $output] = $this->diagnose('--php-user=' . $user['name']);
        self::assertSame(0, $exit, $output);
        self::assertSame('pass', $checks['production.readable.0']);
        chmod($this->root, 0700);
        chmod($this->root . '/.env', 0644);
        [$exit, $checks] = $this->diagnose('--php-user=nobody');
        self::assertSame(1, $exit);
        self::assertSame('fail', $checks['production.readable.0']);
    }

    public function testSymlinkArtifactAndUnknownPhpIdentityDoNotPass(): void
    {
        $this->fixture();
        rename($this->root . '/.env.dump.php', $this->root . '/secret-dump');
        symlink($this->root . '/secret-dump', $this->root . '/.env.dump.php');
        [$exit, $checks] = $this->diagnose('--php-user=no-such-sympress-user');
        self::assertSame(1, $exit);
        self::assertContains('fail', array_filter($checks, static fn (string $id): bool => str_starts_with($id, 'production.env-symlink.'), ARRAY_FILTER_USE_KEY));
        self::assertSame('unknown', $checks['production.readable.0']);
    }
}
