<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Application\RunContext;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Config\Validator;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;
use SymPress\Runtime\Tests\Support\TemporaryProject;

final class MuPluginListTest extends TemporaryProject
{
    #[Group('PAR-SVC-015')]
    #[Group('PAR-MU-001')]
    #[Group('PAR-MU-002')]
    public function testTypedPackagesFallbackDiscoveryHeadersDeduplicationAndDropinExclusion(): void
    {
        $paths = new Paths($this->root);
        $muDirectory = 'wp-content/mu-plugins/';
        $this->write($muDirectory . 'single/bootstrap.php', '<?php // no header necessary');
        $this->write($muDirectory . 'multiple/first.php', "<?php\n/* Plugin Name: First */");
        $this->write($muDirectory . 'multiple/second.php', "<?php\r// Plugin Name: Second");
        $this->write($muDirectory . 'multiple/helper.php', '<?php // helper');
        $this->write($muDirectory . 'untyped/plugin.php', "<?php\n// Plugin Name: Untyped");
        $this->write($muDirectory . 'no-header/helper.php', '<?php // not a plugin');
        $this->write($muDirectory . 'dropin/object-cache.php', "<?php\n// Plugin Name: Cache");
        $this->write($muDirectory . 'nested/sub/plugin.php', "<?php\n// Plugin Name: Too deep");
        $this->write($muDirectory . '.hidden/plugin.php', "<?php\n// Plugin Name: Hidden");
        $this->write($muDirectory . 'root.php', "<?php\n// Plugin Name: Loaded by WordPress");
        $this->write($muDirectory . 'late/plugin.php', '<?php ' . str_repeat(' ', 8192) . "\n// Plugin Name: Too late");
        $metadata = [
            'packages' => [
            ['name' => 'fixture/single', 'type' => 'wordpress-muplugin', 'version' => '1.0.0', 'install-path' => '../../' . $muDirectory . 'single'],
            ['name' => 'fixture/multiple', 'type' => 'wordpress-muplugin', 'version' => '1.0.0', 'install-path' => '../../' . $muDirectory . 'multiple'],
            ['name' => 'fixture/absent', 'type' => 'wordpress-muplugin', 'version' => '1.0.0', 'install-path' => '../../absent'],
            ],
        ];
        $this->write('vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        $config = new Config(['dropins' => ['object-cache.php' => $paths->root($muDirectory . 'dropin/object-cache.php')]], new Validator($paths));
        $finder = new PackageFinder(new RunContext($this->root, $paths->vendor(), $paths->bin()));
        $list = (new MuPluginList($finder, $paths))->pluginsList($config);
        self::assertSame([
            'fixture/single' => $paths->root($muDirectory . 'single/bootstrap.php'),
            'fixture/multiple_first' => $paths->root($muDirectory . 'multiple/first.php'),
            'fixture/multiple_second' => $paths->root($muDirectory . 'multiple/second.php'),
            'untyped' => $paths->root($muDirectory . 'untyped/plugin.php'),
        ], $list);
    }

    #[Group('PAR-SVC-015')]
    public function testAbsentMuDirectoryIsAnEmptyList(): void
    {
        $paths = new Paths($this->root);
        $finder = new PackageFinder(new RunContext($this->root, $paths->vendor(), $paths->bin()));
        self::assertSame([], (new MuPluginList($finder, $paths))->pluginsList(new Config([], new Validator($paths))));
    }
}
