<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Download\UrlDownloader;
use SymPress\Runtime\Filesystem\ContentPublisher;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\OverwritePolicy;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Step\Builtin\DropinsStep;
use SymPress\Runtime\Step\StepInterface;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ContentPublisherTest extends TemporaryProject
{
    /** @return iterable<string, array{string, bool}> */
    public static function replacements(): iterable
    {
        foreach (['copy', 'symlink', 'auto'] as $operation) {
            yield $operation . ' file' => [$operation, false];
            yield $operation . ' directory' => [$operation, true];
        }
    }

    #[DataProvider('replacements')]
    #[Group('PAR-FS-002')]
    #[Group('PAR-FS-003')]
    public function testExplicitForceReplacesOnlyTheLinkAndPreservesItsExternalDestination(string $operation, bool $directory): void
    {
        $external = $this->root . '-external';
        mkdir($external);
        file_put_contents($external . '/keep.txt', 'external');
        $this->write($directory ? 'source/file.txt' : 'source', 'replacement');
        symlink($directory ? $external : $external . '/keep.txt', $this->root . '/target');
        try {
            self::assertSame(StepInterface::ERROR, $this->services()[7]->publish($this->root . '/source', $this->root . '/target', $operation));
            self::assertTrue(is_link($this->root . '/target'));
            self::assertSame(StepInterface::ERROR, $this->services(force: true)[7]->publish($this->root . '/missing', $this->root . '/target', $operation));
            self::assertTrue(is_link($this->root . '/target'));
            self::assertSame(StepInterface::SUCCESS, $this->services(force: true)[7]->publish($this->root . '/source', $this->root . '/target', $operation));
            self::assertSame($operation !== 'copy', is_link($this->root . '/target'));
            self::assertSame('replacement', file_get_contents($this->root . ($directory ? '/target/file.txt' : '/target')));
            self::assertSame('external', file_get_contents($external . '/keep.txt'));
            self::assertSame([], glob($this->root . '/.runtime-publish-*'));
        } finally {
            (new SymfonyFilesystem())->remove($external);
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{Paths, Config, Io, Filesystem, OverwritePolicy, ProjectBoundary, Selection, ContentPublisher}
     */
    private function services(array $settings = [], bool $force = false): array
    {
        $paths = new Paths($this->root);
        $config = new Config($settings, new Validator($paths));
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new Io($input, new BufferedOutput());
        $files = new Filesystem();
        $overwrite = new OverwritePolicy($config, $paths, $io);
        $boundary = new ProjectBoundary($paths);
        $selection = new Selection(force: $force);

        return [$paths, $config, $io, $files, $overwrite, $boundary, $selection, new ContentPublisher($files, $boundary, $overwrite, $selection)];
    }

    #[Group('PAR-STEP-007')]
    public function testDownloadsAreVerifiedBeforeReplacementAndProtectedTargetsAvoidNetworkCalls(): void
    {
        $url = 'https://example.test/object-cache.php';
        [$paths, $config, $io, $files, $overwrite, $boundary, $selection, $publisher] = $this->services(['dropins' => ['object-cache.php' => $url], 'download-checksums' => [$url => hash('sha256', 'valid')]], true);
        $files->save('old', $paths->wpContent('object-cache.php'));
        $http = new MockHttpClient([new MockResponse('corrupt'), new MockResponse('valid')]);
        $step = new DropinsStep(new PackageFinder(new RunContext($this->root, $paths->vendor(), $paths->bin())), $publisher, new UrlDownloader($http, $files, $config), $overwrite, $boundary, $selection, $io);
        self::assertSame(StepInterface::ERROR, $step->run($config, $paths));
        self::assertSame('old', file_get_contents($paths->wpContent('object-cache.php')));
        self::assertSame(StepInterface::SUCCESS, $step->run($config, $paths));
        self::assertSame('valid', file_get_contents($paths->wpContent('object-cache.php')));
        [$paths, $config, $io, $files, $overwrite, $boundary, $selection, $publisher] = $this->services(['dropins' => ['object-cache.php' => $url]]);
        $step = new DropinsStep(new PackageFinder(new RunContext($this->root, $paths->vendor(), $paths->bin())), $publisher, new UrlDownloader($http, $files, $config), $overwrite, $boundary, $selection, $io);
        self::assertSame(StepInterface::NONE, $step->run($config, $paths));
        self::assertSame(2, $http->getRequestsCount());
    }

    /** @return iterable<string, array{bool}> */
    public static function urlLinkDestinations(): iterable
    {
        yield 'inside project' => [false];
        yield 'outside project' => [true];
    }

    #[DataProvider('urlLinkDestinations')]
    #[Group('PAR-STEP-007')]
    public function testForcedUrlDropinReplacesOnlyTheLinkAfterSuccessfulVerification(bool $external): void
    {
        $url = 'https://example.test/object-cache.php';
        [$paths, $config, $io, $files, $overwrite, $boundary, $selection, $publisher] = $this->services(['dropins' => ['object-cache.php' => $url], 'download-checksums' => [$url => hash('sha256', 'valid')]], true);
        $source = $external ? $this->root . '-external.php' : $this->root . '/original.php';
        file_put_contents($source, 'original');
        $files->createDir($paths->wpContent());
        $target = $paths->wpContent('object-cache.php');
        symlink($source, $target);
        $http = new MockHttpClient([new MockResponse('corrupt'), new MockResponse('valid')]);
        $step = new DropinsStep(new PackageFinder(new RunContext($this->root, $paths->vendor(), $paths->bin())), $publisher, new UrlDownloader($http, $files, $config), $overwrite, $boundary, $selection, $io);
        try {
            self::assertSame(StepInterface::ERROR, $step->run($config, $paths));
            self::assertTrue(is_link($target));
            self::assertSame($source, readlink($target));
            self::assertSame('original', file_get_contents($source));
            self::assertSame(StepInterface::SUCCESS, $step->run($config, $paths));
            self::assertFalse(is_link($target));
            self::assertSame('valid', file_get_contents($target));
            self::assertSame('original', file_get_contents($source));
            self::assertSame(2, $http->getRequestsCount());
            self::assertSame([], glob($paths->wpContent('.runtime-publish-*')));
        } finally {
            if ($external) {
                unlink($source);
            }
        }
    }

    #[Group('PAR-FS-002')]
    public function testAutoFallsBackToCopyForExistingDirectoriesAndRejectsSymlinkOverlap(): void
    {
        $this->write('source/file.php', 'source');
        $this->write('target/existing.php', 'target');
        $publisher = $this->services()[7];
        self::assertSame(StepInterface::SUCCESS, $publisher->publish($this->root . '/source', $this->root . '/target', 'auto'));
        self::assertFalse(is_link($this->root . '/target'));
        self::assertSame('source', file_get_contents($this->root . '/target/file.php'));
        self::assertSame('target', file_get_contents($this->root . '/target/existing.php'));
        self::assertSame(StepInterface::ERROR, $publisher->publish($this->root . '/source', $this->root . '/source/nested', 'symlink'));
        self::assertFalse(is_link($this->root . '/source/nested'));
        self::assertDirectoryDoesNotExist($this->root . '/source/nested');
    }

    #[Group('PAR-STEP-007')]
    public function testPartialResultIncludesSuccessfulPublicationAndFailedSourceWithoutDeletingExistingTargets(): void
    {
        $this->write('sources/db.php', 'database');
        $this->write('sources/cache.php', 'cache');
        [$paths, $config, $io, $files, $overwrite, $boundary, $selection, $publisher] = $this->services(['dropins' => ['db.php' => 'sources/db.php', 'object-cache.php' => 'sources/cache.php'], 'dropins-op' => 'copy']);
        $config['dropins']->unwrap();
        unlink($this->root . '/sources/cache.php');
        $files->save('existing', $paths->wpContent('object-cache.php'));
        $step = new DropinsStep(new PackageFinder(new RunContext($this->root, $paths->vendor(), $paths->bin())), $publisher, new UrlDownloader(new MockHttpClient(), $files, $config), $overwrite, $boundary, $selection, $io);
        self::assertSame(StepInterface::SUCCESS | StepInterface::ERROR, $step->run($config, $paths));
        self::assertSame('database', file_get_contents($paths->wpContent('db.php')));
        self::assertSame('existing', file_get_contents($paths->wpContent('object-cache.php')));
        self::assertSame([], glob($paths->wpContent('.runtime-publish-*')));
    }
}
