<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use JsonException;
use RuntimeException;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Composer\ContextFile;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

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
        $context = new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin');
        $file = ContextFile::create($context);
        self::assertStringStartsWith('sympress-context-', basename($file));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($file) & 0777);
        }
        try {
            self::assertSame($context->toArray(), ContextFile::consume($file)->toArray());
            self::assertFileDoesNotExist($file);
            try {
                ContextFile::consume($file);
                self::fail('Consumed contexts must not be replayed.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Invalid private', $error->getMessage());
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testEmptyAndMalformedJsonContextsArePreserved(): void
    {
        foreach (['', '{broken'] as $contents) {
            $file = ContextFile::create(new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin'));
            try {
                file_put_contents($file, $contents);
                try {
                    ContextFile::consume($file);
                    self::fail('Malformed JSON must fail before unlink.');
                } catch (JsonException) {
                    self::assertSame($contents, file_get_contents($file));
                }
            } finally {
                unlink($file);
            }
        }
    }

    public function testFailedProtectionOrWritingRemovesTheEmptyContext(): void
    {
        foreach ([true, false] as $failProtection) {
            $code = 'namespace SymPress\\Runtime\\Composer {
                function chmod(string $file, int $mode): bool {
                    $GLOBALS["contextFile"] = $file;
                    return ' . ($failProtection ? 'false' : '\\chmod($file, $mode)') . ';
                }
                function fwrite($handle, string $contents): int { return 0; }
            } namespace {
                require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
                try {
                    SymPress\\Runtime\\Composer\\ContextFile::create(new SymPress\\Runtime\\Application\\RunContext("root", "vendor", "bin"));
                    exit(1);
                } catch (RuntimeException $error) {
                    echo $error->getMessage();
                    exit(file_exists($GLOBALS["contextFile"]) ? 2 : 0);
                }
            }';
            $process = new Process([PHP_BINARY, '-r', $code], $this->root);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('Cannot secure or write runner context.', $process->getOutput());
        }
    }

    public function testMalformedPrivateContextAndSymlinksArePreserved(): void
    {
        $file = ContextFile::create(new RunContext($this->root, $this->root . '/vendor', $this->root . '/vendor/bin'));
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
