<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\ExtensionMetadata;
use SymPress\Runtime\Package\Package;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class ExtensionMetadataTest extends TemporaryProject
{
    /** @return iterable<string, array{mixed}> */
    public static function invalidMetadata(): iterable
    {
        yield 'scalar metadata' => ['synthetic-secret'];
        yield 'list metadata' => [['synthetic-secret']];
        yield 'scalar steps' => [['steps' => 'synthetic-secret']];
        yield 'non-string class' => [['steps' => ['secret' => false]]];
        yield 'invalid class syntax' => [['steps' => ['secret' => 'synthetic-secret']]];
    }

    #[DataProvider('invalidMetadata')]
    public function testInvalidContributionsFailWithoutLeakingValues(mixed $metadata): void
    {
        $package = new Package('fixture/extension', 'sympress-runtime-extension', '1.0.0', $this->root, ['sympress-runtime' => $metadata]);
        try {
            (new ExtensionMetadata(new Paths($this->root)))->steps($package);
            self::fail('Invalid metadata must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('fixture/extension', $error->getMessage());
            self::assertStringNotContainsString('synthetic-secret', $error->getMessage());
        }
    }

    public function testClassNamesAreValidatedWithoutAutoloading(): void
    {
        $package = new Package('fixture/extension', 'library', '1.0.0', $this->root, ['sympress-runtime' => ['steps' => ['Fixture\\MissingStep']]]);
        self::assertSame(['MissingStep' => 'Fixture\\MissingStep'], (new ExtensionMetadata(new Paths($this->root)))->steps($package));
        self::assertFalse(class_exists('Fixture\\MissingStep', false));
    }
}
