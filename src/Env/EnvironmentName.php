<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

final class EnvironmentName
{
    public const array ALIASES = [
        'local' => 'local',
        'development' => 'development',
        'dev' => 'development',
        'develop' => 'development',
        'staging' => 'staging',
        'stage' => 'staging',
        'pre' => 'staging',
        'preprod' => 'staging',
        'pre-prod' => 'staging',
        'pre-production' => 'staging',
        'preproduction' => 'staging',
        'test' => 'staging',
        'tests' => 'staging',
        'testing' => 'staging',
        'uat' => 'staging',
        'qa' => 'staging',
        'acceptance' => 'staging',
        'accept' => 'staging',
        'production' => 'production',
        'prod' => 'production',
        'live' => 'production',
        'public' => 'production',
    ];

    public static function canonical(string $name): string
    {
        $name = strtolower($name);
        if (isset(self::ALIASES[$name])) {
            return self::ALIASES[$name];
        }
        foreach (self::ALIASES as $alias => $canonical) {
            if (preg_match('/(?:^|[^a-z]+)' . preg_quote($alias, '/') . '(?:[^a-z]+|$)/', $name)) {
                return $canonical;
            }
        }

        return 'production';
    }
}
