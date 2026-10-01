<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class FilesystemIdentityTest extends TemporaryProject
{
    public function testHardLinkedAliasesAreNoOpsAndPreserveBothNamesAndContent(): void
    {
        $this->write('source.txt', 'preserve same inode');
        self::assertTrue(link($this->root . '/source.txt', $this->root . '/alias.txt'));
        $files = new Filesystem();
        self::assertTrue($files->copyFile($this->root . '/source.txt', $this->root . '/alias.txt'));
        self::assertSame('preserve same inode', file_get_contents($this->root . '/source.txt'));
        self::assertTrue($files->moveFile($this->root . '/source.txt', $this->root . '/alias.txt'));
        self::assertSame('preserve same inode', file_get_contents($this->root . '/source.txt'));
        self::assertSame('preserve same inode', file_get_contents($this->root . '/alias.txt'));
    }

    public function testDirectoryAliasesCannotHideAnOverlappingDestination(): void
    {
        $this->write('source/keep.txt', 'preserve tree');
        self::assertTrue(symlink($this->root . '/source', $this->root . '/alias'));
        $files = new Filesystem();
        self::assertFalse($files->copyDir($this->root . '/source', $this->root . '/alias/child'));
        self::assertFalse($files->moveDir($this->root . '/source', $this->root . '/alias/child'));
        self::assertFileDoesNotExist($this->root . '/source/child/keep.txt');
        self::assertSame('preserve tree', file_get_contents($this->root . '/source/keep.txt'));
    }
}
