<?php

declare(strict_types=1);

namespace SymPress\Runtime\Package;

use InvalidArgumentException;
use SymPress\Runtime\Config\SchemaValidator;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;

/** @internal */
final readonly class ExtensionMetadata
{
    public function __construct(private Paths $paths)
    {
    }

    /** @return array<string, string> */
    public function steps(Package $package): array
    {
        $extra = $package->getExtra();
        if (!array_key_exists('sympress-runtime', $extra)) {
            return [];
        }
        $metadata = $extra['sympress-runtime'];
        try {
            (new SchemaValidator())->validateExtension($metadata);
            if (!is_array($metadata)) {
                throw new InvalidArgumentException('Expected an object.');
            }
            $steps = (new Validator($this->paths))->validate('steps', $metadata['steps'] ?? null)->unwrap();
            if (!is_array($steps)) {
                return [];
            }
            $validated = [];
            foreach ($steps as $name => $class) {
                if (!is_string($name) || !is_string($class)) {
                    throw new InvalidArgumentException('Expected named step classes.');
                }
                $validated[$name] = $class;
            }

            return $validated;
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Invalid runtime metadata in ' . $package->getName() . ': ' . $error->getMessage(), previous: $error);
        }
    }
}
