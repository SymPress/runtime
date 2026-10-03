<?php

declare(strict_types=1);

namespace SymPress\Runtime\Env;

use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;

// phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- Template evaluation uses the same environment precedence as the parser.

/** Parser-produced templates evaluated against each request's environment. @internal */
final class EnvCacheTemplates
{
    /**
     * @param list<string> $statements
     * @return list<array{name: string, template: string, references: array<string, array{name: string, default: ?string, assign: bool}>}>|null
     */
    // phpcs:ignore SymPress.Complexity.NestingLevel.High -- Single-pass lexical quote and escape handling avoids reparsing on warm requests.
    public static function compile(Dotenv $dotenv, array $statements): ?array
    {
        $plans = [];
        foreach ($statements as $statement) {
            if (str_contains($statement, '$(')) {
                return null;
            }
            $references = [];
            $transformed = '';
            $quote = null;
            for ($index = 0, $length = strlen($statement); $index < $length; ++$index) {
                $char = $statement[$index];
                if ($char === '\\' && $quote !== "'") {
                    $transformed .= $char;
                    ++$index;
                    if ($index < $length) {
                        $transformed .= $statement[$index];
                    }
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                } elseif ($quote === null && ($char === "'" || $char === '"')) {
                    $quote = $char;
                }
                if ($quote === null && $char === '#' && ($index === 0 || ctype_space($statement[$index - 1]))) {
                    $transformed .= substr($statement, $index);
                    break;
                }
                if ($char !== '$' || $quote === "'") {
                    $transformed .= $char;
                    continue;
                }
                $tail = substr($statement, $index);
                if (str_starts_with($tail, '$(')) {
                    return null;
                }
                if (!preg_match('/^\$(\{)?([A-Za-z_][A-Za-z0-9_]*)(:[-=][^}]*+)?(\})?/', $tail, $match)) {
                    $transformed .= $char;
                    continue;
                }
                $marker = 'SYMPRESS_TEMPLATE_' . bin2hex(random_bytes(16));
                $default = $match[3] ?? '';
                $references[$marker] = ['name' => $match[2], 'default' => $default === '' ? null : self::lexDefault(substr($default, 2), $quote), 'assign' => str_starts_with($default, ':=')];
                $transformed .= $marker . ($match[1] === '' && ($match[4] ?? '') === '}' ? '}' : '');
                $index += strlen($match[0]) - 1;
            }
            foreach ($dotenv->parse($transformed) as $name => $template) {
                if (!is_string($name) || !is_string($template)) {
                    throw new RuntimeException('Environment parser returned invalid template data.');
                }
                $plans[] = ['name' => $name, 'template' => $template, 'references' => $references];
            }
        }
        return $plans;
    }

    /** @return array<string, string> */
    // phpcs:ignore SymPress.Complexity.NestingLevel.High -- Ordered assignments and default side effects share one parser-compatible evaluation boundary.
    public static function resolve(mixed $plans): array
    {
        if (!is_array($plans)) {
            throw new RuntimeException('Environment cache templates are invalid.');
        }
        $loaded = EnvReader::loadedVars();
        $values = [];
        foreach ($plans as $plan) {
            if (!is_array($plan) || !is_string($plan['name'] ?? null) || !is_string($plan['template'] ?? null) || !is_array($plan['references'] ?? null)) {
                throw new RuntimeException('Environment cache template is invalid.');
            }
            $replacements = [];
            foreach ($plan['references'] as $marker => $reference) {
                if (!is_string($marker) || !is_array($reference) || !is_string($reference['name'] ?? null)) {
                    throw new RuntimeException('Environment cache template reference is invalid.');
                }
                $name = $reference['name'];
                $value = isset($loaded[$name], $values[$name]) ? $values[$name]
                    : ($_ENV[$name] ?? (!str_starts_with($name, 'HTTP_') ? ($_SERVER[$name] ?? null) : null) ?? $values[$name] ?? getenv($name));
                $value = is_scalar($value) ? (string) $value : '';
                if ($value === '' && is_string($reference['default'] ?? null)) {
                    $value = $reference['default'];
                    if (strpbrk($value, '\'"{$') !== false) {
                        throw new RuntimeException('Environment cache interpolation default is invalid.');
                    }
                    if (($reference['assign'] ?? false) === true) {
                        $values[$name] = $value;
                    }
                    $value = str_replace(['\\\\', "\0"], ['\\', '$'], $value);
                }
                $replacements[$marker] = $value;
            }
            $values[$plan['name']] = strtr($plan['template'], $replacements);
        }
        return $values;
    }

    private static function lexDefault(string $value, ?string $quote): string
    {
        if ($quote === '"') {
            $value = str_replace(['\\"', '\\r', '\\n'], ['"', "\r", "\n"], $value);
        }
        return preg_replace_callback('/\\\\+\$/', static function (array $match): string {
            $slashes = substr($match[0], 0, -1);
            return strlen($slashes) % 2 === 1 ? substr($slashes, 0, -1) . "\0" : $match[0];
        }, $value) ?? $value;
    }
}
