<?php

declare(strict_types=1);

function fixture_record(array $entry): void
{
    file_put_contents(__DIR__ . '/lifecycle.jsonl', json_encode($entry) . "\n", FILE_APPEND);
}

function fixture_pre_run(int $result, mixed $runner, mixed $services, mixed $context): void
{
    fixture_record(['pre-run', $result, get_class($context)]);
    if (getenv('SYMPRESS_LIFECYCLE_MODE') === 'autoload') {
        $loaded = [];
        set_error_handler(static function (): bool { return true; });
        foreach (['FixtureExtension\\Available', 'fixtureextension\\DifferentCase', 'FixtureExtensionOther\\Sibling', 'FixtureExtension\\Missing'] as $class) {
            try {
                $loaded[] = class_exists($class);
            } catch (Throwable) {
                $loaded[] = 'error';
            }
        }
        restore_error_handler();
        fixture_record(['autoload', $loaded]);
    }
}

final class FixtureLifecycleStep implements \@@STEP_INTERFACE@@
{
    public function name(): string { return 'fixture'; }
    public function success(): string { return 'fixture success'; }
    public function error(): string { return 'fixture error'; }
    public function allowed(\@@CONFIG@@ $config, \@@PATHS@@ $paths): bool { return true; }
    public function run(\@@CONFIG@@ $config, \@@PATHS@@ $paths): int
    {
        fixture_record(['body']);
        return match (getenv('SYMPRESS_LIFECYCLE_MODE')) {
            'error' => self::ERROR,
            'partial' => self::ERROR | self::SUCCESS,
            'none' => self::NONE,
            default => self::SUCCESS,
        };
    }
}

final class FixtureLifecycleHooks
{
    public static function before(int $result): void
    {
        fixture_record(['pre-step', $result]);
        if (getenv('SYMPRESS_LIFECYCLE_MODE') === 'callback-error') {
            throw new RuntimeException('synthetic callback failure');
        }
    }
    public static function next(int $result): void { fixture_record(['next-pre-step', $result]); }
    public static function after(int $result): void { fixture_record(['post-step', $result]); }
    public static function postRun(int $result): void { fixture_record(['post-run', $result]); }
}
