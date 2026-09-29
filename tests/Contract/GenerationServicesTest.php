<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Generation\Salter;
use SymPress\Runtime\Generation\WpConfigSectionEditor;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class GenerationServicesTest extends TemporaryProject
{
    #[Group('PAR-SVC-011')]
    public function testSaltsAreUniqueCachedAndLengthBounded(): void
    {
        $salter = new Salter();
        $keys = $salter->keys();
        self::assertSame(Salter::KEYS, array_keys($keys));
        self::assertSame([64], array_values(array_unique(array_map(strlen(...), $keys))));
        self::assertCount(8, array_unique($keys));
        self::assertSame($keys, $salter->keys());
        self::assertNotSame($keys, (new Salter())->keys());
        self::assertSame([8], array_values(array_unique(array_map(strlen(...), (new Salter(1))->keys()))));
        self::assertSame([256], array_values(array_unique(array_map(strlen(...), (new Salter(1000))->keys()))));
    }

    #[Group('PAR-SVC-014')]
    #[Group('PAR-SEC-001')]
    #[Group('PAR-SEC-002')]
    #[Group('PAR-SEC-003')]
    public function testSectionsPreserveNeighborsAndLiteralCode(): void
    {
        $this->write('wp-config.php', "<?php\nFIRST : {\n    original();\n} #@@/FIRST\nSECOND : {\n    untouched();\n} #@@/SECOND\n");
        $paths = new Paths($this->root);
        $editor = new WpConfigSectionEditor($paths, new Config([], new Validator($paths)), new Filesystem());
        self::assertSame('original();', $editor->sectionContent(' first '));
        self::assertSame('', $editor->sectionContent('missing'));
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $editor->append('FIRST', '$literal = "$1\\\\folder";');
        }
        self::assertSame(1, substr_count($editor->sectionContent('FIRST'), '$literal'));
        $editor->prepend('FIRST', 'before();');
        self::assertLessThan(strpos($editor->sectionContent('FIRST'), 'original();'), strpos($editor->sectionContent('FIRST'), 'before();'));
        self::assertSame('untouched();', $editor->sectionContent('SECOND'));
        $editor->replace('FIRST', '$literal = "$1\\\\folder";');
        self::assertSame('$literal = "$1\\\\folder";', $editor->sectionContent('FIRST'));
        $editor->delete('FIRST');
        self::assertSame('', $editor->sectionContent('FIRST'));
        $editor->append('FIRST', 'afterDelete();');
        self::assertStringContainsString('afterDelete();', $editor->sectionContent('FIRST'));
        $before = file_get_contents($this->root . '/wp-config.php');
        $editor->replace('UNKNOWN', 'ignored();');
        self::assertSame($before, file_get_contents($this->root . '/wp-config.php'));
    }

    #[Group('PAR-SEC-004')]
    public function testLegacyWebrootAndMissingConfigErrors(): void
    {
        $paths = new Paths($this->root, wp: 'public/wp', content: 'public/content');
        $config = new Config([], new Validator($paths), 'release-3.0.1');
        $editor = new WpConfigSectionEditor($paths, $config, new Filesystem());
        $this->write('public/wp-config.php', "<?php FIRST : { legacy(); } #@@/FIRST\n");
        self::assertSame('legacy();', $editor->sectionContent('FIRST'));
        $native = new WpConfigSectionEditor($paths, new Config([], new Validator($paths)), new Filesystem());
        $this->expectExceptionMessage('missing or unreadable');
        $native->sectionContent('FIRST');
    }
}
