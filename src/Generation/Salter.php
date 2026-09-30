<?php

declare(strict_types=1);

namespace SymPress\Runtime\Generation;

final class Salter
{
    public const array KEYS = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ];

    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ =,.;:/?!|@#$%^&*()-_[]{}<>~`+';

    /** @var array<string, string>|null */
    private ?array $generated = null;

    public function __construct(private readonly int $length = 64)
    {
    }

    /** @return array<string, string> */
    public function keys(): array
    {
        if ($this->generated !== null) {
            return $this->generated;
        }
        $length = max(8, min(256, $this->length));
        $salts = [];
        foreach (self::KEYS as $name) {
            $salt = '';
            for ($offset = 0; $offset < $length; $offset++) {
                $salt .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $salts[$name] = $salt;
        }
        $this->generated = $salts;

        return $salts;
    }
}
