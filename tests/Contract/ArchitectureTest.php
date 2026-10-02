<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    #[Group('PAR-QA-001')]
    #[Group('PAR-QA-003')]
    public function testInstalledGraphExcludesWpStarterAndUsesTheRequiredStack(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $installed = json_decode((string) file_get_contents($root . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_merge(array_keys($manifest['require']), array_keys($manifest['require-dev']), array_column($installed['packages'], 'name')) as $name) {
            self::assertFalse(str_starts_with($name, 'wecodemore/'), $name);
            self::assertFalse(str_starts_with($name, 'inpsyde/'), $name);
        }
        self::assertSame('^8.5', $manifest['require']['php']);
        self::assertSame('^13.0', $manifest['require-dev']['phpunit/phpunit']);
        foreach ($manifest['require'] as $name => $constraint) {
            if (!str_starts_with($name, 'symfony/')) {
                continue;
            }
            self::assertSame('^8.1', $constraint);
        }
        self::assertArrayHasKey('sympress/qa', $manifest['require-dev']);
        self::assertStringContainsString('sympress/workflows/.github/workflows/sympress-qa.yml@177fa0d727b278d2103052ec77c102b4a4c492a0', (string) file_get_contents($root . '/.github/workflows/qa.yml'));
        $workflow = (string) file_get_contents($root . '/.github/workflows/differential.yml');
        self::assertStringContainsString('--fail-on-skipped', $workflow);
        self::assertStringContainsString('tools/parity-evidence.py', $workflow);
        foreach (['wecodemore/', 'inpsyde/', 'tools/differential/run.py', '--oracle-php'] as $excluded) {
            self::assertStringNotContainsString($excluded, $workflow);
        }
        self::assertFileDoesNotExist($root . '/tools/differential/run.py');
    }

    #[Group('PAR-QA-004')]
    public function testCopiedRecognitionTemplatesMatchTheAttributedPinnedSources(): void
    {
        $root = dirname(__DIR__, 2);
        $notice = (string) file_get_contents($root . '/NOTICE');
        self::assertStringContainsString('Giuseppe Mazzapica', $notice);
        self::assertStringContainsString('Permission is hereby granted', $notice);
        $inventory = json_decode((string) file_get_contents($root . '/docs/maintainers/upstream-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['release' => 'legacy-mu-loader.php.txt', 'dev' => 'legacy-mu-loader-dev.php.txt'] as $baseline => $template) {
            $files = array_column($inventory['baselines'][$baseline]['files'], null, 'file');
            self::assertSame($files['templates/wpstarter-mu-loader.php']['sha256'], hash_file('sha256', $root . '/resources/' . $template));
            self::assertStringContainsString('resources/' . $template, $notice);
        }
    }
}
