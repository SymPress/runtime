<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class ProcessInterpolationBoundaryTest extends TemporaryProject
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

    public function testEstablishedProcessValuesKeepInterpolationPrecedenceAcrossFileOverrides(): void
    {
        $this->write('.env', "RTV_ROOT=base-file\nRTV_SAFE=static\n");
        $this->write('.env.local', "RTV_ROOT=local-file\n" . 'RTV_DERIVED=$RTV_ROOT' . "\n");
        self::assertSame(['real-process', 'real-process', true, true, 0, 0], $this->runPhp(<<<'PHP'
unset($_ENV['RTV_ROOT'], $_SERVER['RTV_ROOT']); putenv('RTV_ROOT=real-process');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cache = $r->dumpCached('cache.php'); $dump = $r->dumpCached('dump.php', immutable: true);
$bytes = static fn (string $path): int => substr_count(file_get_contents($path), 'real-process');
echo json_encode([$r->read('RTV_ROOT'), $r->read('RTV_DERIVED'), $cache, $dump, $bytes('cache.php'), $bytes('dump.php')]);
PHP));
        self::assertSame([true, 'rotated-process', 'rotated-process', 'static'], $this->runPhp(<<<'PHP'
unset($_ENV['RTV_ROOT'], $_SERVER['RTV_ROOT']); putenv('RTV_ROOT=rotated-process');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
echo json_encode([$r->hasCachedValues(), $r->read('RTV_ROOT'), $r->read('RTV_DERIVED'), $r->read('RTV_SAFE')]);
PHP));
    }

    public function testInterpolationTracksProcessEnvAndServerOriginsWithoutTrustingHttpHeaders(): void
    {
        foreach (['RTV_REVIEW_SECRET', 'HTTP_REVIEW_SECRET'] as $name) {
            $this->write('.env', 'RTV_DERIVED=${' . $name . ':-fallback}' . "\nRTV_SAFE=static\n");
            foreach (['process', 'env', 'server'] as $origin) {
                $setup = '$name = ' . var_export($name, true) . '; $origin = ' . var_export($origin, true) . ';' . <<<'PHP'
unset($_ENV[$name], $_SERVER[$name]); putenv($name);
$secret = 'review-origin-secret-83da';
if ($origin === 'process') { putenv($name . '=' . $secret); }
if ($origin === 'env') { $_ENV[$name] = $secret; }
if ($origin === 'server') { $_SERVER[$name] = $secret; }
PHP;
                $trusted = $name !== 'HTTP_REVIEW_SECRET' || $origin !== 'server';
                $direct = $name === 'HTTP_REVIEW_SECRET' && $origin !== 'env' ? null : 'review-origin-secret-83da';
                self::assertSame([$direct, $trusted ? 'review-origin-secret-83da' : 'fallback', true, true, 0, 0, 'static'], $this->runPhp($setup . <<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cache = $r->dumpCached('cache.php'); $dump = $r->dumpCached('dump.php', immutable: true);
$bytes = static fn (string $file): int => substr_count(file_get_contents($file), $secret);
echo json_encode([$r->read($name), $r->read('RTV_DERIVED'), $cache, $dump, $bytes('cache.php'), $bytes('dump.php'), $r->read('RTV_SAFE')]);
PHP));
            }
        }
    }

    public function testProcessOnlyHttpInterpolationRefreshesWhileTrustedFileValueWins(): void
    {
        $this->write('.env', 'RTV_DERIVED=${HTTP_REVIEW_SECRET:-fallback}' . "\nRTV_SAFE=static\n");
        self::assertSame([true], $this->runPhp(<<<'PHP'
unset($_ENV['HTTP_REVIEW_SECRET'], $_SERVER['HTTP_REVIEW_SECRET']);
putenv('HTTP_REVIEW_SECRET=first-process');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php')]);
PHP));
        self::assertSame([true, 'rotated-process', 'static', null], $this->runPhp(<<<'PHP'
unset($_ENV['HTTP_REVIEW_SECRET'], $_SERVER['HTTP_REVIEW_SECRET']);
putenv('HTTP_REVIEW_SECRET=rotated-process');
$r = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('cache.php', validateSources: true);
echo json_encode([$r->hasCachedValues(), $r->read('RTV_DERIVED'), $r->read('RTV_SAFE'), $r->read('HTTP_REVIEW_SECRET')]);
PHP));
        $this->write('.env', "HTTP_REVIEW_SECRET=trusted-file\n" . 'RTV_DERIVED=$HTTP_REVIEW_SECRET' . "\nRTV_SAFE=static\n");
        self::assertSame(['trusted-file', 'trusted-file', true, true, false], $this->runPhp(<<<'PHP'
unset($_ENV['HTTP_REVIEW_SECRET'], $_SERVER['HTTP_REVIEW_SECRET']);
putenv('HTTP_REVIEW_SECRET=must-not-override-file');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cache = $r->dumpCached('cache.php'); $dump = $r->dumpCached('dump.php', immutable: true);
echo json_encode([$r->read('HTTP_REVIEW_SECRET'), $r->read('RTV_DERIVED'), $cache, $dump, str_contains(file_get_contents('cache.php') . file_get_contents('dump.php'), 'must-not-override-file')]);
PHP));
    }

    public function testHistoricalHttpProcessOriginSurvivesLaterFileOverrideAndRotation(): void
    {
        $this->write('.env', 'RTV_EARLY=$HTTP_REVIEW_SECRET' . "\n" . 'RTV_TRANSITIVE=$RTV_EARLY' . "\nRTV_SAFE=static\n");
        $this->write('.env.local', "HTTP_REVIEW_SECRET=trusted-file\n");
        $this->write('.env.production', 'RTV_LATE=$HTTP_REVIEW_SECRET' . "\n");
        self::assertSame([true, true, 0, 0, false, false, 'trusted-file', 'static'], $this->runPhp(<<<'PHP'
unset($_ENV['HTTP_REVIEW_SECRET'], $_SERVER['HTTP_REVIEW_SECRET']);
putenv('HTTP_REVIEW_SECRET=review-http-chain-secret-d517');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cache = $r->dumpCached('cache.php'); $dump = $r->dumpCached('dump.php', immutable: true);
$bytes = static fn (string $file): int => substr_count(file_get_contents($file), 'review-http-chain-secret-d517');
$data = SymPress\Runtime\Env\EnvCacheFormat::read('dump.php');
echo json_encode([$cache, $dump, $bytes('cache.php'), $bytes('dump.php'), isset($data['values']['RTV_EARLY']), isset($data['values']['RTV_TRANSITIVE']), $data['values']['RTV_LATE'][1] ?? null, $data['values']['RTV_SAFE'][1] ?? null]);
PHP));
        $cold = $this->runPhp(<<<'PHP'
unset($_ENV['HTTP_REVIEW_SECRET'], $_SERVER['HTTP_REVIEW_SECRET']);
putenv('HTTP_REVIEW_SECRET=rotated-process');
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode($r->readMany('RTV_EARLY', 'RTV_TRANSITIVE', 'RTV_LATE', 'RTV_SAFE'));
PHP);
        self::assertSame(['RTV_EARLY' => 'rotated-process', 'RTV_TRANSITIVE' => 'rotated-process', 'RTV_LATE' => 'trusted-file', 'RTV_SAFE' => 'static'], $cold);
        foreach (['cache.php', 'dump.php'] as $file) {
            self::assertSame([true, $cold], $this->runPhp('unset($_ENV["HTTP_REVIEW_SECRET"], $_SERVER["HTTP_REVIEW_SECRET"]);putenv("HTTP_REVIEW_SECRET=rotated-process");$r = SymPress\\Runtime\\Env\\EnvReader::buildFromCacheDump(' . var_export($file, true) . ', validateSources: ' . ($file === 'cache.php' ? 'true' : 'false') . ');' . <<<'PHP'
echo json_encode([$r->hasCachedValues(), $r->readMany('RTV_EARLY', 'RTV_TRANSITIVE', 'RTV_LATE', 'RTV_SAFE')]);
PHP));
        }
    }

    public function testInheritedDotenvOwnershipCannotPersistExternalSecretBytes(): void
    {
        foreach (['RTV_REVIEW_SECRET', 'HTTP_REVIEW_SECRET'] as $name) {
            $this->write('.env', 'RTV_DERIVED=$' . $name . "\nRTV_SAFE=static\n");
            foreach (['process', 'env', 'process-env', 'server'] as $origin) {
                $setup = '$name = ' . var_export($name, true) . '; $origin = ' . var_export($origin, true) . ';' . <<<'PHP'
unset($_ENV[$name], $_SERVER[$name]); putenv($name);
$secret = 'review-marked-secret-e2f8';
$_ENV['SYMFONY_DOTENV_VARS'] = $_SERVER['SYMFONY_DOTENV_VARS'] = $name;
if ($origin === 'process' || $origin === 'process-env') { putenv($name . '=' . $secret); }
if ($origin === 'env' || $origin === 'process-env') { $_ENV[$name] = $secret; }
if ($origin === 'server') { $_SERVER[$name] = $secret; }
PHP;
                $derived = $name === 'HTTP_REVIEW_SECRET' && $origin === 'server' ? '' : 'review-marked-secret-e2f8';
                $direct = $name === 'HTTP_REVIEW_SECRET' && ($origin === 'process' || $origin === 'server') ? null : 'review-marked-secret-e2f8';
                self::assertSame([$direct, $derived, true, true, 0, 0, false, false, 'static'], $this->runPhp($setup . <<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cache = $r->dumpCached('cache.php'); $dump = $r->dumpCached('dump.php', immutable: true);
$bytes = static fn (string $file): int => substr_count(file_get_contents($file), $secret);
$data = SymPress\Runtime\Env\EnvCacheFormat::read('dump.php');
echo json_encode([$r->read($name), $r->read('RTV_DERIVED'), $cache, $dump, $bytes('cache.php'), $bytes('dump.php'), isset($data['values'][$name]), isset($data['values']['RTV_DERIVED']) && $r->read('RTV_DERIVED') === $secret, $r->read('RTV_SAFE')]);
PHP));
            }
        }
    }

    public function testSameFileDefinitionsEstablishOwnershipWithoutClearingEarlierProcessTaint(): void
    {
        foreach (['RTV_REVIEW_SECRET', 'HTTP_REVIEW_SECRET'] as $name) {
            $this->write('.env', 'RTV_EARLY=$' . $name . "\n" . $name . "=trusted-file\n" . 'RTV_LATE=$' . $name . "\nRTV_SAFE=static\n");
            self::assertSame([true, true, 0, 0, false, 'trusted-file', 'trusted-file', 'static'], $this->runPhp('$name = ' . var_export($name, true) . ';' . <<<'PHP'
unset($_ENV[$name], $_SERVER[$name]); putenv($name . '=review-same-file-secret');
if ($name === 'RTV_REVIEW_SECRET') {
    $_ENV[$name] = 'review-same-file-secret';
    $_ENV['SYMFONY_DOTENV_VARS'] = $_SERVER['SYMFONY_DOTENV_VARS'] = $name;
}
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
$cache = $r->dumpCached('cache.php'); $dump = $r->dumpCached('dump.php', immutable: true);
$bytes = static fn (string $file): int => substr_count(file_get_contents($file), 'review-same-file-secret');
$data = SymPress\Runtime\Env\EnvCacheFormat::read('dump.php');
echo json_encode([$cache, $dump, $bytes('cache.php'), $bytes('dump.php'), isset($data['values']['RTV_EARLY']), $data['values']['RTV_LATE'][1] ?? null, $data['values'][$name][1] ?? null, $data['values']['RTV_SAFE'][1] ?? null]);
PHP));
        }
    }

    public function testReplayRetainsInheritedEnvAndServerInputsBeforeLaterFileDefinitions(): void
    {
        foreach ([['RTV_ROOT', 'env'], ['RTV_ROOT', 'server'], ['HTTP_REVIEW_ROOT', 'env']] as [$name, $origin]) {
            $this->write('.env', 'RTV_EARLY=$' . $name . "\n" . $name . "=trusted-file\n" . 'RTV_LATE=$' . $name . "\nRTV_SAFE=static\n");
            $setup = '$name = ' . var_export($name, true) . '; $origin = ' . var_export($origin, true) . ';' . <<<'PHP'
unset($_ENV[$name], $_SERVER[$name]); putenv($name);
$_ENV['SYMFONY_DOTENV_VARS'] = $_SERVER['SYMFONY_DOTENV_VARS'] = $name;
if ($origin === 'env') { $_ENV[$name] = 'review-original-secret'; }
if ($origin === 'server') { $_SERVER[$name] = 'review-original-secret'; }
PHP;
            self::assertSame([true, true], $this->runPhp($setup . <<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode([$r->dumpCached('cache.php'), $r->dumpCached('dump.php', immutable: true)]);
PHP));
            $rotated = str_replace('review-original-secret', 'review-rotated-secret', $setup);
            $cold = $this->runPhp($rotated . <<<'PHP'
$r = new SymPress\Runtime\Env\EnvReader(); $r->loadChain();
echo json_encode($r->readMany('RTV_EARLY', 'RTV_LATE', 'RTV_SAFE'));
PHP);
            self::assertSame(['RTV_EARLY' => 'review-rotated-secret', 'RTV_LATE' => 'trusted-file', 'RTV_SAFE' => 'static'], $cold);
            foreach (['cache.php', 'dump.php'] as $file) {
                self::assertSame([true, $cold], $this->runPhp($rotated . '$r = SymPress\\Runtime\\Env\\EnvReader::buildFromCacheDump(' . var_export($file, true) . ', validateSources: ' . ($file === 'cache.php' ? 'true' : 'false') . ');' . <<<'PHP'
echo json_encode([$r->hasCachedValues(), $r->readMany('RTV_EARLY', 'RTV_LATE', 'RTV_SAFE')]);
PHP));
            }
        }
    }
}
