<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Filesystem\FileContentBuilder;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class TemplateOverrideTest extends TemporaryProject
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (['.env.example', '.gitignore', '.hgignore', 'index.php', 'wp-config.php', 'wp-config-loader.php', 'sympress-runtime-mu-loader.php', 'wp-cli.yml'] as $template) {
            yield $template => [$template];
        }
    }

    #[DataProvider('templates')]
    #[Group('PAR-EXT-009')]
    public function testEachTemplateFallsBackAndCanBeOverriddenIndependently(string $template): void
    {
        $paths = new Paths($this->root);
        $bundled = $paths->template($template);
        self::assertFileExists($bundled);
        $this->write('first/unrelated', 'unrelated');
        $this->write('second/' . $template, 'second {{{VALUE}}}');
        $paths->useCustomTemplatesDir($this->root . '/missing');
        $paths->useCustomTemplatesDir($this->root . '/first');
        $paths->useCustomTemplatesDir($this->root . '/second');
        $builder = new FileContentBuilder();
        self::assertSame('second literal', $builder->build($paths, $template, ['value' => 'literal']));
        $this->write('first/' . $template, 'first {{{VALUE}}}');
        self::assertSame('first literal', $builder->build($paths, $template, ['value' => 'literal']));
        self::assertSame('unrelated', $builder->build($paths, 'unrelated'));
    }

    #[Group('PAR-EXT-010')]
    public function testPlaceholderValuesAreScalarLiteralAndUnknownNamesSurvive(): void
    {
        $builder = new FileContentBuilder();
        self::assertSame('$1\\path|$1\\path|0|1||{{{array}}}|{{{null}}}|{{{unknown}}}', $builder->render('{{{ Value }}}|{{{vAlUe}}}|{{{zero}}}|{{{yes}}}|{{{no}}}|{{{array}}}|{{{null}}}|{{{unknown}}}', ['value' => '$1\\path', 'zero' => 0, 'yes' => true, 'no' => false, 'array' => [], 'null' => null]));
        $this->write('templates/empty', '');
        $paths = new Paths($this->root);
        $paths->useCustomTemplatesDir($this->root . '/templates');
        $this->expectExceptionMessage('Template is empty');
        $builder->build($paths, 'empty');
    }
}
