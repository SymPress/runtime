<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

/** @internal */
final class TrustedProxy
{
    // phpcs:ignore SymPress.Complexity.NestingLevel.High -- IP-family normalization and bounded CIDR validation share a fail-closed comparison boundary.
    public static function forwardsHttps(mixed $peer, mixed $scheme, mixed $trusted): bool
    {
        if (!is_string($peer) || !is_string($scheme) || strtolower($scheme) !== 'https' || !is_string($trusted)) {
            return false;
        }
        $address = @inet_pton($peer);
        if ($address === false) {
            return false;
        }
        $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";
        if (strlen($address) === 16 && str_starts_with($address, $mappedPrefix)) {
            $address = substr($address, 12);
        }
        foreach (explode(',', $trusted) as $entry) {
            $parts = explode('/', trim($entry));
            $network = @inet_pton($parts[0]);
            if (is_string($network) && strlen($network) === 16 && str_starts_with($network, $mappedPrefix)) {
                if (isset($parts[1])) {
                    if (!ctype_digit($parts[1]) || (int) $parts[1] < 96 || (int) $parts[1] > 128) {
                        continue;
                    }
                    $parts[1] = (string) ((int) $parts[1] - 96);
                }
                $network = substr($network, 12);
            }
            if ($network === false || strlen($network) !== strlen($address) || count($parts) > 2) {
                continue;
            }
            $width = strlen($address) * 8;
            if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > $width)) {
                continue;
            }
            $bits = isset($parts[1]) ? (int) $parts[1] : $width;
            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;
            if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            if ($remainder === 0 || (ord($address[$bytes]) & (255 << 8 - $remainder)) === (ord($network[$bytes]) & (255 << 8 - $remainder))) {
                return true;
            }
        }

        return false;
    }
}
