<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\RuntimeBundleBuilder;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class RuntimeBundleTest extends TemporaryProject
{
    /** @return iterable<string, array{bool}> */
    public static function bootstrapModes(): iterable
    {
        yield 'lazy payload' => [false];
        yield 'bundled bootstrap' => [true];
    }

    #[DataProvider('bootstrapModes')]
    public function testIndependentPayloadCanWriteAndReadItsVersionedCache(bool $bundled): void
    {
        $paths = new Paths($this->root);
        $bundle = (new RuntimeBundleBuilder($paths, new ProjectBoundary($paths), $bundled))->build();
        $bootstrap = '$class = require ' . var_export($bundle->loader, true) . ';';
        $write = new Process([PHP_BINARY, '-r', $bootstrap . '$reader = new $class(); $reader->write("RTV_PAYLOAD_VALUE", "payload"); if (!$reader->dumpCached("cache.php")) { exit(1); }'], $this->root);
        $write->mustRun();
        $read = new Process([PHP_BINARY, '-r', $bootstrap . '$reader = $class::buildFromCacheDump("cache.php"); echo json_encode([$reader->hasCachedValues(), $reader->read("RTV_PAYLOAD_VALUE"), class_exists("Composer\\\\Autoload\\\\ClassLoader", false)]);'], $this->root, ['RTV_PAYLOAD_VALUE' => false]);
        $read->mustRun();
        self::assertSame([true, 'payload', false], json_decode($read->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Group('PAR-WP-002')]
    public function testScopedPayloadLoadsWithoutComposerAndRetainsCommandSubstitution(): void
    {
        $paths = new Paths($this->root);
        $builder = new RuntimeBundleBuilder($paths, new ProjectBoundary($paths));
        $bundle = $builder->build();
        self::assertFileExists(dirname($bundle->loader) . '/Dotenv/LICENSE');
        self::assertFileExists(dirname($bundle->loader) . '/Process/LICENSE');
        self::assertFileDoesNotExist($this->root . '/vendor/autoload.php');
        $this->write('.env', "WP_ENVIRONMENT_TYPE=staging\nDB_NAME=fixture\nDB_USER=fixture\nRTV_EXPANDED=\"\${DB_NAME}/expanded\"\nRTV_COMMAND=\$(printf command-result)\n");
        $this->write('probe.php', '<?php namespace Symfony\\Component\\Dotenv { class Dotenv { public function __construct() { throw new \\RuntimeException("Host parser loaded"); } } } namespace { $readerClass = require ' . var_export($bundle->loader, true) . '; $reader = new $readerClass(); $reader->loadChain(); $reader->setupConstants(); echo json_encode([$reader->read("RTV_EXPANDED"), $reader->read("RTV_COMMAND"), WP_ENVIRONMENT_TYPE, class_exists("Composer\\\\Autoload\\\\ClassLoader", false)]); }');
        $process = new Process([PHP_BINARY, $this->root . '/probe.php'], $this->root, ['WP_ENV' => false, 'WP_ENVIRONMENT_TYPE' => false, 'SYMFONY_DOTENV_VARS' => false, 'DB_NAME' => false, 'DB_USER' => false]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(['fixture/expanded', 'command-result', 'staging', false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $second = $builder->build();
        self::assertSame($bundle->loader, $second->loader);
        self::assertSame($bundle->fingerprint, $second->fingerprint);
    }

    public function testExistingModifiedBundleIsRejectedAndRetained(): void
    {
        $paths = new Paths($this->root);
        $builder = new RuntimeBundleBuilder($paths, new ProjectBoundary($paths));
        $bundle = $builder->build();
        file_put_contents($bundle->loader, '<?php // user change');
        try {
            $builder->build();
            self::fail('Modified payload must not be overwritten.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('immutable build', $error->getMessage());
            self::assertSame('<?php // user change', file_get_contents($bundle->loader));
            self::assertSame([], glob($this->root . '/var/runtime/.build-*'));
        }
    }

    public function testUnknownManifestFormatIsRejectedWithoutReplacingThePayload(): void
    {
        $paths = new Paths($this->root);
        $builder = new RuntimeBundleBuilder($paths, new ProjectBoundary($paths));
        $bundle = $builder->build();
        $file = dirname($bundle->loader) . '/manifest.json';
        $manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['format']);
        $manifest['format'] = 2;
        $contents = json_encode($manifest, JSON_THROW_ON_ERROR);
        file_put_contents($file, $contents);
        try {
            $builder->build();
            self::fail('Unknown payload formats must not be adopted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('immutable build', $error->getMessage());
            self::assertSame($contents, file_get_contents($file));
            self::assertFileExists($bundle->loader);
        }
    }
}
