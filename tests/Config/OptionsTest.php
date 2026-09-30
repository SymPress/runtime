<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\ConfigLoader;
use SymPress\Runtime\Config\Options;
use SymPress\Runtime\Config\SchemaValidator;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class OptionsTest extends TemporaryProject
{
    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function values(): iterable
    {
        yield 'PAR-OPT-002 cache-env' => ['cache-env', 'false', false];
        yield 'PAR-OPT-003 check-vcs-ignore' => ['check-vcs-ignore', 'ask', 'ask'];
        yield 'PAR-OPT-004 command-steps' => ['command-steps', ['App\\CommandStep'], ['CommandStep' => 'App\\CommandStep']];
        yield 'PAR-OPT-007 content-dev-op' => ['content-dev-op', false, 'none'];
        yield 'PAR-OPT-007 operation normalization' => ['content-dev-op', ' COPY ', 'copy'];
        yield 'PAR-OPT-002 boolean whitespace' => ['cache-env', ' FALSE ', false];
        yield 'PAR-OPT-008 create-vcs-ignore-file' => ['create-vcs-ignore-file', 'ask', 'ask'];
        yield 'PAR-OPT-009 custom-steps' => ['custom-steps', ['own' => 'App\\OwnStep'], ['own' => 'App\\OwnStep']];
        yield 'PAR-OPT-010 db-check' => ['db-check', 'health', 'health'];
        yield 'PAR-OPT-011 dropins' => ['dropins', ['object-cache.php' => 'https://example.test/cache.php'], ['object-cache.php' => 'https://example.test/cache.php']];
        yield 'PAR-OPT-012 dropins-op' => ['dropins-op', true, 'auto'];
        yield 'PAR-OPT-016 env-example' => ['env-example', 'ask', 'ask'];
        yield 'PAR-OPT-017 env-file' => ['env-file', '.project-env', '.project-env'];
        yield 'PAR-OPT-018 install-wp-cli' => ['install-wp-cli', 'no', false];
        yield 'PAR-OPT-023 move-content' => ['move-content', 'ask', 'ask'];
        yield 'PAR-OPT-024 prevent-overwrite' => ['prevent-overwrite', ['*.php'], ['*.php']];
        yield 'PAR-OPT-025 register-theme-folder' => ['register-theme-folder', 'yes', true];
        yield 'PAR-OPT-026 require-wp' => ['require-wp', false, false];
        yield 'PAR-OPT-027 scripts' => ['scripts', ['pre-index' => 'App\\Hooks::before'], ['pre-index' => ['App\\Hooks::before']]];
        yield 'PAR-OPT-028 skip-db-check' => ['skip-db-check', true, true];
        yield 'PAR-OPT-029 skip-steps' => ['skip-steps', ['wpcli'], ['wpcli']];
        yield 'PAR-OPT-031 unknown-dropins' => ['unknown-dropins', 'ask', 'ask'];
        yield 'PAR-OPT-032 wp-cli-commands' => ['wp-cli-commands', ['wp option get home'], ['wp option get home']];
        yield 'PAR-OPT-034 wp-version' => ['wp-version', '6.8', '6.8.0'];
    }

    #[DataProvider('values')]
    public function testEveryOptionIsValidatedAndNormalized(string $key, mixed $input, mixed $expected): void
    {
        (new SchemaValidator())->validate([$key => $input]);
        $config = new Config([$key => $input], new Validator(new Paths($this->root)));
        self::assertSame([], $config->errors());
        self::assertSame($expected, $config[$key]->unwrap());
    }

    #[Group('PAR-OPT-001')]
    #[Group('PAR-OPT-006')]
    #[Group('PAR-OPT-013')]
    #[Group('PAR-OPT-014')]
    #[Group('PAR-OPT-015')]
    #[Group('PAR-OPT-030')]
    #[Group('PAR-OPT-033')]
    public function testConfiguredPathsAreRelativeToProjectRootAndProvidersStayLazy(): void
    {
        $this->write('vendor/example/hooks.php', '<?php throw new RuntimeException("Must not execute during validation");');
        $values = [
            'autoload' => 'vendor/example/hooks.php',
            'early-hook-file' => 'vendor/example/hooks.php',
            'templates-dir' => 'vendor/example',
            'content-dev-dir' => 'vendor/example',
            'env-dir' => 'config/env',
            'env-bootstrap-dir' => 'config/bootstrap',
            'wp-cli-commands' => 'vendor/example/hooks.php',
            'wp-cli-files' => [['file' => 'vendor/example/hooks.php', 'args' => ['argument with spaces'], 'skip-wordpress' => true]],
        ];
        $this->write('vendor/example/config.json', json_encode($values, JSON_THROW_ON_ERROR));
        $loaded = (new ConfigLoader())->load($this->root, ['sympress-runtime' => 'vendor/example/config.json']);
        $config = new Config($loaded->values, new Validator(new Paths($this->root)));
        self::assertSame([], $config->errors());
        foreach (['autoload', 'early-hook-file', 'templates-dir', 'content-dev-dir', 'env-dir', 'env-bootstrap-dir', 'wp-cli-commands'] as $key) {
            self::assertSame($this->root . '/' . $values[$key], $config[$key]->unwrap());
        }
        self::assertSame([['file' => $this->root . '/vendor/example/hooks.php', 'args' => ['argument with spaces'], 'skip-wordpress' => true]], $config['wp-cli-files']->unwrap());
    }

    #[Group('PAR-OPT-005')]
    #[Group('PAR-OPT-019')]
    #[Group('PAR-OPT-020')]
    #[Group('PAR-OPT-021')]
    #[Group('PAR-OPT-022')]
    public function testInternalOptionsReflectRealExecutionContext(): void
    {
        $context = new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin', 'update', updatedPackages: [['name' => 'example/core', 'version' => '6.8']]);
        self::assertSame($context->toArray(), RunContext::fromArray($context->toArray())->toArray());
        $config = new Config($context->configuration(false), new Validator(new Paths($this->root)));
        self::assertSame([['name' => 'example/core', 'version' => '6.8']], $config['composer-updated-packages']->unwrap());
        self::assertTrue($config['is-composer-update']->unwrap());
        self::assertFalse($config['is-composer-install']->unwrap());
        self::assertFalse($config['is-wpstarter-command']->unwrap());
        self::assertFalse($config['is-wpstarter-selected-command']->unwrap());
    }

    public function testEveryRecognizedOptionHasAnExplicitSchemaProperty(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/runtime.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_keys(Options::DEFAULTS) as $option) {
            self::assertArrayHasKey($option, $schema['properties'], $option);
        }
    }
}
