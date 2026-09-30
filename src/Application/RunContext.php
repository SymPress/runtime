<?php

declare(strict_types=1);

namespace SymPress\Runtime\Application;

use InvalidArgumentException;

/**
 * Serializable process boundary; no framework or live Composer objects.
 *
 * @internal
 */
final readonly class RunContext
{
    /** @param list<array{name: string, version: string}> $updatedPackages */
    public function __construct(
        public string $root,
        public string $vendor,
        public string $bin,
        public string $mode = 'standalone',
        public bool $dev = true,
        public bool $interactive = false,
        public bool $decorated = false,
        public int $verbosity = 32,
        public array $updatedPackages = [],
        public string $manifest = 'composer.json',
    ) {
        if (!in_array($mode, ['standalone', 'command', 'install', 'update'], true)) {
            throw new InvalidArgumentException('Invalid runner mode.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'protocol' => 1,
        'root' => $this->root,
        'vendor' => $this->vendor,
            'bin' => $this->bin,
        'mode' => $this->mode,
        'dev' => $this->dev,
            'interactive' => $this->interactive,
        'decorated' => $this->decorated,
            'verbosity' => $this->verbosity,
        'updatedPackages' => $this->updatedPackages,
        'manifest' => $this->manifest,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['protocol'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported runner context protocol.');
        }
        foreach (['root', 'vendor', 'bin', 'mode'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || str_contains($data[$key], "\0")) {
                throw new InvalidArgumentException('Invalid runner context field: ' . $key);
            }
        }
        foreach (['dev', 'interactive', 'decorated'] as $key) {
            if (!isset($data[$key]) || !is_bool($data[$key])) {
                throw new InvalidArgumentException('Invalid runner context flag: ' . $key);
            }
        }
        if (!isset($data['verbosity']) || !is_int($data['verbosity']) || !in_array($data['verbosity'], [16, 32, 64, 128, 256], true)) {
            throw new InvalidArgumentException('Invalid runner verbosity.');
        }
        $packages = $data['updatedPackages'] ?? [];
        if (!is_array($packages) || !array_is_list($packages)) {
            throw new InvalidArgumentException('Invalid updated package list.');
        }
        $validated = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
                throw new InvalidArgumentException('Invalid updated package descriptor.');
            }
            $validated[] = ['name' => $package['name'], 'version' => $package['version']];
        }

        $manifest = $data['manifest'] ?? 'composer.json';
        if (!is_string($manifest) || $manifest === '' || str_contains($manifest, "\0")) {
            throw new InvalidArgumentException('Invalid runner manifest path.');
        }

        return new self($data['root'], $data['vendor'], $data['bin'], $data['mode'], $data['dev'], $data['interactive'], $data['decorated'], $data['verbosity'], $validated, $manifest);
    }

    /** @return array<string, mixed> */
    public function configuration(bool $selected): array
    {
        $command = in_array($this->mode, ['command', 'standalone'], true);

        return [
            'is-composer-install' => $this->mode === 'install',
            'is-composer-update' => $this->mode === 'update',
            'is-runtime-command' => $command,
            'is-runtime-selected-command' => $selected,
            'is-wpstarter-command' => $command,
            'is-wpstarter-selected-command' => $selected,
            'composer-updated-packages' => $this->updatedPackages,
        ];
    }

    public function withConsole(bool $interactive, bool $decorated, int $verbosity): self
    {
        return new self($this->root, $this->vendor, $this->bin, $this->mode, $this->dev, $interactive, $decorated, $verbosity, $this->updatedPackages, $this->manifest);
    }

    public function manifestPath(): string
    {
        return preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $this->manifest) ? $this->manifest : $this->root . '/' . $this->manifest;
    }
}
