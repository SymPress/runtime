<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ContentStepsTest extends TemporaryProject
{
    /** @param array<string, mixed> $settings */
    private function fixture(array $settings = [], string $content = 'public/content'): void
    {
        $this->write('composer.json', json_encode(['extra' => ['wordpress-install-dir' => 'public/wp', 'wordpress-content-dir' => $content, 'sympress-runtime' => array_replace(['require-wp' => false, 'db-check' => false], $settings)]], JSON_THROW_ON_ERROR));
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
    }

    /** @param list<string> $steps */
    private function execute(array $steps): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/runtime', '-n', ...$steps], $this->root, ['COMPOSER_VENDOR_DIR' => false, 'COMPOSER' => false]);
        $process->run();

        return $process;
    }

    #[Group('PAR-STEP-008')]
    #[Group('PAR-OPT-023')]
    #[Group('PAR-OPT-025')]
    public function testMoveTransfersTheWholeCoreContentTreeAndSkipsWhenDisabledOrThemeRegistrationIsEnabled(): void
    {
        $this->fixture();
        $this->write('public/wp/wp-content/themes/default/style.css', 'theme');
        $this->write('public/wp/wp-content/plugins/hello.php', '<?php // hello');
        $this->write('public/wp/wp-content/languages/de_DE.mo', 'translation');
        self::assertSame(0, $this->execute(['movecontent'])->getExitCode());
        self::assertDirectoryExists($this->root . '/public/wp/wp-content');
        $this->fixture(['move-content' => true, 'register-theme-folder' => 'ask']);
        self::assertSame(0, $this->execute(['movecontent'])->getExitCode());
        self::assertDirectoryExists($this->root . '/public/wp/wp-content');
        $this->fixture(['move-content' => true]);
        $run = $this->execute(['movecontent']);
        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->root . '/public/wp/wp-content');
        self::assertSame('theme', file_get_contents($this->root . '/public/content/themes/default/style.css'));
        self::assertSame('translation', file_get_contents($this->root . '/public/content/languages/de_DE.mo'));
        self::assertFileExists($this->root . '/public/content/plugins/hello.php');
    }

    #[Group('PAR-STEP-008')]
    #[Group('PAR-FS-001')]
    public function testMovePreflightsEveryTargetAndForceDoesNotPermitOverlappingPaths(): void
    {
        $this->fixture(['move-content' => true]);
        $this->write('public/wp/wp-content/plugins/a.php', 'new-a');
        $this->write('public/wp/wp-content/plugins/z.php', 'new-z');
        $this->write('public/content/plugins/z.php', 'owned-z');
        self::assertSame(1, $this->execute(['movecontent'])->getExitCode());
        self::assertSame('owned-z', file_get_contents($this->root . '/public/content/plugins/z.php'));
        self::assertFileDoesNotExist($this->root . '/public/content/plugins/a.php');
        self::assertFileExists($this->root . '/public/wp/wp-content/plugins/z.php');
        self::assertSame(0, $this->execute(['movecontent', '--force'])->getExitCode());
        self::assertSame('new-z', file_get_contents($this->root . '/public/content/plugins/z.php'));
        $this->write('public/wp/wp-content/plugins/a.php', 'again');
        $this->fixture(['move-content' => true], 'public/wp/wp-content/nested');
        self::assertSame(1, $this->execute(['movecontent', '--force'])->getExitCode());
        self::assertFileExists($this->root . '/public/wp/wp-content/plugins/a.php');
        self::assertDirectoryDoesNotExist($this->root . '/public/wp/wp-content/nested');
        $this->fixture(['move-content' => true], 'public/wp/wp-content');
        self::assertSame(0, $this->execute(['movecontent'])->getExitCode());
        self::assertFileExists($this->root . '/public/wp/wp-content/plugins/a.php');
    }

    #[Group('PAR-STEP-009')]
    #[Group('PAR-OPT-006')]
    #[Group('PAR-OPT-007')]
    public function testDevelopmentContentSupportsDirectoriesFilesTranslationsAndIgnoresImmediateHiddenAndVcsEntries(): void
    {
        $this->write('content-dev/plugins/shop/main.php', 'plugin');
        $this->write('content-dev/plugins/one.php', 'single');
        $this->write('content-dev/themes/theme/style.css', 'theme');
        $this->write('content-dev/mu-plugins/mu.php', 'mu');
        $this->write('content-dev/languages/de_DE.mo', 'language');
        $this->write('content-dev/plugins/.secret', 'hidden');
        $this->write('content-dev/plugins/CVS/Entries', 'vcs');
        $this->fixture(['content-dev-dir' => 'content-dev', 'content-dev-op' => 'copy']);
        $run = $this->execute(['publishcontentdev']);
        self::assertSame(0, $run->getExitCode(), $run->getOutput());
        foreach (['plugins/shop/main.php', 'plugins/one.php', 'themes/theme/style.css', 'mu-plugins/mu.php', 'languages/de_DE.mo'] as $file) {
            self::assertSame(file_get_contents($this->root . '/content-dev/' . $file), file_get_contents($this->root . '/public/content/' . $file));
            self::assertFalse(is_link($this->root . '/public/content/' . $file));
        }
        self::assertFileDoesNotExist($this->root . '/public/content/plugins/.secret');
        self::assertDirectoryDoesNotExist($this->root . '/public/content/plugins/CVS');
        self::assertSame(0, $this->execute(['publishcontentdev'])->getExitCode());
        $this->write('content-dev/plugins/shop/main.php', 'updated');
        self::assertSame(1, $this->execute(['publishcontentdev'])->getExitCode());
        self::assertSame('plugin', file_get_contents($this->root . '/public/content/plugins/shop/main.php'));
        self::assertSame(0, $this->execute(['publishcontentdev', '--force'])->getExitCode());
        self::assertSame('updated', file_get_contents($this->root . '/public/content/plugins/shop/main.php'));
    }

    #[Group('PAR-STEP-009')]
    public function testDevelopmentAutoAndAskPublishWorkingLinksAndNoneDoesNothing(): void
    {
        $this->write('content-dev/plugins/shop/main.php', 'plugin');
        $this->fixture(['content-dev-dir' => 'content-dev', 'content-dev-op' => 'none']);
        self::assertSame(0, $this->execute(['publishcontentdev'])->getExitCode());
        self::assertDirectoryDoesNotExist($this->root . '/public/content');
        $this->fixture(['content-dev-dir' => 'content-dev', 'content-dev-op' => 'ask']);
        self::assertSame(0, $this->execute(['publishcontentdev'])->getExitCode());
        self::assertTrue(is_link($this->root . '/public/content/plugins/shop'));
        self::assertSame('plugin', file_get_contents($this->root . '/public/content/plugins/shop/main.php'));
        self::assertSame(0, $this->execute(['publishcontentdev'])->getExitCode());
    }

    #[Group('PAR-STEP-007')]
    #[Group('PAR-OPT-012')]
    #[Group('PAR-OPT-011')]
    #[Group('PAR-OPT-031')]
    public function testDropinMapUsesPerTargetProtectionAndReleaseUnknownPolicy(): void
    {
        $this->write('sources/object-cache.php', 'new');
        $this->write('public/content/object-cache.php', 'owned');
        $this->fixture(['dropins' => ['object-cache.php' => 'sources/object-cache.php'], 'dropins-op' => 'symlink']);
        self::assertSame(0, $this->execute(['dropins'])->getExitCode());
        self::assertSame('owned', file_get_contents($this->root . '/public/content/object-cache.php'));
        self::assertSame(0, $this->execute(['dropins', '--force'])->getExitCode());
        self::assertTrue(is_link($this->root . '/public/content/object-cache.php'));
        self::assertSame('new', file_get_contents($this->root . '/public/content/object-cache.php'));
        self::assertSame(0, $this->execute(['dropins'])->getExitCode());
        $this->fixture(['compatibility-profile' => 'release-3.0.1', 'dropins' => ['custom.php' => 'sources/object-cache.php'], 'unknown-dropins' => 'ask']);
        self::assertSame(0, $this->execute(['dropins'])->getExitCode());
        self::assertFileDoesNotExist($this->root . '/public/content/custom.php');
        $this->fixture(['compatibility-profile' => 'release-3.0.1', 'dropins' => ['custom.php' => 'sources/object-cache.php'], 'unknown-dropins' => true]);
        self::assertSame(0, $this->execute(['dropins'])->getExitCode());
        self::assertSame('new', file_get_contents($this->root . '/public/content/custom.php'));
        self::assertFalse(is_link($this->root . '/public/content/custom.php'));
    }

    #[Group('PAR-STEP-007')]
    #[Group('PAR-STEP-009')]
    public function testTargetSymlinksAndTypeConflictsCannotEscapeTheProjectOrDestroyUserFiles(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside);
        try {
            $this->write('sources/cache.php', 'replacement');
            $this->write('public/keep', 'keep');
            symlink($outside, $this->root . '/public/content');
            $this->fixture(['dropins' => ['object-cache.php' => 'sources/cache.php']]);
            self::assertSame(1, $this->execute(['dropins', '--force'])->getExitCode());
            self::assertFileDoesNotExist($outside . '/object-cache.php');
            unlink($this->root . '/public/content');
            $this->write('content-dev/plugins/a/main.php', 'plugin');
            $this->write('public/content/plugins/a', 'owned-file');
            $this->fixture(['content-dev-dir' => 'content-dev', 'content-dev-op' => 'copy']);
            self::assertSame(1, $this->execute(['publishcontentdev', '--force'])->getExitCode());
            self::assertSame('owned-file', file_get_contents($this->root . '/public/content/plugins/a'));
        } finally {
            rmdir($outside);
        }
    }

    private function assertDropinSources(string $name): void
    {
        $this->write('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'fixture/dropins', 'version' => '1.0.0', 'type' => 'wordpress-dropin', 'install-path' => '../fixture/dropins']]], JSON_THROW_ON_ERROR));
        $this->write('vendor/fixture/dropins/' . $name, 'package');
        $this->write('vendor/fixture/dropins/LICENSE', 'retained');
        $this->fixture(['dropins-op' => 'symlink']);
        $run = $this->execute(['dropins']);
        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        $target = $this->root . '/public/content/' . $name;
        self::assertTrue(is_link($target));
        self::assertSame('package', file_get_contents($target));
        self::assertFileExists($this->root . '/vendor/fixture/dropins/LICENSE');
        self::assertSame(0, $this->execute(['dropins'])->getExitCode());
        unlink($target);
        $this->write('vendor/composer/installed.json', '{"packages":[]}');
        $this->write('sources/' . $name, 'mapped');
        $this->fixture(['dropins-op' => 'copy', 'dropins' => [$name => 'sources/' . $name]]);
        self::assertSame(0, $this->execute(['dropins'])->getExitCode());
        self::assertSame('mapped', file_get_contents($target));
        self::assertFalse(is_link($target));
        unlink($target);
        $this->write('content-dev/' . $name, 'development');
        $this->fixture(['content-dev-dir' => 'content-dev', 'content-dev-op' => 'copy']);
        self::assertSame(0, $this->execute(['publishcontentdev'])->getExitCode());
        self::assertSame('development', file_get_contents($target));
    }

    #[Group('PAR-STEP-009')]
    public function testReleaseCopyKeepsWholeDirectoryIncludingHiddenEntries(): void
    {
        $this->write('content-dev/plugins/.hidden', 'hidden');
        $this->write('content-dev/plugins/CVS/Entries', 'vcs');
        $this->fixture(['compatibility-profile' => 'release-3.0.1', 'content-dev-dir' => 'content-dev', 'content-dev-op' => 'copy']);
        self::assertSame(0, $this->execute(['publishcontentdev'])->getExitCode());
        self::assertSame('hidden', file_get_contents($this->root . '/public/content/plugins/.hidden'));
        self::assertSame('vcs', file_get_contents($this->root . '/public/content/plugins/CVS/Entries'));
    }

    #[Group('PAR-DROPIN-advanced-cache')]
    public function testAdvancedCacheSources(): void
    {
        $this->assertDropinSources('advanced-cache.php');
    }

    #[Group('PAR-DROPIN-db')]
    public function testDatabaseSources(): void
    {
        $this->assertDropinSources('db.php');
    }

    #[Group('PAR-DROPIN-db-error')]
    public function testDatabaseErrorSources(): void
    {
        $this->assertDropinSources('db-error.php');
    }

    #[Group('PAR-DROPIN-install')]
    public function testInstallSources(): void
    {
        $this->assertDropinSources('install.php');
    }

    #[Group('PAR-DROPIN-maintenance')]
    public function testMaintenanceSources(): void
    {
        $this->assertDropinSources('maintenance.php');
    }

    #[Group('PAR-DROPIN-object-cache')]
    public function testObjectCacheSources(): void
    {
        $this->assertDropinSources('object-cache.php');
    }

    #[Group('PAR-DROPIN-php-error')]
    public function testPhpErrorSources(): void
    {
        $this->assertDropinSources('php-error.php');
    }

    #[Group('PAR-DROPIN-fatal-error-handler')]
    public function testFatalErrorSources(): void
    {
        $this->assertDropinSources('fatal-error-handler.php');
    }

    #[Group('PAR-DROPIN-sunrise')]
    public function testSunriseSources(): void
    {
        $this->assertDropinSources('sunrise.php');
    }

    #[Group('PAR-DROPIN-blog-deleted')]
    public function testDeletedBlogSources(): void
    {
        $this->assertDropinSources('blog-deleted.php');
    }

    #[Group('PAR-DROPIN-blog-inactive')]
    public function testInactiveBlogSources(): void
    {
        $this->assertDropinSources('blog-inactive.php');
    }

    #[Group('PAR-DROPIN-blog-suspended')]
    public function testSuspendedBlogSources(): void
    {
        $this->assertDropinSources('blog-suspended.php');
    }
}
