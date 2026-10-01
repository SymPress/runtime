<?php

declare(strict_types=1);

namespace SymPress\Runtime\Console;

use InvalidArgumentException;
use SymPress\Runtime\Step\Definition;
use SymPress\Runtime\Step\Registry;

/** @internal */
final readonly class Selection
{
    /** @param list<string> $names */
    public function __construct(
        public array $names = [],
        public bool $skip = false,
        public bool $skipCustom = false,
        public bool $ignoreSkipConfig = false,
        public bool $list = false,
        public bool $force = false,
    ) {
        if ($skip && $names === []) {
            throw new InvalidArgumentException('--skip requires at least one step name.');
        }
    }

    public function selected(): bool
    {
        return !$this->skip && $this->names !== [];
    }

    /**
     * @param list<string> $configuredSkips
     * @return array{steps: list<Definition>, warnings: list<string>}
     */
    public function resolve(Registry $registry, array $configuredSkips = [], string $profile = 'native', bool $compatibility = true): array
    {
        if ($profile !== 'native' && $this->list && $this->selected()) {
            throw new InvalidArgumentException('Legacy profiles cannot combine --list-steps with positional opt-in.');
        }
        $warnings = [];
        $excluded = $this->ignoreSkipConfig ? [] : $configuredSkips;
        foreach ($excluded as $index => $name) {
            $definition = $registry->resolve($name, $compatibility);
            if ($definition === null || $definition->name === $name) {
                continue;
            }
            $excluded[$index] = $definition->name;
            $warnings[] = 'Deprecated WP Starter step alias: ' . $name . '; use ' . $definition->name . '.';
        }
        if ($this->skip) {
            foreach ($this->names as $name) {
                $definition = $registry->resolve($name, $compatibility);
                if ($definition === null) {
                    $warnings[] = 'Unknown step: ' . $name;

                    continue;
                }
                $excluded[] = $definition->name;
                if ($definition->name === $name) {
                    continue;
                }
                $warnings[] = 'Deprecated WP Starter step alias: ' . $name . '; use ' . $definition->name . '.';
            }
        }
        $steps = [];
        $candidates = $this->selected() ? $this->names : array_keys($registry->all($profile));
        foreach ($candidates as $name) {
            $definition = $registry->resolve($name, $compatibility);
            if ($definition === null) {
                $warnings[] = 'Unknown step: ' . $name;
                continue;
            }
            if ($definition->name !== $name) {
                $warnings[] = 'Deprecated WP Starter step alias: ' . $name . '; use ' . $definition->name . '.';
            }
            if ($profile === 'release-3.0.1' && !$this->selected() && !$this->list && $definition->name === 'vcsignorecheck' && !$definition->custom) {
                continue;
            }
            $nativeOptIn = $this->selected() && $profile === 'native';
            if (!$nativeOptIn && (in_array($definition->name, $excluded, true) || ($this->skipCustom && $definition->custom))) {
                continue;
            }
            if ($definition->commandOnly && !$this->selected() && !$this->list) {
                continue;
            }
            $steps[$definition->name] = $definition;
        }
        if ($this->selected() && $steps === []) {
            throw new InvalidArgumentException('No valid selected steps remain.');
        }
        if (isset($steps['wpcli'])) {
            $wpcli = $steps['wpcli'];
            unset($steps['wpcli']);
            $steps['wpcli'] = $wpcli;
        }
        if ($this->list) {
            ksort($steps);
        }

        return ['steps' => array_values($steps), 'warnings' => array_values(array_unique($warnings))];
    }
}
