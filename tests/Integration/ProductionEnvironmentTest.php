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

    public function testProcessSecretInterpolationCachesTemplatesWithoutPersistingSecrets(): void
    {
        $this->write('.env', 'RTV_DERIVED=${RTV_PROCESS_SECRET}' . "\n");
        self::assertSame(['external-secret', true, true, true, true, false, 0600], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=external-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cached = $r->dumpCached('cache.php');
echo json_encode([$r->read('RTV_DERIVED'), $cached, $r->dumpCached('dump.php', immutable: true), is_file('cache.php'), is_file('dump.php'), $cached && str_contains(file_get_contents('cache.php'), 'external-secret'), $cached ? fileperms('cache.php') & 0777 : null]);
PHP));
    }

    public function testInterpolationRefreshPreservesSafeCacheForChangedMissingOrRemovedProcessValues(): void
    {
        $this->write('.env', 'WP_HOME=https://file.example' . "\n" . 'WP_SITEURL=${WP_HOME}/wp' . "\n" . 'RTV_CHAIN=${WP_SITEURL}/admin' . "\n" . 'RTV_DEFAULT=${RTV_OPTIONAL:-fallback}' . "\n");
        $this->write('.env.production', 'RTV_APPENDED=${RTV_CHAIN}/tail' . "\n");
        self::assertSame([true], $this->runPhp(<<<'PHP'
putenv('WP_HOME=https://process.example');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php')]);
PHP));
        $load = <<<'PHP'
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
$cached = $r->hasCachedValues(); $r->loadChain();
echo json_encode([$cached, class_exists(Symfony\Component\Dotenv\Dotenv::class, false), $r->read('WP_SITEURL'), $r->read('RTV_APPENDED'), $r->read('RTV_DEFAULT')]);
PHP;
        self::assertSame([true, false, 'https://process.example/wp', 'https://process.example/wp/admin/tail', 'fallback'], $this->runPhp("putenv('WP_HOME=https://process.example');" . $load));
        self::assertSame([true, true, 'https://changed.example/wp', 'https://changed.example/wp/admin/tail', 'fallback'], $this->runPhp("putenv('WP_HOME=https://changed.example');" . $load));
        self::assertSame([true, true, 'https://process.example/wp', 'https://process.example/wp/admin/tail', 'present'], $this->runPhp("putenv('WP_HOME=https://process.example'); putenv('RTV_OPTIONAL=present');" . $load));
        self::assertSame([true, true, 'https://file.example/wp', 'https://file.example/wp/admin/tail', 'fallback'], $this->runPhp("putenv('WP_HOME'); unset(\$_ENV['WP_HOME'], \$_SERVER['WP_HOME']);" . $load));
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

    public function testInterpolationTemplatesPreserveQuotingEscapesEmptyDefaultsAndTypedValues(): void
    {
        $this->write('.env', <<<'ENV'
RTV_DERIVED="$RTV_PROCESS_SECRET/suffix"
RTV_LITERAL='$RTV_PROCESS_SECRET'
RTV_ESCAPED=\$RTV_PROCESS_SECRET
RTV_EMPTY=${RTV_EMPTY_EXTERNAL:-fallback}
WP_DEBUG=$RTV_PROCESS_BOOL
ENV);
        $setup = <<<'PHP'
putenv('RTV_PROCESS_SECRET=backslash\and$dollar');
putenv('RTV_PROCESS_BOOL=true'); putenv('RTV_EMPTY_EXTERNAL=');
PHP;
        self::assertSame([true, false], $this->runPhp($setup . <<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php'), str_contains(file_get_contents('cache.php'), 'backslash')]);
PHP));
        self::assertSame([true, 'backslash\and$dollar/suffix', '$RTV_PROCESS_SECRET', '$RTV_PROCESS_SECRET', 'fallback', true, false], $this->runPhp($setup . <<<'PHP'
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
$r->loadChain();
echo json_encode([$r->hasCachedValues(), $r->read('RTV_DERIVED'), $r->read('RTV_LITERAL'), $r->read('RTV_ESCAPED'), $r->read('RTV_EMPTY'), $r->read('WP_DEBUG'), class_exists(Symfony\Component\Dotenv\Dotenv::class, false)]);
PHP));
    }

    public function testExplicitWritesReplacePreviouslyInterpolatedCacheTemplates(): void
    {
        $this->write('.env', 'RTV_DERIVED=$RTV_PROCESS_SECRET' . "\n");
        self::assertSame([true, 'explicit'], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=external-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$r->write('RTV_DERIVED', 'explicit');
$cached = $r->dumpCached('cache.php');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
echo json_encode([$cached, $r->read('RTV_DERIVED')]);
PHP));
    }

    public function testCommandOverridesReplaceTemplatesBeforeLaterFileInterpolation(): void
    {
        $this->write('.env', 'RTV_BASE=$RTV_EXTERNAL' . "\n");
        $this->write('.env.local', 'RTV_BASE=$(printf changed)' . "\n");
        $this->write('.env.production', 'RTV_DERIVED=$RTV_BASE' . "\n");
        self::assertSame(['changed', 'changed', true], $this->runPhp(<<<'PHP'
putenv('RTV_EXTERNAL=external-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->read('RTV_BASE'), $r->read('RTV_DERIVED'), $r->dumpCached('cache.php')]);
PHP));
    }

    public function testProcessDerivedEnvironmentSelectionDoesNotPersistSecretsInMetadata(): void
    {
        $this->write('.env', 'WP_ENVIRONMENT_TYPE=$RTV_PROCESS_SECRET' . "\n");
        self::assertSame([false, false, false], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=private-stage');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php'), $r->dumpCached('dump.php', immutable: true), is_file('cache.php')]);
