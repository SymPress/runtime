<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ProductionEnvironmentTest extends TemporaryProject
{
    /** @return array<array-key, mixed> */
    private function runPhp(string $code): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $process = new Process([PHP_BINARY, '-r', 'require ' . var_export($autoload, true) . ';' . $code], $this->root, array_fill_keys(['WP_ENV', 'WP_ENVIRONMENT_TYPE', 'WORDPRESS_ENV', 'SYMFONY_DOTENV_VARS', 'DB_PASSWORD', 'DB_PASSWORD_FILE'], false));
        $process->mustRun();

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    public function testProcessSecretInterpolationPreventsPersistenceWithoutChangingReads(): void
    {
        $this->write('.env', 'RTV_DERIVED=${RTV_PROCESS_SECRET}' . "\n");
        self::assertSame(['external-secret', false, false, false, false], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=external-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->read('RTV_DERIVED'), $r->dumpCached('cache.php'), $r->dumpCached('dump.php', immutable: true), is_file('cache.php'), is_file('dump.php')]);
PHP));
    }

    public function testRealEnvironmentSecretsAreExcludedAndFileDefaultsSurviveTheirRemoval(): void
    {
        $this->write('.env', "DB_PASSWORD=file-default\nRTV_VALUE=from-file\n");
        $actual = $this->runPhp(<<<'PHP'
putenv('DB_PASSWORD=process-secret'); putenv('RTV_PROCESS_TOKEN=process-token');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$r->read('DB_PASSWORD'); $r->read('RTV_PROCESS_TOKEN');
$r->dumpCached('cache.php'); $r->dumpCached('dump.php', immutable: true);
$clean = !str_contains(file_get_contents('cache.php') . file_get_contents('dump.php'), 'process-secret')
    && !str_contains(file_get_contents('cache.php') . file_get_contents('dump.php'), 'process-token');
putenv('DB_PASSWORD'); putenv('RTV_PROCESS_TOKEN'); unset($_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD'], $_ENV['RTV_PROCESS_TOKEN'], $_SERVER['RTV_PROCESS_TOKEN']);
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
echo json_encode([$clean, $r->read('DB_PASSWORD'), $r->read('RTV_PROCESS_TOKEN')]);
PHP);
        self::assertSame([true, 'file-default', null], $actual);
    }

    public function testCacheDetectsNewOverrideChangedSizeAndDeletedFileWhileDumpStaysImmutable(): void
    {
        $this->write('.env', "WP_ENVIRONMENT_TYPE=production\nRTV_VALUE=base\n");
        $this->runPhp('$r = new SymPress\\Runtime\\Env\\EnvReader(); $r->loadChain(); $r->dumpCached("cache.php"); $r->dumpCached("dump.php", immutable: true); echo "[]";');
        $load = '$r = SymPress\\Runtime\\Env\\EnvReader::buildFromCacheDump("cache.php", validateSources: true); $cached = $r->hasCachedValues(); $r->loadChain(); echo json_encode([$cached, $r->read("RTV_VALUE")]);';
        self::assertSame([true, 'base'], $this->runPhp($load));
        $this->write('.env.production.local', "RTV_VALUE=override\n");
        self::assertSame([false, 'override'], $this->runPhp($load));
        self::assertSame([true, 'base'], $this->runPhp('$r = SymPress\\Runtime\\Env\\EnvReader::buildFromCacheDump("dump.php"); echo json_encode([$r->hasCachedValues(), $r->read("RTV_VALUE")]);'));
        $this->runPhp('$r = new SymPress\\Runtime\\Env\\EnvReader(); $r->loadChain(); $r->dumpCached("cache.php"); echo "[]";');
        $this->write('.env.production.local', "RTV_VALUE=longer-override\n");
        self::assertSame([false, 'longer-override'], $this->runPhp($load));
        unlink($this->root . '/.env.production.local');
        self::assertSame([false, 'base'], $this->runPhp($load));
    }

    public function testFileSecretsAreLiveNeverDumpedAndExplicitEmptyBaseWins(): void
    {
        $this->write('secret', "first-secret\n\n");
        $this->write('.env', 'DB_PASSWORD_FILE=' . $this->root . "/secret\nRTV_EMPTY=\nRTV_EMPTY_FILE=/invalid\n");
        $actual = $this->runPhp(<<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$first = $r->read('DB_PASSWORD');
$r->dumpCached('cache.php');
file_put_contents('secret', "rotated\n");
echo json_encode([$first, $r->read('DB_PASSWORD'), $r->read('RTV_EMPTY'), str_contains(file_get_contents('cache.php'), 'first-secret')]);
PHP);
        self::assertSame(["first-secret\n", 'rotated', '', false], $actual);
        self::assertSame(['rotated'], $this->runPhp('$r = SymPress\\Runtime\\Env\\EnvReader::buildFromCacheDump("cache.php", validateSources: true); echo json_encode([$r->read("DB_PASSWORD")]);'));
    }

    public function testRequirementsDistinguishZeroFalseInvalidAndMissingWithoutLeakingValues(): void
    {
        $this->write('.env', "RTV_ZERO=0\nRTV_FALSE=false\nRTV_FLOAT=1.25\nRTV_INVALID=private-secret\nRTV_FILE_FILE=/invalid/private-path\n");
        $result = $this->runPhp(<<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode((new SymPress\Runtime\Env\EnvRequirements())->validate($r, ['RTV_ZERO'=>'int','RTV_FALSE'=>'bool','RTV_FLOAT'=>'float','RTV_INVALID'=>'int','RTV_MISSING'=>'string','RTV_FILE'=>'string']));
PHP);
        self::assertCount(3, $result);
        self::assertStringNotContainsString('private-secret', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-path', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testPrivateAndGroupModesForArtifactsAndEnvironmentDumps(): void
    {
        self::assertSame([0600, 0640, 0640], $this->runPhp(<<<'PHP'
$boundary = new SymPress\Runtime\Filesystem\ProjectBoundary(new SymPress\Runtime\Filesystem\Paths(getcwd()));
(new SymPress\Runtime\Generation\ArtifactWriter($boundary))->write('' . getcwd() . '/private.php', '<?php return 1;');
(new SymPress\Runtime\Generation\ArtifactWriter($boundary, 0640))->write(getcwd() . '/group.php', '<?php return 1;');
$r = new SymPress\Runtime\Env\EnvReader(fileMode: 0640); $r->write('RTV_VALUE','secret'); $r->dumpCached('cache.php');
echo json_encode([fileperms('private.php') & 0777, fileperms('group.php') & 0777, fileperms('cache.php') & 0777]);
PHP));
    }

    public function testBundledBootstrapLoadsCachesWithoutParserAndRetainsLazyParsing(): void
    {
        $this->write('.env', "RTV_VALUE=bundled\n");
        self::assertSame([false, 'bundled', true], $this->runPhp(<<<'PHP'
$paths = new SymPress\Runtime\Filesystem\Paths(getcwd());
$boundary = new SymPress\Runtime\Filesystem\ProjectBoundary($paths);
$bundle = (new SymPress\Runtime\Generation\RuntimeBundleBuilder($paths, $boundary, true))->build();
$class = require $bundle->loader;
$r = new $class();
$parser = str_replace('Env\\EnvReader', 'Dotenv\\Dotenv', $class);
$before = class_exists($parser, false);
$r->loadChain();
echo json_encode([$before, $r->read('RTV_VALUE'), class_exists($parser, false)]);
PHP));
    }

    public function testWarmCacheRestoresTypedValuesWithoutParserOrFilters(): void
    {
        $this->write('.env', "WP_DEBUG=true\nRTV_VALUE=warm\n");
        $this->runPhp('$r = new SymPress\\Runtime\\Env\\EnvReader(); $r->loadChain(); $r->dumpCached("cache.php"); echo "[]";');
        self::assertSame([true, true, null, null], $this->runPhp(<<<'PHP'
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
echo json_encode([$r->hasCachedValues(), $r->read('WP_DEBUG'), (new ReflectionProperty($r, 'dotenv'))->getValue($r), (new ReflectionProperty($r, 'filters'))->getValue($r)]);
PHP));
    }

    public function testUnprivilegedReadOnlyDirectoryNeverReceivesOrSpillsSecrets(): void
    {
        mkdir($this->root . '/readonly', 0555);
        chmod($this->root, 0755);
        try {
            $actual = $this->runPhp(<<<'PHP'
if (posix_geteuid() === 0) { posix_setgid(65534); posix_setuid(65534); }
$before = glob(sys_get_temp_dir() . '/.sympress-private-*');
$r = new SymPress\Runtime\Env\EnvReader(); $r->write('RTV_SECRET', 'must-never-spill');
$boundary = new SymPress\Runtime\Filesystem\ProjectBoundary(new SymPress\Runtime\Filesystem\Paths(getcwd()));
$writer = new SymPress\Runtime\Generation\ArtifactWriter($boundary);
$first = $r->dumpCached('readonly/cache.php');
$second = $writer->write(getcwd() . '/readonly/config.php', '<?php /* must-never-spill */');
echo json_encode([posix_geteuid() !== 0, is_writable('readonly'), $first, $second, $r->canWriteCache('readonly/cache.php'), $before === glob(sys_get_temp_dir() . '/.sympress-private-*'), glob('readonly/*')]);
PHP);
            self::assertSame([true, false, false, false, false, true, []], $actual);
        } finally {
            chmod($this->root . '/readonly', 0755);
        }
    }
}
