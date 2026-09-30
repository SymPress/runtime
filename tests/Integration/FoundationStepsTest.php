<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Step\Builtin\CheckPathsStep;
use SymPress\Runtime\Step\Builtin\FlushEnvCacheStep;
use SymPress\Runtime\Step\Builtin\IndexStep;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

final class FoundationStepsTest extends TemporaryProject
{
    private function paths(string $wordpress = 'public/wp'): Paths
    {
        return new Paths($this->root, wp: $wordpress, content: 'public/content');
    }

    /** @param array<string, mixed> $values */
    private function config(Paths $paths, array $values = []): Config
    {
        return new Config($values, new Validator($paths));
    }

    #[Group('PAR-STEP-001')]
    public function testCheckPathsCreatesContentAndDiagnosesMissingFilesAndPublicEnvironment(): void
    {
        $paths = $this->paths();
        $step = new CheckPathsStep(new Filesystem(), new ProjectBoundary($paths));
        $config = $this->config($paths, ['env-dir' => 'public/env']);
        self::assertSame(StepInterface::ERROR, $step->run($config, $paths));
        self::assertStringContainsString('autoload.php', $step->error());
        self::assertStringContainsString('wp-settings.php', $step->error());
        self::assertDirectoryExists($paths->wpContent('themes'));
        self::assertDirectoryExists($paths->wpContent('plugins'));
        $this->write('vendor/autoload.php', '<?php');
        $this->write('public/wp/wp-settings.php', '<?php');
        self::assertSame(StepInterface::SUCCESS, $step->run($config, $paths));
        $output = new BufferedOutput();
        $step->postProcess(new Io(new ArrayInput([]), $output));
        self::assertStringContainsString('inside the webroot', $output->fetch());
    }

    #[Group('PAR-STEP-001')]
    public function testContentCreationRejectsEscapingSymlinkBeforeWriting(): void
    {
        $outside = sys_get_temp_dir() . '/sympress-outside-' . bin2hex(random_bytes(6));
        mkdir($outside);
        $this->write('public/keep', 'fixture');
        self::assertTrue(symlink($outside, $this->root . '/public/content'));
        $paths = $this->paths();
        try {
            $step = new CheckPathsStep(new Filesystem(), new ProjectBoundary($paths));
            try {
                $step->run($this->config($paths), $paths);
                self::fail('Escaping content links must fail.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('outside the project', $error->getMessage());
                self::assertSame(['.', '..'], scandir($outside));
            }
        } finally {
            rmdir($outside);
        }
    }

    #[Group('PAR-STEP-003')]
    #[Group('PAR-TPL-002')]
    public function testIndexExecutesTheCoreFrontControllerWithQuotedPaths(): void
    {
        $paths = $this->paths("public/word'press");
        $this->write("public/word'press/index.php", '<?php echo "core-front-controller";');
        $step = new IndexStep(new Filesystem(), new FileContentBuilder(), new ProjectBoundary($paths));
        self::assertTrue($step->allowed($this->config($paths), $paths));
        self::assertSame(StepInterface::SUCCESS, $step->run($this->config($paths), $paths));
        $first = file_get_contents($paths->wpParent('index.php'));
        $process = new Process([PHP_BINARY, $paths->wpParent('index.php')], $this->root);
        $process->mustRun();
        self::assertSame('core-front-controller', $process->getOutput());
        self::assertSame(StepInterface::SUCCESS, $step->run($this->config($paths), $paths));
        self::assertSame($first, file_get_contents($paths->wpParent('index.php')));
        $flat = $this->paths('.');
        self::assertFalse($step->allowed($this->config($flat), $flat));
    }

    #[Group('PAR-STEP-004')]
    #[Group('PAR-ENV-022')]
    public function testFlushOnlyRemovesTheConfiguredCacheAndNeverRecursesIntoADirectory(): void
    {
        $paths = $this->paths();
        $config = $this->config($paths, ['env-dir' => 'configuration']);
        $step = new FlushEnvCacheStep(new Filesystem());
        self::assertFalse($step->allowed($config, $paths));
        $this->write('configuration/.env.cached.php', '<?php return [];');
        $this->write('configuration/.env.dump.php', '<?php return [];');
        $this->write('.env.cached.php', 'other environment');
        self::assertTrue($step->allowed($config, $paths));
        self::assertSame(StepInterface::SUCCESS, $step->run($config, $paths));
        self::assertFileDoesNotExist($this->root . '/configuration/.env.cached.php');
        self::assertFileExists($this->root . '/configuration/.env.dump.php');
        self::assertFileExists($this->root . '/.env.cached.php');
        $this->write('configuration/.env.cached.php/keep', 'user data');
        self::assertSame(StepInterface::ERROR, $step->run($config, $paths));
        self::assertSame('user data', file_get_contents($this->root . '/configuration/.env.cached.php/keep'));
    }

    #[Group('PAR-ENV-022')]
    public function testStandaloneFlushWorksWithoutWordPressAndPreservesSymlinkTargets(): void
    {
        $this->write('composer.json', '{}');
        $this->write('keep', 'user data');
        self::assertTrue(symlink($this->root . '/keep', $this->root . '/.env.cached.php'));
        $package = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $package . '/bin/sympress-runtime', 'flush-env-cache'], $this->root, ['COMPOSER_VENDOR_DIR' => $package . '/vendor']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFalse(is_link($this->root . '/.env.cached.php'));
        self::assertSame('user data', file_get_contents($this->root . '/keep'));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
