<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class LegacyStepTest extends TemporaryProject
{
    /** @return iterable<string, array{string}> */
    public static function constructors(): iterable
    {
        yield 'typed locator' => ['private \\WeCodeMore\\WpStarter\\Util\\Locator $locator'];
        yield 'positional services and context' => ['private $locator, private $composer'];
    }

    private function fixture(string $constructor): void
    {
        $this->write('vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        $this->write('composer.json', json_encode(['extra' => ['sympress-runtime' => ['require-wp' => false, 'db-check' => false, 'autoload' => 'legacy.php', 'custom-steps' => ['index' => 'LegacyFixtureStep'], 'scripts' => ['post-index' => 'legacy_fixture_post']]]], JSON_THROW_ON_ERROR));
        $source = <<<'PHP'
<?php
use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Util\Paths;
use WeCodeMore\WpStarter\Io\Io;
use WeCodeMore\WpStarter\Util\Locator;
use WeCodeMore\WpStarter\Step\Step;
use WeCodeMore\WpStarter\Step\OptionalStep;
use WeCodeMore\WpStarter\Step\PostProcessStep;
final class LegacyFixtureStep implements OptionalStep, PostProcessStep
{
    public function __construct(@@CONSTRUCTOR@@) {}
    public function name(): string { return 'index'; }
    public function success(): string { return 'legacy success'; }
    public function error(): string { return 'legacy failure'; }
    public function allowed(Config $config, Paths $paths): bool { return $this->locator->paths() === $paths && $config[Config::REQUIRE_WP]->is(false) && is_dir($paths[Paths::WP_STARTER]); }
    public function run(Config $config, Paths $paths): int
    {
        if (isset($this->composer) && !$this->composer instanceof \SymPress\Runtime\Application\RunContext) { throw new RuntimeException('Wrong context'); }
        $this->locator->io()->write('legacy body');
        return Step::SUCCESS;
    }
    public function askConfirm(Config $config, Io $io): bool { return true; }
    public function skipped(): string { return 'legacy skip'; }
    public function postProcess(Io $io): void { $io->write('legacy postprocess'); }
}
function legacy_fixture_post(int $result, Step $step, Locator $services): void
{
    $services->io()->write('legacy callback:' . $result . ':' . $step->name());
}
PHP;
        $this->write('legacy.php', str_replace('@@CONSTRUCTOR@@', $constructor, $source));
    }

    #[DataProvider('constructors')]
    #[Group('PAR-EXT-007')]
    #[Group('PAR-EXT-001')]
    public function testLegacyInterfacesLocatorConstructorAndDefaultReplacementWorkInChild(string $constructor): void
    {
        $this->fixture($constructor);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/sympress-runtime', '-n', 'index'], $this->root, ['COMPOSER_VENDOR_DIR' => false, 'COMPOSER' => false]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString("legacy body\nlegacy callback:2:index", $process->getOutput());
        self::assertStringContainsString('legacy postprocess', $process->getOutput());
        self::assertFileDoesNotExist($this->root . '/index.php');
        $normal = new Process([PHP_BINARY, '-r', 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; echo (int) interface_exists("WeCodeMore\\WpStarter\\Step\\Step");']);
        $normal->mustRun();
        self::assertSame('0', $normal->getOutput());
    }

    #[Group('PAR-EXT-007')]
    #[Group('PAR-EXT-011')]
    public function testRequiredComposerObjectProducesConcreteMigrationDiagnostic(): void
    {
        $this->fixture('private \\WeCodeMore\\WpStarter\\Util\\Locator $locator, private \\Composer\\Composer $composer');
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/sympress-runtime', '-n', 'index'], $this->root, ['COMPOSER_VENDOR_DIR' => false, 'COMPOSER' => false]);
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('LegacyFixtureStep::$composer', $process->getErrorOutput());
        self::assertStringContainsString('RunContext or runner Services', $process->getErrorOutput());
        self::assertStringNotContainsString('legacy body', $process->getOutput());
    }
}
