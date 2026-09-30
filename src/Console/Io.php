<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question as ConsoleQuestion;

final readonly class Io
{
    public function __construct(private InputInterface $input, private OutputInterface $output)
    {
    }

    public function write(string $message): void
    {
        $this->output->writeln(OutputFormatter::escape($message));
    }

    public function success(string $message): void
    {
        $this->write($message);
    }

    public function raw(string $buffer, bool $error = false): void
    {
        $output = $error && $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
    }

    public function comment(string $message): void
    {
        $this->write($message);
    }

    public function error(string $message): void
    {
        $output = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        $output->writeln(OutputFormatter::escape($message));
    }

    public function verbose(string $message): void
    {
        $this->output->writeln(OutputFormatter::escape($message), OutputInterface::VERBOSITY_VERBOSE);
    }

    /** @param list<string>|string $question */
    public function askConfirm(array|string $question, bool $default = true): bool
    {
        if (!$this->input->isInteractive()) {
            return $default;
        }
        $text = is_array($question) ? implode("\n", $question) : $question;
        $prompt = new ConfirmationQuestion($text . ($default ? ' [Y/n] ' : ' [y/N] '), $default);
        $prompt->setMaxAttempts(5);

        return (new QuestionHelper())->ask($this->input, $this->output, $prompt) === true;
    }

    public function ask(Question $question): ?string
    {
        $lines = $question->questionLines();
        if ($lines === []) {
            return null;
        }
        if (!$this->input->isInteractive()) {
            return $question->defaultAnswerKey();
        }
        $helper = new QuestionHelper();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $answer = $helper->ask($this->input, $this->output, new ConsoleQuestion(implode("\n", $lines) . "\n> ", $question->defaultAnswerKey()));
            $filtered = is_string($answer) ? $question->filterAnswer($answer) : null;
            if ($filtered !== null) {
                return $filtered;
            }
            $this->comment('Invalid answer, try again.');
        }
        $this->error('Maximum attempts reached; using the configured default.');

        return $question->defaultAnswerKey();
    }

    public function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    public function writeSuccess(string $message): void
    {
        $this->success($message);
    }

    public function writeError(string $line): void
    {
        $this->error($line);
    }

    public function writeComment(string $message): bool
    {
        $this->comment($message);

        return true;
    }

    public function writeIfVerbose(string $line): void
    {
        $this->verbose($line);
    }

    public function writeCommentIfVerbose(string $line): bool
    {
        $this->verbose($line);

        return $this->isVerbose();
    }

    public function writeErrorIfVerbose(string $line): void
    {
        if (!$this->isVerbose()) {
            return;
        }

        $this->error($line);
    }

    public function writeSuccessBlock(string ...$lines): void
    {
        $this->writeFilledColorBlock('green', 'black', ...$lines);
    }

    public function writeCommentBlock(string ...$lines): void
    {
        $this->writeFilledColorBlock('yellow', 'black', ...$lines);
    }

    public function writeErrorBlock(string ...$lines): void
    {
        $this->writeFilledErrorColorBlock('red', 'white', ...$lines);
    }

    public function writeFilledColorBlock(string $background, string $frontground = 'black', string ...$lines): void
    {
        $this->block($background, $frontground, false, false, array_values($lines));
    }

    public function writeCenteredColorBlock(string $background, string $frontground = 'black', string ...$lines): void
    {
        $this->block($background, $frontground, true, false, array_values($lines));
    }

    public function writeFilledErrorColorBlock(string $background, string $frontground = 'black', string ...$lines): void
    {
        $this->block($background, $frontground, false, true, array_values($lines));
    }

    public function writeCenteredErrorColorBlock(string $background, string $frontground = 'black', string ...$lines): void
    {
        $this->block($background, $frontground, true, true, array_values($lines));
    }

    /** @param list<string> $lines */
    private function block(string $background, string $foreground, bool $centered, bool $error, array $lines): void
    {
        $formatter = new Formatter();
        $before = '<fg=' . $foreground . ';bg=' . $background . '>';
        $lines = array_map(OutputFormatter::escape(...), $lines);
        $block = $centered ? $formatter->createCenteredBlock($before, '</>', ...$lines) : $formatter->createFilledBlock($before, '</>', ...$lines);
        $output = $error && $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        $output->writeln($block);
    }
}