PHP));
    }

    public function testPartialDumpFreezesSafeValuesAndResolvesOnlyProcessExpressionsLive(): void
    {
        $this->write('.env', 'RTV_SAFE=original' . "\n" . 'RTV_DERIVED=${RTV_PROCESS_SECRET:-fallback}' . "\n");
        self::assertSame([true, true, true, true], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=first-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cache = $r->dumpCached('cache.php'); $dump = $r->dumpCached('dump.php', immutable: true);
$data = SymPress\Runtime\Env\EnvCacheFormat::read('dump.php');
echo json_encode([$cache, $dump, isset($data['values']['RTV_SAFE']) && !isset($data['values']['RTV_DERIVED']), !str_contains(file_get_contents('dump.php'), 'first-secret')]);
PHP));
        $this->write('.env', "RTV_SAFE=replaced\n");
        $load = <<<'PHP'
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('dump.php');
echo json_encode([$r->hasCachedValues(), $r->read('RTV_SAFE'), $r->read('RTV_DERIVED')]);
PHP;
        self::assertSame([true, 'original', 'rotated-secret'], $this->runPhp("putenv('RTV_PROCESS_SECRET=rotated-secret');" . $load));
        self::assertSame([true, 'original', 'fallback'], $this->runPhp("putenv('RTV_PROCESS_SECRET');" . $load));
    }

    public function testCommandResultsAndTransitiveResultsStayLiveWhileUnrelatedKeysAreCached(): void
    {
        $this->write('.env', 'RTV_SAFE=original' . "\n" . 'RTV_COMMAND=$(printf %s "$RTV_PROCESS_SECRET")' . "\n" . 'RTV_DERIVED=${RTV_COMMAND}/suffix' . "\n");
        self::assertSame([true, true, true], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=first-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cached = $r->dumpCached('cache.php'); $dumped = $r->dumpCached('dump.php', immutable: true);
$data = SymPress\Runtime\Env\EnvCacheFormat::read('cache.php');
echo json_encode([$cached, $dumped, !isset($data['values']['RTV_COMMAND']) && !isset($data['values']['RTV_DERIVED']) && isset($data['values']['RTV_SAFE']) && !str_contains(file_get_contents('cache.php') . file_get_contents('dump.php'), 'first-secret')]);
PHP));
        self::assertSame([true, 'original', 'rotated-secret', 'rotated-secret/suffix'], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=rotated-secret');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
echo json_encode([$r->hasCachedValues(), $r->read('RTV_SAFE'), $r->read('RTV_COMMAND'), $r->read('RTV_DERIVED')]);
PHP));
    }

    public function testDynamicRefreshPreservesEarlierFileDefaultsAndMultilineQuoting(): void
    {
        $this->write('.env', 'RTV_BASE=first' . "\n" . 'RTV_MULTILINE="line1' . "\n" . 'RTV_FAKE=inside $RTV_PROCESS_SECRET' . "\n" . 'line3"' . "\n" . 'RTV_DERIVED=$RTV_BASE/$RTV_PROCESS_SECRET' . "\n");
        $this->write('.env.production', "RTV_BASE=second\n");
        self::assertSame([true], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=old');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php')]);
