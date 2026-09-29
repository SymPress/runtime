<?php

declare(strict_types=1);

namespace SymPress\Runtime\Kernel;

use PhpToken;
use RuntimeException;
use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Package\MuPluginList;
use SymPress\Runtime\Package\PackageFinder;

final readonly class BootOwnership
{
    public function __construct(private PackageFinder $packages, private MuPluginList $muPlugins, private Config $config, private Paths $paths)
    {
    }

    /** @return list<string> */
    public function providers(): array
    {
        $providers = [];
        $declaredPaths = [];
        foreach ($this->packages->all() as $package) {
            $metadata = $package->getExtra()['sympress-runtime'] ?? [];
            if (!is_array($metadata) || ($metadata['boots-kernel'] ?? false) !== true) {
                continue;
            }
            $providers[] = $package->getName();
            $declaredPaths[] = rtrim(realpath($package->getInstallPath()) ?: $package->getInstallPath(), '/') . '/';
        }
        $files = [...array_values($this->muPlugins->pluginsList($this->config)), ...(glob($this->paths->wpContent('mu-plugins/*.php')) ?: [])];
        $seen = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === false || isset($seen[$real]) || $real === $this->generatedFile() || array_any($declaredPaths, static fn (string $directory): bool => str_starts_with($real, $directory))) {
                continue;
            }
            $seen[$real] = true;
            $source = file_get_contents($real);
            if ($source === false) {
                throw new RuntimeException('Cannot inspect a kernel boot candidate.');
            }
            $code = '';
            foreach (PhpToken::tokenize($source) as $token) {
                if (in_array($token->id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $code .= $token->text;
            }
            if (!preg_match('/\bbootKernel\s*\(/i', $code)) {
                continue;
            }
            if (!str_contains($code, 'SymPress\\Kernel\\App')) {
                throw new RuntimeException('Ambiguous kernel boot call in ' . basename($file) . '; declare extra.sympress-runtime.boots-kernel on its package.');
            }
            $providers[] = $real;
        }
        if (count($providers) > 1) {
            throw new RuntimeException('Multiple kernel boot providers were found. Keep exactly one boot owner.');
        }

        return $providers;
    }

    public function generatedFile(): string
    {
        return $this->paths->wpContent('mu-plugins/sympress-runtime-kernel.php');
    }
}
