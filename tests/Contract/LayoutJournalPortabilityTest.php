<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class LayoutJournalPortabilityTest extends TemporaryProject
{
    public function testWindowsBranchFlushesFilesWithoutOpeningDirectories(): void
    {
        $this->write('composer.json', '{}');
        $process = $this->runWindowsJournal(false);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('3', $process->getOutput());
        self::assertSame('{}', file_get_contents($this->root . '/composer.json'));
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.pending.json');
    }

    public function testWindowsFileFlushFailureStillStopsTheTransaction(): void
    {
        $this->write('composer.json', '{}');
        $process = $this->runWindowsJournal(true);
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Cannot flush package journal.', $process->getErrorOutput());
        self::assertSame('{}', file_get_contents($this->root . '/composer.json'));
        self::assertFileDoesNotExist($this->root . '/var/runtime/package-layout.pending.json');
    }

    private function runWindowsJournal(bool $failFlush): Process
    {
        $code = 'namespace SymPress\\Runtime\\Composer {
            const PHP_OS_FAMILY = "Windows";
            function fopen(string $file, string $mode) {
                if (is_dir($file)) { throw new \\RuntimeException("Directory stream is unavailable on Windows."); }
                if ($mode === "r") { throw new \\RuntimeException("File flush needs Windows write access."); }
                return \\fopen($file, $mode);
            }
            function fsync($handle): bool {
                $GLOBALS["flushes"] = ($GLOBALS["flushes"] ?? 0) + 1;
                return ' . ($failFlush ? 'false' : '\\fsync($handle)') . ';
            }
        } namespace {
            require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            $journal = new SymPress\\Runtime\\Composer\\LayoutJournal(getcwd(), getcwd() . "/vendor");
            $journal->begin([], [getcwd() . "/composer.json"]);
            $journal->commit();
            echo $GLOBALS["flushes"];
        }';
        $process = new Process([PHP_BINARY, '-r', $code], $this->root);
        $process->run();

        return $process;
    }
}