PHP));
        self::assertSame(['second', 'first/new', "line1\nRTV_FAKE=inside new\nline3"], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=new');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
echo json_encode([$r->read('RTV_BASE'), $r->read('RTV_DERIVED'), $r->read('RTV_MULTILINE')]);
PHP));
    }

    public function testLaterSafeCommandOverrideDoesNotPersistEarlierDerivedSecret(): void
    {
        $this->write('.env', 'RTV_COMMAND=$(printf %s "$RTV_PROCESS_SECRET")' . "\n" . 'RTV_DERIVED=$RTV_COMMAND' . "\n");
        $this->write('.env.production', "RTV_COMMAND=safe\nRTV_UNRELATED=static\n");
        self::assertSame([true, true, 'safe', 'rotated'], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=initial-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$dump = $r->dumpCached('dump.php', immutable: true);
$safe = !str_contains(file_get_contents('dump.php'), 'initial-secret');
putenv('RTV_PROCESS_SECRET=rotated');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('dump.php');
echo json_encode([$dump, $safe, $r->read('RTV_COMMAND'), $r->read('RTV_DERIVED')]);
PHP));
    }

    public function testDynamicEnvironmentSelectorsCannotPersistASelectedChain(): void
    {
        foreach (['WP_ENVIRONMENT_TYPE=${RTV_OPTIONAL:-production}', 'WP_ENVIRONMENT_TYPE=$(printf production)'] as $content) {
            $this->write('.env', $content . "\nRTV_SAFE=static\n");
            self::assertSame([false, false], $this->runPhp(<<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php'), $r->dumpCached('dump.php', immutable: true)]);
PHP));
        }
    }

    public function testMixedCommandsAndImplicitDefaultAssignmentsNeverPersistDerivedBytes(): void
    {
        $this->write('.env', 'RTV_SAFE=static' . "\n" . 'RTV_COMMAND=${RTV_ASSIGNED:=$(printf %s "$RTV_PROCESS_SECRET")}' . "\n" . 'RTV_OTHER=$RTV_PROCESS_SECRET' . "\n" . 'RTV_TRANSITIVE=$RTV_ASSIGNED' . "\n");
        self::assertSame([true, true, 'rotated', 'rotated', 'rotated', 'rotated'], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=initial-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$dump = $r->dumpCached('dump.php', immutable: true);
$safe = !str_contains(file_get_contents('dump.php'), 'initial-secret');
unset($_ENV['RTV_ASSIGNED'], $_SERVER['RTV_ASSIGNED']);
putenv('RTV_PROCESS_SECRET=rotated');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('dump.php');
echo json_encode([$dump, $safe, $r->read('RTV_COMMAND'), $r->read('RTV_ASSIGNED'), $r->read('RTV_OTHER'), $r->read('RTV_TRANSITIVE')]);
PHP));
    }

    public function testEmptyVariableOverrideClearsEarlierCommandValue(): void
    {
        $this->write('.env', 'RTV_COMMAND=$(printf %s "$RTV_PROCESS_SECRET")' . "\n" . 'RTV_DERIVED=$RTV_COMMAND' . "\n");
        $this->write('.env.production', "RTV_COMMAND=\nRTV_SAFE=static\n");
        self::assertSame([true, '', 'rotated'], $this->runPhp(<<<'PHP'
putenv('RTV_PROCESS_SECRET=initial-secret');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$dump = $r->dumpCached('dump.php', immutable: true);
putenv('RTV_PROCESS_SECRET=rotated');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('dump.php');
echo json_encode([$dump, $r->read('RTV_COMMAND'), $r->read('RTV_DERIVED')]);
PHP));
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

    public function testBundledInterpolationCacheUsesLiveProcessDependenciesWithoutLoadingParser(): void
    {
        $this->write('.env', 'WP_HOME=https://file.example' . "\n" . 'WP_SITEURL=${WP_HOME}/wp' . "\n");
        self::assertSame([true], $this->runPhp(<<<'PHP'
putenv('WP_HOME=https://process.example');
$paths = new SymPress\Runtime\Filesystem\Paths(getcwd());
$boundary = new SymPress\Runtime\Filesystem\ProjectBoundary($paths);
$bundle = (new SymPress\Runtime\Generation\RuntimeBundleBuilder($paths, $boundary, true))->build();
file_put_contents('loader-path.txt', $bundle->loader);
$class = require $bundle->loader;
$r = new $class(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php')]);
PHP));
        $load = <<<'PHP'
$class = require file_get_contents('loader-path.txt');
$r = $class::buildFromCacheDump('cache.php', validateSources: true, producer: $class);
$cached = $r->hasCachedValues(); $r->loadChain();
$parser = str_replace('Env\\EnvReader', 'Dotenv\\Dotenv', $class);
echo json_encode([$cached, $r->read('WP_SITEURL'), class_exists($parser, false)]);
PHP;
        self::assertSame([true, 'https://process.example/wp', false], $this->runPhp("putenv('WP_HOME=https://process.example');" . $load));
        self::assertSame([true, 'https://changed.example/wp', true], $this->runPhp("putenv('WP_HOME=https://changed.example');" . $load));
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
