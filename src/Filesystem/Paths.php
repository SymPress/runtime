<?php

declare(strict_types=1);

namespace SymPress\Runtime\Filesystem;

use ArrayAccess;
use BadMethodCallException;
use InvalidArgumentException;
use OutOfRangeException;
use Symfony\Component\Filesystem\Path;

/** @implements ArrayAccess<string, string> */
final class Paths implements ArrayAccess
{
    public const string ROOT = 'root';
    public const string VENDOR = 'vendor';
    public const string BIN = 'bin';
    public const string WP = 'wp';
    public const string WP_PARENT = 'wp-parent';
    public const string WP_CONTENT = 'wp-content';
    public const string WP_STARTER = 'wp-starter';

    /** @var array<string, string> */
    private array $paths;

    /** @var list<string> */
    private array $templates = [];

    public function __construct(
        string $root,
        string $vendor = 'vendor',
        ?string $bin = null,
        string $wp = 'wordpress',
        string $content = 'wp-content',
    ) {
        if (!is_dir($root)) {
            throw new InvalidArgumentException('Project root must be an existing directory.');
        }
        $root = Path::canonicalize($root);
        if (!Path::isAbsolute($root)) {
            throw new InvalidArgumentException('Project root must be absolute.');
        }
        $vendor = Path::makeAbsolute($vendor, $root);
        $wp = Path::makeAbsolute($wp, $root);
        $content = Path::makeAbsolute($content, $root);
        $parent = $wp === $root ? $root : Path::getDirectory($wp);
        if (!$this->inside($wp, $root)) {
            throw new InvalidArgumentException('WordPress must be inside the project root.');
        }
        if ($content === $root || !$this->inside($content, $root) || !$this->inside($content, $parent)) {
            throw new InvalidArgumentException('Content must be below the root and inside the WordPress parent.');
        }
        $this->paths = [
            self::ROOT => $root,
            self::VENDOR => $vendor,
            self::BIN => $bin === null ? $vendor . '/bin' : Path::makeAbsolute($bin, $root),
            self::WP => $wp,
            self::WP_PARENT => $parent,
            self::WP_CONTENT => $content,
            'runtime' => Path::canonicalize(dirname(__DIR__, 2)),
            self::WP_STARTER => Path::canonicalize(dirname(__DIR__, 2)),
        ];
    }

    public function root(string $to = ''): string
    {
        return $this->absolute(self::ROOT, $to);
    }

    public function vendor(string $to = ''): string
    {
        return $this->absolute(self::VENDOR, $to);
    }

    public function bin(string $to = ''): string
    {
        return $this->absolute(self::BIN, $to);
    }

    public function wp(string $to = ''): string
    {
        return $this->absolute(self::WP, $to);
    }

    public function wpParent(string $to = ''): string
    {
        return $this->absolute(self::WP_PARENT, $to);
    }

    public function wpContent(string $to = ''): string
    {
        return $this->absolute(self::WP_CONTENT, $to);
    }

    public function absolute(string $pathName, string $to = ''): string
    {
        $path = Path::canonicalize($this[$pathName] . '/' . ltrim($to, '/\\'));

        return $to !== '' && preg_match('~[/\\\\]$~', $to) === 1 ? rtrim($path, '/') . '/' : $path;
    }

    public function relativeToRoot(string $pathName, string $to = ''): string
    {
        return Path::makeRelative($this->absolute($pathName, $to), $this->root());
    }

    public function useCustomTemplatesDir(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $this->templates[] = $directory;
    }

    public function template(string $filename): string
    {
        foreach ($this->templates as $directory) {
            $path = Path::join($directory, $filename);
            if (is_file($path)) {
                return $path;
            }
        }

        return $this->absolute('runtime', 'templates/' . $filename);
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!is_string($offset)) {
            throw new InvalidArgumentException('Path name must be a string.');
        }

        return array_key_exists($offset, $this->paths);
    }

    public function offsetGet(mixed $offset): string
    {
        if (!is_string($offset) || !$this->offsetExists($offset)) {
            throw new OutOfRangeException('Unknown path name.');
        }

        return $this->paths[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_string($offset) || !is_string($value)) {
            throw new InvalidArgumentException('Path names and values must be strings.');
        }
        if ($this->offsetExists($offset)) {
            throw new BadMethodCallException('Paths are append-only.');
        }
        $this->paths[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('Paths cannot be removed.');
    }

    private function inside(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, '/') . '/');
    }
}
