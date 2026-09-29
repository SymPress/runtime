<?php

declare(strict_types=1);

namespace SymPress\Runtime\Config;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator as JsonValidator;
use RuntimeException;

final class SchemaValidator
{
    /** @param array<string, mixed> $values */
    public function validate(array $values): void
    {
        foreach (['scripts', 'download-checksums'] as $map) {
            if (!isset($values[$map]) || $values[$map] !== []) {
                continue;
            }

            $values[$map] = (object) [];
        }
        $validator = new JsonValidator();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/schema/runtime.schema.json');
        if ($schema === false) {
            throw new RuntimeException('Runtime configuration schema is missing.');
        }
        $data = json_decode(json_encode((object) $values, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode($schema, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($schema)) {
            throw new RuntimeException('Runtime schema must contain an object.');
        }
        $result = $validator->validate($data, $schema);
        $error = $result->error();
        if ($error !== null) {
            throw new InvalidArgumentException('Configuration schema validation failed: ' . implode('; ', array_unique($this->messages($error))));
        }
    }

    /** @return list<string> */
    private function messages(ValidationError $error): array
    {
        $children = $error->subErrors();
        if ($children === []) {
            return [(new ErrorFormatter())->formatErrorKey($error) . ': expected ' . $error->keyword() . ' constraint'];
        }
        $messages = [];
        foreach ($children as $child) {
            if (!$child instanceof ValidationError) {
                throw new RuntimeException('Invalid schema validation error.');
            }
            array_push($messages, ...$this->messages($child));
        }

        return $messages;
    }
}
