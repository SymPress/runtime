<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Composer\ContextFile;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class ContextFileTest extends TemporaryProject
{
    public function testRegularProjectFileCannotBeConsumedAsPrivateContext(): void
    {
        $this->write('composer.json', '{}');
        try {
            ContextFile::consume($this->root . '/composer.json');
            self::fail('Project files must not be consumed.');
        } catch (RuntimeException) {
            self::assertSame('{}', file_get_contents($this->root . '/composer.json'));
        }
    }

    public function testValidPrivateContextIsValidatedThenRemoved(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sympress-context-');
        self::assertIsString($file);
        $context = new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin');
        chmod($file, 0600);
        file_put_contents($file, json_encode($context->toArray(), JSON_THROW_ON_ERROR));
        try {
            self::assertSame($context->toArray(), ContextFile::consume($file)->toArray());
            self::assertFileDoesNotExist($file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testMalformedPrivateContextAndSymlinksArePreserved(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sympress-context-');
        self::assertIsString($file);
        chmod($file, 0600);
        file_put_contents($file, '{"protocol":99}');
        try {
            try {
                ContextFile::consume($file);
                self::fail('Invalid protocol must fail before unlink.');
            } catch (\InvalidArgumentException) {
                self::assertFileExists($file);
            }
            unlink($file);
            $this->write('keep.json', '{}');
            self::assertTrue(symlink($this->root . '/keep.json', $file));
            try {
                ContextFile::consume($file);
                self::fail('Symlink must not be consumed.');
            } catch (RuntimeException) {
                self::assertTrue(is_link($file));
                self::assertSame('{}', file_get_contents($this->root . '/keep.json'));
            }
        } finally {
            if (file_exists($file) || is_link($file)) {
                unlink($file);
            }
        }
    }
}
