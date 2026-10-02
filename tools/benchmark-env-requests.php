<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Filesystem\ProjectBoundary;
use SymPress\Runtime\Generation\RuntimeBundleBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__) . '/build/env-benchmark';
(new Filesystem())->remove($root);
mkdir($root, 0700, true);
$source = "WP_ENVIRONMENT_TYPE=production\n";
foreach (range(1, 1000) as $index) {
    $source .= 'RTV_SAFE_' . $index . '=' . str_repeat('x', 64) . "\n";
}
$source .= 'RTV_DERIVED=$RTV_PROCESS_SECRET' . "\n";
file_put_contents($root . '/.env', $source);
$paths = new Paths($root);
$bundle = (new RuntimeBundleBuilder($paths, new ProjectBoundary($paths), true))->build();
$parserFile = dirname($bundle->loader) . '/Dotenv/Dotenv.php';
$parser = file_get_contents($parserFile);
$parser = preg_replace('/(public function parse\(string \$data, string \$path = [^\n]+\): array\s*\{)/', '$1' . "\n        \$GLOBALS['benchmark_parse_calls'] = (\$GLOBALS['benchmark_parse_calls'] ?? 0) + 1;\n        \$GLOBALS['benchmark_parse_bytes'] = (\$GLOBALS['benchmark_parse_bytes'] ?? 0) + strlen(\$data);", (string) $parser, 1, $replaced);
if ($replaced !== 1) {
    throw new RuntimeException('Cannot instrument the benchmark parser.');
}
file_put_contents($parserFile, $parser);
$router = <<<'PHP'
<?php
$class = require LOADER;
chdir(__DIR__);
putenv('RTV_PROCESS_SECRET=' . (isset($_GET['rotate']) ? 'changed-secret' : 'initial-secret'));
$start = hrtime(true);
$reader = $class::buildFromCacheDump('cache.php', validateSources: true);
$hit = $reader->hasCachedValues();
$reader->loadChain();
$cached = is_file('cache.php') || $reader->dumpCached('cache.php');
$dumped = is_file('dump.php') || $reader->dumpCached('dump.php', immutable: true);
echo json_encode(['hit'=>$hit,'cached'=>$cached,'dumped'=>$dumped,'parse_calls'=>$GLOBALS['benchmark_parse_calls'] ?? 0,'parse_bytes'=>$GLOBALS['benchmark_parse_bytes'] ?? 0,'milliseconds'=>(hrtime(true)-$start)/1e6,'safe'=>$reader->read('RTV_SAFE_1000') !== null,'derived'=>$reader->read('RTV_DERIVED')]);
PHP;
file_put_contents($root . '/router.php', str_replace('LOADER', var_export($bundle->loader, true), $router));
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error, $errno);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = new Process([PHP_BINARY, '-S', (string) $address, $root . '/router.php']);
$server->start();
try {
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        if (str_contains($server->getErrorOutput(), 'Development Server')) {
            break;
        }
        usleep(10000);
    }
    $phases = [];
    foreach (['warm' => '', 'rotated' => '?rotate=1'] as $name => $query) {
        $requests = [];
        for ($index = 0; $index < 21; ++$index) {
            $start = hrtime(true);
            $content = file_get_contents('http://' . $address . '/' . $query);
            if ($content === false) {
                throw new RuntimeException('Benchmark HTTP request failed.');
            }
            try {
                $result = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(strip_tags($content), previous: $exception);
            }
            $result['http_ms'] = (hrtime(true) - $start) / 1e6;
            if ($index > 0) {
                $requests[] = $result;
            }
        }
        $latencies = array_column($requests, 'milliseconds');
        $http = array_column($requests, 'http_ms');
        sort($latencies);
        sort($http);
        $phases[$name] = [
            'requests' => count($requests),
            'cache_hits' => count(array_filter($requests, static fn (array $request): bool => $request['hit'])),
            'dump_created' => $requests[0]['dumped'],
            'parse_calls_per_request' => array_sum(array_column($requests, 'parse_calls')) / count($requests),
            'parse_bytes_per_request' => array_sum(array_column($requests, 'parse_bytes')) / count($requests),
            'env_ms_p50' => $latencies[9],
            'env_ms_p95' => $latencies[18],
            'http_ms_p50' => $http[9],
            'http_ms_p95' => $http[18],
        ];
    }
    echo json_encode(['php' => PHP_VERSION, 'phases' => $phases], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $server->stop();
    (new Filesystem())->remove($root);
}
