<?php

declare(strict_types=1);

namespace SymPress\Runtime\Database;

use InvalidArgumentException;

final readonly class DbHost
{
    public function __construct(public string $host, public ?int $port = null, public ?string $socket = null)
    {
    }

    public static function parse(string $input): self
    {
        $socket = null;
        $position = strpos($input, ':/');
        if ($position !== false) {
            $socket = substr($input, $position + 1);
            $input = substr($input, 0, $position);
        }
        if (str_starts_with($input, '[')) {
            if (!preg_match('/^\[([0-9a-fA-F:]+)\](?::([0-9]+))?$/D', $input, $parts)) {
                throw new InvalidArgumentException('Invalid bracketed database host.');
            }
        } elseif (substr_count($input, ':') > 1) {
            $parts = [$input, $input];
        } elseif (!preg_match('/^([^:]*)(?::([0-9]+))?$/D', $input, $parts)) {
            throw new InvalidArgumentException('Invalid database host or port.');
        }
        $port = isset($parts[2]) ? (int) $parts[2] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid database port.');
        }

        return new self($parts[1] === '' ? 'localhost' : $parts[1], $port, $socket);
    }
}
