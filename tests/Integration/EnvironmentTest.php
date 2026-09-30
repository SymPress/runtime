<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use SymPress\Runtime\Tests\Support\TemporaryProject;
use Symfony\Component\Process\Process;

final class EnvironmentTest extends TemporaryProject
{
    /**
     * @param array<string, string|false> $environment
     * @return array<array-key, mixed>
     */
    private function runPhp(string $code, array $environment = []): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . ';' . $code;
        $defaults = array_fill_keys(['WP_ENV', 'WP_ENVIRONMENT_TYPE', 'WORDPRESS_ENV', 'WPSTARTER_ENV_LOADED', 'SYMPRESS_RUNTIME_ENV_LOADED', 'SYMFONY_DOTENV_VARS', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'WP_DEBUG', 'WP_POST_REVISIONS'], false);
        $process = new Process([PHP_BINARY, '-r', $script], $this->root, array_replace($defaults, $environment));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Group('PAR-ENV-001')]
    #[Group('PAR-ENV-004')]
    #[Group('PAR-ENV-006')]
    public function testNativeChainRealEnvironmentInterpolationAndStableEnvironmentSelection(): void
    {
        $this->write('.env', "WP_ENVIRONMENT_TYPE=staging\nRTV_LAYER=base\nRTV_REAL=from-file\nRTV_EXPAND=\"\${RTV_REAL}/base\"\n");
        $this->write('.env.local', "RTV_LAYER=local\nRTV_LOCAL=yes\n");
        $this->write('.env.staging', "RTV_LAYER=stage\nWP_ENVIRONMENT_TYPE=production\n");
        $this->write('.env.staging.local', "RTV_LAYER=stage-local\n");
        $this->write('.env.production.local', "RTV_LAYER=wrong-chain\n");
        $actual = $this->runPhp(<<<'PHP'
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->loadChain();
echo json_encode([$reader->determineEnvType(), $reader->readMany('RTV_LAYER', 'RTV_REAL', 'RTV_EXPAND', 'RTV_LOCAL'), $_ENV['RTV_LAYER'], $_SERVER['RTV_LAYER']]);
PHP
, ['RTV_REAL' => 'actual']);
        self::assertSame(['staging', ['RTV_LAYER' => 'stage-local', 'RTV_REAL' => 'actual', 'RTV_EXPAND' => 'actual/base', 'RTV_LOCAL' => 'yes'], 'stage-local', 'stage-local'], $actual);
    }

    #[Group('PAR-ENV-006')]
    public function testTestEnvironmentSkipsGenericLocalFileAndLegacyProfileKeepsTwoFiles(): void
    {
        $this->write('.env', "WP_ENV=test\nRTV_VALUE=base\n");
        $this->write('.env.local', "RTV_LOCAL=forbidden\n");
        $this->write('.env.test', "RTV_VALUE=test\n");
        $this->write('.env.test.local', "RTV_VALUE=test-local\n");
        $native = $this->runPhp('$reader = new SymPress\\Runtime\\Env\\EnvReader(); $reader->loadChain(); echo json_encode($reader->readMany("RTV_VALUE", "RTV_LOCAL"));');
        self::assertSame(['RTV_VALUE' => 'test-local', 'RTV_LOCAL' => null], $native);
        $legacy = $this->runPhp('$reader = new SymPress\\Runtime\\Env\\EnvReader(profile: "release-3.0.1"); $reader->loadChain(localOverrides: false); echo json_encode($reader->readMany("RTV_VALUE", "RTV_LOCAL"));');
        self::assertSame(['RTV_VALUE' => 'test', 'RTV_LOCAL' => null], $legacy);
    }

    #[Group('PAR-ENV-002')]
    public function testActualValuesAreImmutableWhileOwnedValuesCanChangeAndHeadersAreIgnored(): void
    {
        $this->write('.env', "RTV_OWNED=first\nHTTP_OWNED=trusted-file\nWP_DEBUG=not-a-boolean\n");
        $actual = $this->runPhp(<<<'PHP'
$_SERVER['HTTP_FAKE'] = 'untrusted-header';
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->load();
$reader->write('RTV_OWNED', 'second');
$blocked = false;
try { $reader->write('RTV_REAL', 'replace'); } catch (BadMethodCallException) { $blocked = true; }
echo json_encode([$blocked, $reader->readMany('RTV_REAL', 'RTV_OWNED', 'HTTP_FAKE', 'HTTP_OWNED'), $reader->has('WP_DEBUG'), isset($_SERVER['HTTP_OWNED'])]);
PHP
, ['RTV_REAL' => 'actual']);
        self::assertSame([true, ['RTV_REAL' => 'actual', 'RTV_OWNED' => 'second', 'HTTP_FAKE' => null, 'HTTP_OWNED' => 'trusted-file'], false, false], $actual);
    }

    #[Group('PAR-ENV-005')]
    public function testLoadedMarkersBypassEvenMalformedMainAndAppendedFiles(): void
    {
        $this->write('.env', 'malformed main');
        $this->write('.env.local', 'malformed local');
        $actual = $this->runPhp(<<<'PHP'
$_ENV['WPSTARTER_ENV_LOADED'] = false;
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->loadChain();
$reader->loadAppended('.env.local');
echo json_encode([$reader->determineEnvType(), $reader->read('RTV_MISSING')]);
PHP);
        self::assertSame(['production', null], $actual);
    }

    #[Group('PAR-ENV-010')]
    #[Group('PAR-ENV-017')]
    public function testTypedCustomAndExistingConstantsAreSetOnce(): void
    {
        $this->write('.env', "DB_NAME=fixture\nDB_USER=fixture\nDB_PASSWORD='raw <tag> & secret'\nWP_DEBUG=false\nWP_POST_REVISIONS=2\nSUNRISE=yes\nRTV_CUSTOM=12.9\nRTV_RAW=literal\nSYMPRESS_RUNTIME_ENV_TO_CONST=RTV_CUSTOM:INT,RTV_RAW:UNKNOWN\n");
        $actual = $this->runPhp(<<<'PHP'
define('WP_DEBUG', true);
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->loadChain();
$reader->setupConstants();
$reader->setupConstants();
echo json_encode([WP_DEBUG, WP_POST_REVISIONS, SUNRISE, RTV_CUSTOM, RTV_RAW, DB_PASSWORD, $reader->isWpSetup()]);
PHP);
        self::assertSame([true, 2, 'yes', 12, 'literal', 'raw <tag> & secret', true], $actual);
    }

    #[Group('PAR-ENV-018')]
    #[Group('PAR-ENV-019')]
    public function testCacheRestoresInAFreshProcessButRealValuesStillWin(): void
    {
        $this->write('.env', "WP_ENV=staging\nRTV_VALUE=file\nWP_DEBUG=false\nDB_NAME=fixture\nDB_USER=fixture\n");
        $created = $this->runPhp(<<<'PHP'
$reader = new SymPress\Runtime\Env\EnvReader();
$reader->loadChain();
$reader->setupConstants();
echo json_encode([$reader->dumpCached('.env.cached.php'), fileperms('.env.cached.php') & 0777]);
PHP);
        self::assertSame([true, 0600], $created);
        $this->write('.env', 'invalid and must not be parsed');
        $restored = $this->runPhp(<<<'PHP'
$reader = SymPress\Runtime\Env\EnvReader::buildFromCacheDump('.env.cached.php', environment: 'staging');
$reader->loadChain();
echo json_encode([$reader->hasCachedValues(), $reader->read('RTV_VALUE'), $_ENV['RTV_VALUE'] ?? null, $_SERVER['RTV_VALUE'] ?? null, WP_DEBUG, WP_ENVIRONMENT_TYPE, $reader->dumpCached('.env.cached.php')]);
PHP
, ['RTV_VALUE' => 'actual', 'WP_DEBUG' => 'true']);
        self::assertSame([true, 'actual', 'actual', 'actual', true, 'staging', false], $restored);
    }

    public function testParseDiagnosticsDoNotExposeEnvironmentContents(): void
    {
        $this->write('.env', 'RTV_SECRET="synthetic-secret');
        $actual = $this->runPhp(<<<'PHP'
try { (new SymPress\Runtime\Env\EnvReader())->load(); }
catch (RuntimeException $error) { echo json_encode([$error->getMessage(), $error->getPrevious() === null]); }
PHP);
        self::assertStringContainsString('Cannot parse environment file', $actual[0]);
        self::assertStringNotContainsString('synthetic-secret', $actual[0]);
        self::assertTrue($actual[1]);
    }

    public function testInvalidCacheIsRejectedBeforeHydrationAndEnvironmentMismatchFails(): void
    {
        $payload = ['format' => 1, 'profile' => 'native', 'environment' => 'staging', 'values' => ['RTV_PARTIAL' => ['must-not-load', 'must-not-load'], 'RTV_BAD' => 'invalid'], 'loaded' => [], 'types' => [], 'constants' => []];
        $header = "<?php\n// @generated by sympress/runtime env-cache:v1\nreturn ";
        $this->write('.env.cached.php', $header . var_export($payload, true) . ';');
        $actual = $this->runPhp(<<<'PHP'
try { SymPress\Runtime\Env\EnvReader::buildFromCacheDump('.env.cached.php'); }
catch (RuntimeException $error) { echo json_encode([$error->getMessage(), isset($_ENV['RTV_PARTIAL'])]); }
PHP);
        self::assertSame(['Environment cache value is invalid.', false], $actual);
        $payload['values'] = ['RTV_PARTIAL' => ['cached', 'cached']];
        $this->write('.env.cached.php', $header . var_export($payload, true) . ';');
        $mismatch = $this->runPhp(<<<'PHP'
try { SymPress\Runtime\Env\EnvReader::buildFromCacheDump('.env.cached.php'); }
catch (RuntimeException $error) { echo json_encode([$error->getMessage(), isset($_ENV['RTV_PARTIAL'])]); }
PHP
, ['WP_ENVIRONMENT_TYPE' => 'production']);
        self::assertSame(['Environment cache does not match the requested environment.', false], $mismatch);
    }
}
