<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class ConfigLoaderTest extends TemporaryProject
{
    #[Group('PAR-CFG-002')]
    #[Group('PAR-CFG-003')]
    #[Group('PAR-CFG-004')]
    public function testRootOverridesReferencedAndInlineConfigurationShallowly(): void
    {
        $this->write('vendor/package/config.json', '{"cache-env":false,"env-dir":"env","skip-steps":["index"]}');
        $this->write('sympress-runtime.json', '{"cache-env":true,"skip-steps":["wpconfig"]}');
        $loader = new ConfigLoader();
        $config = $loader->load($this->root, ['sympress-runtime' => 'vendor/package/config.json']);
        self::assertTrue($config->values['cache-env']);
        self::assertSame(['wpconfig'], $config->values['skip-steps']);
        self::assertSame('env', $config->values['env-dir']);
        self::assertSame('sympress-runtime.json', $config->provenance['skip-steps']);
        self::assertCount(2, $config->diagnostics);
        $inline = $loader->load($this->root, ['sympress-runtime' => ['cache-env' => false, 'install-wp-cli' => false]]);
        self::assertTrue($inline->values['cache-env']);
        self::assertFalse($inline->values['install-wp-cli']);
    }

    #[Group('PAR-CFG-005')]
    public function testNativeFamilyWinsAndLegacySelectsReleaseProfile(): void
    {
        $this->write('wpstarter.json', '{"cache-env":false,"skip-steps":["index"]}');
        $loader = new ConfigLoader();
        $legacy = $loader->load($this->root, []);
        self::assertSame('release-3.0.1', $legacy->profile);
        self::assertStringContainsString('Deprecated', $legacy->diagnostics[0]);
        $native = $loader->load($this->root, ['sympress-runtime' => ['cache-env' => true]]);
        self::assertSame('native', $native->profile);
        self::assertTrue($native->values['cache-env']);
        self::assertSame(['index'], $native->values['skip-steps']);
        $this->expectExceptionMessage('compatibility is disabled');
        $loader->load($this->root, ['sympress-runtime' => ['compatibility' => false]]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJson(): iterable
    {
        yield 'malformed' => ['{'];
        yield 'null' => ['null'];
        yield 'array' => ['[]'];
        yield 'scalar' => ['true'];
    }

    #[DataProvider('invalidJson')]
    #[Group('PAR-CFG-008')]
    public function testMalformedSourcesNeverBecomeEmptyConfig(string $json): void
    {
        $this->write('sympress-runtime.json', $json);
        $this->expectException(InvalidArgumentException::class);
        (new ConfigLoader())->load($this->root, []);
    }

    #[Group('PAR-OPT-019')]
    public function testExecutionContextCannotBeForgedByConfiguration(): void
    {
        $this->expectExceptionMessage('provided by the runner');
        (new ConfigLoader())->load($this->root, ['sympress-runtime' => ['is-composer-install' => true]]);
    }
}
