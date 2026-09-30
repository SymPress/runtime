<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Application\ContainerFactory;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Process\PhpProcess;
use SymPress\Runtime\Process\SystemProcess;
use SymPress\Runtime\Services;
use SymPress\Runtime\Step\Registry;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ServicesTest extends TemporaryProject
{
    #[Group('PAR-SVC-001')]
    #[Group('PAR-SVC-004')]
    #[Group('PAR-SVC-005')]
    #[Group('PAR-SVC-006')]
    #[Group('PAR-SVC-018')]
    public function testInjectedServiceAdaptersPreserveConfigurationIoPathsAndExecutableDiscovery(): void
    {
        $this->write('composer.json', '{"config":{"preferred-install":"dist"}}');
        $paths = new Paths($this->root, $this->root . '/deps', $this->root . '/tools');
        $config = new Config(['custom' => 4, 'cache-env' => 'invalid'], new Validator($paths));
        $output = new BufferedOutput();
        $io = new Io(new ArrayInput([]), $output);
        $context = new RunContext($this->root, $paths->vendor(), $paths->bin());
        $services = (new ContainerFactory())->create($config, $paths, $io, $context, new Registry())->get(Services::class);
        self::assertInstanceOf(Services::class, $services);
        self::assertSame($config, $services->config());
        $services->config()->appendValidator('custom', static fn (int $value): int => $value * 2);
        self::assertSame(8, $services->config()['custom']->unwrap());
        self::assertArrayHasKey('cache-env', $services->config()->errors());
        $services->composerIo()->write('through adapter');
        self::assertSame("through adapter\n", $output->fetch());
        self::assertTrue($services->composerFilesystem()->save('content', $this->root . '/generated/file'));
        self::assertSame('content', file_get_contents($this->root . '/generated/file'));
        self::assertSame('../generated/file', $services->composerFilesystem()->findShortestPath($this->root . '/nested/index.php', $this->root . '/generated/file'));
        self::assertSame($paths->vendor(), $services->composerConfig()->get('vendor-dir'));
        self::assertSame('tools', $services->composerConfig()->get('bin-dir', 1));
        self::assertSame('dist', $services->composerConfig()->get('preferred-install'));
        self::assertNull($services->composerConfig()->get('absent'));
        $this->write('tools/runtime-executable-fixture', "#!/bin/sh\nexit 0\n");
        chmod($this->root . '/tools/runtime-executable-fixture', 0755);
        self::assertSame($this->root . '/tools/runtime-executable-fixture', $services->executableFinder()->find('runtime-executable-fixture', null, [$paths->bin()]));
        self::assertNull($services->executableFinder()->find('runtime-nonexistent-command-8c19'));
    }

    private function io(): Io
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return new Io($input, new BufferedOutput());
    }

    #[Group('PAR-SVC-007')]
    public function testFilesystemPreservesSourcesAndRejectsDirectoryAndLinkReplacement(): void
    {
        $files = new Filesystem();
        self::assertTrue($files->save('original', $this->root . '/source.txt'));
        self::assertTrue($files->copyFile($this->root . '/source.txt', $this->root . '/nested/copy.txt'));
        self::assertFileExists($this->root . '/source.txt');
        self::assertSame('original', file_get_contents($this->root . '/nested/copy.txt'));
        self::assertFalse($files->moveFile($this->root . '/source.txt', $this->root . '/nested'));
        self::assertFileExists($this->root . '/source.txt');
        self::assertTrue($files->symlink($this->root . '/source.txt', $this->root . '/link.txt'));
        self::assertTrue($files->symlink($this->root . '/source.txt', $this->root . '/link.txt'));
        self::assertFalse($files->save('replaced', $this->root . '/link.txt'));
        self::assertSame('original', file_get_contents($this->root . '/source.txt'));
        self::assertTrue($files->unlinkOrRemove($this->root . '/link.txt'));
        self::assertFileExists($this->root . '/source.txt');
        self::assertTrue($files->moveFile($this->root . '/source.txt', $this->root . '/moved.txt'));
        self::assertFileDoesNotExist($this->root . '/source.txt');
        self::assertTrue($files->copyDir($this->root . '/nested', $this->root . '/copied'));
        self::assertFalse($files->copyDir($this->root . '/nested', $this->root . '/nested/recursive'));
        self::assertTrue($files->moveDir($this->root . '/copied', $this->root . '/moved-dir'));
        self::assertDirectoryDoesNotExist($this->root . '/copied');
        self::assertSame('original', file_get_contents($this->root . '/moved-dir/copy.txt'));
    }

    #[Group('PAR-SVC-009')]
    public function testTemplatesSupportLiteralReplacementAndFallback(): void
    {
        $paths = new Paths($this->root);
        $this->write('templates/custom.php', '{{{ PATH }}} / {{{path}}} / {{{missing}}}');
        $paths->useCustomTemplatesDir($this->root . '/templates');
        $builder = new FileContentBuilder();
        self::assertSame('$1\\path / $1\\path / {{{missing}}}', $builder->build($paths, 'custom.php', ['path' => '$1\\path']));
        $this->expectExceptionMessage('Template is missing');
        $builder->build($paths, 'not-found');
    }

    #[Group('PAR-SVC-007')]
    public function testDirectoryCopiesRejectAliasRecursionAndPreserveSymlinkTargets(): void
    {
        $files = new Filesystem();
        $this->write('source/keep.txt', 'source');
        $this->write('outside/keep.txt', 'outside');
        self::assertTrue(symlink($this->root . '/source', $this->root . '/alias'));
        self::assertFalse($files->copyDir($this->root . '/source', $this->root . '/alias/nested'));
        self::assertFalse($files->moveDir($this->root . '/source', $this->root));
        self::assertFileDoesNotExist($this->root . '/keep.txt');
        $this->write('destination/placeholder', '');
        self::assertTrue(symlink($this->root . '/outside/keep.txt', $this->root . '/destination/keep.txt'));
        self::assertFalse($files->copyDir($this->root . '/source', $this->root . '/destination'));
        self::assertSame('outside', file_get_contents($this->root . '/outside/keep.txt'));
        self::assertTrue(symlink('.', $this->root . '/source/loop'));
        self::assertTrue($files->copyDir($this->root . '/source', $this->root . '/safe'));
        self::assertTrue(is_link($this->root . '/safe/loop'));
        self::assertSame('.', readlink($this->root . '/safe/loop'));
        self::assertTrue($files->copyDir($this->root . '/source', $this->root . '/safe'));
        self::assertSame('source', file_get_contents($this->root . '/source/keep.txt'));
    }

    #[Group('PAR-SVC-010')]
    #[Group('PAR-CLI-012')]
    public function testOverwriteProtectionAndForceKeepDirectoriesSafe(): void
    {
        $paths = new Paths($this->root);
        $this->write('user.txt', 'user content');
        $this->write('managed.txt', OverwritePolicy::MARKER);
        $config = new Config([], new Validator($paths));
        $policy = new OverwritePolicy($config, $paths, $this->io());
        self::assertTrue($policy->shouldOverwrite($this->root . '/absent'));
        self::assertFalse($policy->shouldOverwrite($this->root . '/user.txt'));
        self::assertTrue($policy->shouldOverwrite($this->root . '/managed.txt'));
        self::assertTrue($policy->shouldOverwrite($this->root . '/user.txt', true));
        self::assertFalse($policy->shouldOverwrite($this->root, true));
        $protected = new OverwritePolicy(new Config(['prevent-overwrite' => ['*.txt']], new Validator($paths)), $paths, $this->io());
        self::assertFalse($protected->shouldOverwrite($this->root . '/managed.txt'));
        self::assertTrue($protected->shouldOverwrite($this->root . '/managed.txt', true));
        $legacy = new OverwritePolicy(new Config([], new Validator($paths), 'release-3.0.1'), $paths, $this->io());
        self::assertTrue($legacy->shouldOverwrite($this->root . '/user.txt'));
    }

    #[Group('PAR-SVC-017')]
    #[Group('PAR-SVC-019')]
    #[Group('PAR-WPC-008')]
    public function testProcessesRetainOutputFailureEnvironmentAndArgumentBoundaries(): void
    {
        $process = new SystemProcess(new Paths($this->root), $this->io());
        $process->withEnvironment(['RUNTIME_FIXTURE' => 'synthetic']);
        [$out, $err, $success, $error] = $process->executeCapturing([PHP_BINARY, '-r', 'echo getenv("RUNTIME_FIXTURE"), "|", $argv[1]; fwrite(STDERR, "failure"); exit(7);', '$(do not execute)']);
        self::assertSame('synthetic|$(do not execute)', $out);
        self::assertSame('failure', $err);
        self::assertFalse($success);
        self::assertNotNull($error);
        self::assertTrue($process->executeSilently([PHP_BINARY, '-r', 'exit(0);']));
        self::assertFalse($process->executeSilently([PHP_BINARY, '-r', 'exit(3);']));
        $php = new PhpProcess($process);
        self::assertTrue($php->executeSilently(['-r', 'exit(getenv("RUNTIME_FIXTURE") === "synthetic" ? 0 : 1);']));
    }

    #[Group('PAR-SVC-013')]
    public function testPackageMetadataHonorsDevModeAndCustomInstallPaths(): void
    {
        $metadata = [
            'packages' => [
                ['name' => 'example/core', 'version' => '6.8.1', 'type' => 'wordpress-core', 'install-path' => '../../public/wp'],
                ['name' => 'example/theme', 'version' => '1.0.0', 'type' => 'wordpress-theme', 'install-path' => '../../public/content/themes/example'],
                ['name' => 'dev/tool', 'version' => '1.0.0', 'type' => 'library', 'install-path' => '../dev/tool'],
            ],
            'dev' => true,
            'dev-package-names' => ['dev/tool'],
        ];
        $this->write('vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        $finder = new PackageFinder(new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin', dev: false));
        self::assertCount(2, $finder->all());
        self::assertCount(2, $finder->findByVendor('example'));
        self::assertCount(2, $finder->search('EXAMPLE/*'));
        self::assertCount(0, $finder->search('example*'));
        self::assertSame([], $finder->search(''));
        self::assertCount(1, $finder->findByType('wordpress-core'));
        self::assertNull($finder->findByName('dev/tool'));
        $core = $finder->findByName('example/core');
        self::assertNotNull($core);
        self::assertSame($this->root . '/public/wp', $finder->findPathOf($core));
        $devFinder = new PackageFinder(new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin'));
        self::assertCount(3, $devFinder->search('*'));
        self::assertTrue($devFinder->findByName('dev/tool')->isDev());
    }
}
