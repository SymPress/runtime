<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SymPress\Runtime\Console\Formatter;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Question;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConsoleServicesTest extends TestCase
{
    #[Group('PAR-IO-001')]
    #[Group('PAR-SVC-003')]
    public function testQuestionKeysDefaultsAndNoninteractiveFallback(): void
    {
        $question = new Question(['', 'Choose a mode'], [' AUTO ' => 'Automatic', 'COPY' => 'Copy'], 'copy');
        self::assertSame('auto', $question->filterAnswer(' Auto '));
        self::assertNull($question->filterAnswer('Automatic'));
        self::assertSame('copy', $question->defaultAnswerKey());
        self::assertSame('Copy', $question->defaultAnswerText());
        self::assertContains("Default: 'copy'", $question->questionLines());
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new Io($input, new BufferedOutput());
        self::assertSame('copy', $io->ask($question));
        self::assertNull($io->ask(new Question([])));
        self::assertTrue($io->askConfirm(['Continue?']));
        self::assertFalse($io->askConfirm(['Allow unknown dropins?'], false));
        $custom = Question::newWithValidator(['Number?'], ctype_digit(...), '12');
        self::assertSame('12', $custom->defaultAnswerKey());
        self::assertSame('42', $custom->filterAnswer(' 42 '));
        self::assertNull($custom->filterAnswer('invalid'));
    }

    #[Group('PAR-IO-001')]
    public function testInteractiveQuestionsStopAfterFiveInvalidAnswers(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "wrong\nwrong\nwrong\nwrong\nwrong\ny\n");
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput();
        $io = new Io($input, $output);
        self::assertSame('n', $io->ask(new Question(['Continue?'], ['y' => 'Yes', 'n' => 'No'], 'n')));
        self::assertSame("y\n", fgets($stream));
        self::assertStringContainsString('Maximum attempts', $output->fetch());
        fclose($stream);
    }

    #[Group('PAR-IO-002')]
    public function testFormatterNormalizesLinesAndCreatesListsAndBlocks(): void
    {
        $formatter = new Formatter();
        self::assertSame(['one', 'two', 'three'], $formatter->ensureLinesLength(8, " one\r\ntwo\nthree "));
        self::assertSame([' - First', ' - Second'], $formatter->createList('First', 'Second'));
        self::assertSame(['* Item'], $formatter->createListWithPrefix('*', 'Item'));
        self::assertSame([], $formatter->createFilledBlock('', ''));
        $block = $formatter->createCenteredBlock('', '', 'Hello');
        self::assertCount(5, $block);
        self::assertSame('Hello', trim($block[2]));
        self::assertSame(62, strlen($block[2]));
        self::assertSame('', $block[0]);
        self::assertSame('', $block[4]);
    }
}
