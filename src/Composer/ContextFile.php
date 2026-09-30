<?php

declare(strict_types=1);

namespace SymPress\Runtime\Composer;

use RuntimeException;
use SymPress\Runtime\Application\RunContext;

/**
 * Standard-library-only codec for the private Composer-to-runner handoff.
 *
 * @internal
 */
final class ContextFile
{
    public static function consume(string $file): RunContext
    {
        $temporaryDirectory = realpath(sys_get_temp_dir());
        if (!str_starts_with(basename($file), 'sympress-context-') || realpath(dirname($file)) !== $temporaryDirectory || !is_file($file) || is_link($file)) {
            throw new RuntimeException('Invalid private runner context file.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0777) !== 0600) {
            throw new RuntimeException('Runner context file permissions must be 0600.');
        }
        if (function_exists('posix_geteuid') && fileowner($file) !== posix_geteuid()) {
            throw new RuntimeException('Runner context file has a different owner.');
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Cannot read runner context.');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid runner context data.');
        }
        $named = [];
        foreach ($data as $name => $value) {
            if (!is_string($name)) {
                throw new RuntimeException('Invalid runner context data.');
            }
            $named[$name] = $value;
        }
        $context = RunContext::fromArray($named);
        if (!unlink($file)) {
            throw new RuntimeException('Cannot consume private runner context.');
        }

        return $context;
    }
}
