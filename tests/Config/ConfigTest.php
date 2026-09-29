<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Config;

use BadMethodCallException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Options;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class ConfigTest extends TemporaryProject
{
    /** @param array<string, mixed> $values */
    private function config(array $values = [], string $profile = 'native'): Config
    {
        return new Config($values, new Validator(new Paths($this->root), $profile), $profile);
    }

    #[Group('PAR-CFG-001')]
    public function testZeroConfigAndProfileDefaults(): void
    {
        $config = $this->config();
        self::assertSame([], $config->errors());
        self::assertTrue($config['cache-env']->unwrap());
        self::assertNull($config['autoload']->unwrap());
        self::assertNull($config['content-dev-dir']->unwrap());
        self::assertSame('auto', $config['content-dev-op']->unwrap());
        self::assertSame('symlink', $this->config([], 'release-3.0.1')['content-dev-op']->unwrap());
        self::assertFalse($this->config([], 'release-3.0.1')['env-local-overrides']->unwrap());
        self::assertSame('auto', $this->config([], 'upstream-dev')['content-dev-op']->unwrap());
        self::assertCount(count(Options::DEFAULTS), Options::defaults('release-3.0.1'));
    }

    #[Group('PAR-CFG-008')]
    public function testValidationKeepsFalseAndErrorsDistinct(): void
    {
        $config = $this->config(['cache-env' => 'false', 'install-wp-cli' => 'no', 'require-wp' => 0, 'env-file' => '../.env']);
        self::assertFalse($config['cache-env']->unwrap());
        self::assertFalse($config['install-wp-cli']->unwrap());
        self::assertFalse($config['require-wp']->unwrap());
        self::assertSame(['env-file'], array_keys($config->errors()));
        self::assertSame(['cache-env'], array_keys($this->config(['cache-env' => null])->errors()));
        self::assertSame(['cache-env'], array_keys($this->config(['cache-env' => 'maybe'])->errors()));
        self::assertSame(['autoload'], array_keys($this->config(['autoload' => 'missing.php'])->errors()));
    }

    #[Group('PAR-CFG-009')]
    public function testCustomValuesAndValidators(): void
    {
        $config = $this->config(['custom' => 4]);
        self::assertSame(4, $config['custom']->unwrap());
        $config->appendValidator('custom', static fn (int $value): int => $value * 2);
        self::assertSame(8, $config['custom']->unwrap());
        $config->appendValidator('custom', static fn (): never => throw new RuntimeException('custom failure'));
        self::assertSame(['custom' => 'custom failure'], $config->errors());
        $this->expectException(InvalidArgumentException::class);
        $config->appendValidator('cache-env', static fn (): bool => true);
    }

    #[Group('PAR-CFG-010')]
    public function testMutationOnlyReplacesUnsetNullAndDefaultValues(): void
    {
        $config = $this->config(['nullable' => null]);
        $config['nullable'] = 12;
        $config['cache-env'] = false;
        $config['new'] = 'value';
        $config[''] = 'empty key';
        $config[] = 'ignored';
        self::assertSame(12, $config['nullable']->unwrap());
        self::assertFalse($config['cache-env']->unwrap());
        self::assertSame('value', $config['new']->unwrap());
        self::assertSame('empty key', $config['']->unwrap());
        self::assertNull($config[0]->unwrap());
        self::assertFalse(isset($config[0]));
        self::assertTrue(isset($config['nullable']));
        $this->expectException(BadMethodCallException::class);
        $config['cache-env'] = true;
    }

    #[Group('PAR-CFG-010')]
    public function testUnsetIsRejected(): void
    {
        $config = $this->config();
        $this->expectException(LogicException::class);
        unset($config['missing']);
    }
}
