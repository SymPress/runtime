<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Package\AutoloadRegistry;
use SymPress\Runtime\Package\Package;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class AutoloadRegistryTest extends TemporaryProject
{
    #[Group('PAR-EXT-005')]
    public function testNamespaceBoundaryFallbackDirectoriesAndExplicitUnregister(): void
    {
        $namespace = 'RuntimeAutoload' . bin2hex(random_bytes(8));
        $this->write('src/Found.php', '<?php namespace ' . $namespace . '; final class Found {}');
        $this->write('src/Later.php', '<?php namespace ' . $namespace . '; final class Later {}');
        $package = new Package('fixture/extension', 'sympress-runtime-extension', '1.0.0', $this->root, ['sympress-runtime-autoload' => ['psr-4' => [$namespace . '\\' => ['missing', 'src']]]]);
        $before = spl_autoload_functions();
        $registry = new AutoloadRegistry();
        $registry->load($package);
        self::assertTrue(class_exists($namespace . '\\Found'));
        self::assertFalse(class_exists($namespace . 'Other\\Found'));
        self::assertFalse(class_exists($namespace . '\\Missing'));
        $registry->unregister();
        self::assertSame($before, spl_autoload_functions());
        self::assertFalse(class_exists($namespace . '\\Later'));
    }

    #[Group('PAR-EXT-006')]
    public function testLegacyMalformedEntriesAreIgnoredAndNonExtensionsDoNotLoadExtraAutoload(): void
    {
        $this->write('bootstrap.php', '<?php throw new RuntimeException("must not execute");');
        $registry = new AutoloadRegistry();
        $legacy = new Package('fixture/legacy', 'wpstarter-extension', '1.0.0', $this->root, ['wpstarter-autoload' => ['psr-4' => [false], 'files' => [null]]]);
        self::assertSame(['psr-4' => [], 'files' => []], $registry->metadata($legacy));
        $library = new Package('fixture/library', 'library', '1.0.0', $this->root, ['sympress-runtime-autoload' => ['files' => ['bootstrap.php']]]);
        self::assertSame([], $registry->load($library));
        $disabled = new Package('fixture/legacy', 'wpstarter-extension', '1.0.0', $this->root, ['wpstarter-autoload' => ['files' => ['bootstrap.php']]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Legacy extension metadata requires compatibility');
        $registry->load($disabled, false);
    }
}
