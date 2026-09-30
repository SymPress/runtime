<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Filesystem;

use BadMethodCallException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class PathsTest extends TemporaryProject
{
    #[Group('PAR-CFG-006')]
    #[Group('PAR-CFG-007')]
    #[Group('PAR-SVC-002')]
    public function testAllNamedPathsAndCustomVendorDirectories(): void
    {
        $paths = new Paths($this->root, 'deps', 'tools', 'public/wp', 'public/content');
        self::assertSame($this->root . '/public/wp/wp-settings.php', $paths->wp('wp-settings.php'));
        self::assertSame($this->root . '/public', $paths->wpParent());
        self::assertSame($this->root . '/public/content/plugins/', $paths->wpContent('/plugins/'));
        self::assertSame($this->root . '/deps/autoload.php', $paths->vendor('autoload.php'));
        self::assertSame($this->root . '/tools', $paths->bin());
        self::assertSame('public/wp', $paths->relativeToRoot(Paths::WP));
        self::assertSame($this->root, (new Paths($this->root, wp: '.'))->wpParent());
        $paths['extra'] = $this->root . '/extension';
        self::assertSame($this->root . '/extension/file.php', $paths->absolute('extra', 'file.php'));
        $this->write('templates/index.php', 'custom');
        $paths->useCustomTemplatesDir($this->root . '/templates');
        self::assertSame($this->root . '/templates/index.php', $paths->template('index.php'));
        self::assertSame(dirname(__DIR__, 2) . '/templates/other.php', $paths->template('other.php'));
        $this->expectException(BadMethodCallException::class);
        $paths['extra'] = 'changed';
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLayouts(): iterable
    {
        yield 'outside core' => ['../sibling/wp', 'wp-content'];
        yield 'outside content' => ['wordpress', '../sibling/content'];
        yield 'root content' => ['wordpress', '.'];
        yield 'different parent' => ['public/wp', 'wp-content'];
        yield 'prefix collision' => ['public/wp', 'publicity/content'];
    }

    #[DataProvider('invalidLayouts')]
    #[Group('PAR-CFG-006')]
    #[Group('PAR-CFG-007')]
    public function testInvalidLayoutsAreRejected(string $core, string $content): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Paths($this->root, wp: $core, content: $content);
    }

    #[Group('PAR-SVC-002')]
    public function testPathRemovalIsRejected(): void
    {
        $paths = new Paths($this->root);
        $this->expectException(BadMethodCallException::class);
        unset($paths[Paths::ROOT]);
    }
}
