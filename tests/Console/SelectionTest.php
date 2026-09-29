<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Console;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Step\Definition;
use SymPress\Runtime\Step\Registry;

final class SelectionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function releaseSlugs(): iterable
    {
        foreach (['check-paths' => 'checkpaths', 'build-wp-config' => 'wpconfig', 'build-index' => 'index', 'flush-env-cache' => 'flushenvcache', 'build-mu-loader' => 'muloader', 'build-env-example' => 'envexample', 'dropins' => 'dropins', 'move-content' => 'movecontent', 'publish-content-dev' => 'publishcontentdev', 'build-wp-cli-yml' => 'wpcliconfig', 'wp-cli' => 'wpcli'] as $old => $native) {
            yield $old => [$old, $native];
        }
    }

    #[DataProvider('releaseSlugs')]
    #[Group('PAR-CLI-009')]
    public function testEveryPinnedReleaseSlugResolvesAndHonorsCompatibility(string $legacy, string $native): void
    {
        $registry = new Registry();
        self::assertSame($native, $registry->resolve($legacy)?->name);
        self::assertSame($legacy === $native ? $native : null, $registry->resolve($legacy, false)?->name);
        self::assertSame([$native], array_column((new Selection([$legacy]))->resolve($registry)['steps'], 'name'));
    }

    #[Group('PAR-CLI-011')]
    #[Group('PAR-RUN-001')]
    public function testDefaultOrderMatchesEachPinnedProfileWithoutRemovingOptInCapabilities(): void
    {
        $registry = new Registry();
        $names = static fn (string $profile): array => array_column((new Selection())->resolve($registry, profile: $profile)['steps'], 'name');
        self::assertSame(['checkpaths', 'wpconfig', 'index', 'flushenvcache', 'muloader', 'envexample', 'dropins', 'movecontent', 'publishcontentdev', 'vcsignorecheck', 'wpcliconfig', 'wpcli'], $names('native'));
        self::assertSame(['checkpaths', 'wpconfig', 'index', 'flushenvcache', 'muloader', 'envexample', 'dropins', 'movecontent', 'publishcontentdev', 'wpcliconfig', 'vcsignorecheck', 'wpcli'], $names('upstream-dev'));
        self::assertSame(['checkpaths', 'wpconfig', 'index', 'flushenvcache', 'muloader', 'envexample', 'dropins', 'movecontent', 'publishcontentdev', 'wpcliconfig', 'wpcli'], $names('release-3.0.1'));
        self::assertSame('vcsignorecheck', (new Selection(['vcsignorecheck']))->resolve($registry, profile: 'release-3.0.1')['steps'][0]->name);
    }

    #[Group('PAR-SYM-009')]
    #[Group('PAR-CLI-009')]
    public function testLegacyAliasesWarnOnceApplyToSkipsAndAreDisabledExplicitly(): void
    {
        $registry = new Registry();
        $result = (new Selection(['build-wp-config', 'build-wp-config']))->resolve($registry);
        self::assertCount(1, $result['warnings']);
        self::assertSame(['wpconfig'], array_column($result['steps'], 'name'));
        $skipped = (new Selection())->resolve($registry, ['build-wp-config']);
        self::assertNotContains('wpconfig', array_column($skipped['steps'], 'name'));
        self::assertCount(1, $skipped['warnings']);
        $this->expectExceptionMessage('No valid selected steps');
        (new Selection(['build-wp-config']))->resolve($registry, compatibility: false);
    }

    private function registry(): Registry
    {
        $registry = new Registry();
        $registry->add(new Definition('custom', custom: true));
        $registry->add(new Definition('command', commandOnly: true));

        return $registry;
    }

    /** @return list<string> */
    private function names(Selection $selection, array $skips = []): array
    {
        return array_map(static fn (Definition $step): string => $step->name, $selection->resolve($this->registry(), $skips)['steps']);
    }

    #[Group('PAR-CLI-003')]
    #[Group('PAR-CLI-011')]
    public function testBareModeExcludesCommandStepsAndRunsWpCliLast(): void
    {
        $names = $this->names(new Selection());
        self::assertNotContains('command', $names);
        self::assertContains('custom', $names);
        self::assertSame('wpcli', $names[array_key_last($names)]);
        self::assertSame(['command'], $this->names(new Selection(['command'])));
    }

    #[Group('PAR-CLI-004')]
    #[Group('PAR-CLI-006')]
    public function testOptInWinsAndPreservesOrderingExceptWpCli(): void
    {
        $selection = new Selection(['wpcli', 'custom', 'index'], skipCustom: true);
        self::assertSame(['custom', 'index', 'wpcli'], $this->names($selection, ['index', 'custom']));
        self::assertSame(['wpcli'], array_map(static fn (Definition $step): string => $step->name, $selection->resolve($this->registry(), ['index', 'custom'], 'upstream-dev')['steps']));
    }

    #[Group('PAR-CLI-005')]
    #[Group('PAR-CLI-006')]
    #[Group('PAR-CLI-007')]
    public function testExclusionsAndIgnoreConfigAreIndependent(): void
    {
        $names = $this->names(new Selection(['index'], skip: true, skipCustom: true), ['wpconfig']);
        self::assertNotContains('index', $names);
        self::assertNotContains('wpconfig', $names);
        self::assertNotContains('custom', $names);
        self::assertNotContains('command', $names);
        $ignored = $this->names(new Selection(['index'], skip: true, ignoreSkipConfig: true), ['wpconfig']);
        self::assertContains('wpconfig', $ignored);
        self::assertNotContains('index', $ignored);
        self::assertNotContains('command', $ignored);
    }

    #[Group('PAR-CLI-005')]
    public function testSkipRequiresNames(): void
    {
        $this->expectExceptionMessage('--skip requires');
        new Selection(skip: true);
    }

    #[Group('PAR-CLI-008')]
    public function testListSupportsSelectionAndExclusions(): void
    {
        self::assertSame(['index', 'wpcli'], $this->names(new Selection(['wpcli', 'index'], list: true)));
        $listed = $this->names(new Selection(list: true));
        self::assertContains('command', $listed);
        $sorted = $listed;
        sort($sorted);
        self::assertSame($sorted, $listed);
        self::assertNotContains('index', $this->names(new Selection(['index'], skip: true, list: true)));
    }

    #[Group('PAR-CLI-009')]
    #[Group('PAR-CLI-010')]
    public function testAliasesAndPartialUnknownSelection(): void
    {
        $result = (new Selection(['not-real', 'build-wp-config', 'index', 'index']))->resolve($this->registry());
        self::assertSame(['wpconfig', 'index'], array_map(static fn (Definition $step): string => $step->name, $result['steps']));
        self::assertSame(['Unknown step: not-real', 'Deprecated WP Starter step alias: build-wp-config; use wpconfig.'], $result['warnings']);
        self::assertNull($this->registry()->resolve('INDEX'));
        $this->expectException(InvalidArgumentException::class);
        (new Selection(['not-real']))->resolve($this->registry());
    }
}
