# ADR 0026: Console command discovery

Status: implemented for 1.1.0.

## Context

Single-command mode treated `runtime list` and `runtime help` as setup selections.
Neither command reached Symfony Console's built-in implementation. Discovery also
passed through the pre-autoload package layout recovery path.

## Decision

Register all Runtime operations in a normal Symfony Console application. Route a
recognized first positional command to Console; prepend `run` for default setup
and direct step selections. Retain Console's list, help and completion commands.
The PHP-only layout boundary recognizes those discovery commands before any
recovery process starts. It does not load Symfony into the Composer host.

Custom steps with command-like names remain callable through `run <step>` or
`-- <step>`. This explicit syntax resolves the ambiguity without removing a step
or changing the registry's accepted names. Later positional names remain steps.
`--list-steps` retains its existing configuration-aware selection behavior.

## Verification

`ConsoleHelpTest` covers command formats, help targets, invalid configuration,
pending recovery, provider isolation and ambiguous custom step names.
`ComposerTest` exercises the installed vendor binary before WordPress exists.
`WpCliBridgeTest` compares discovery and help through the real WP-CLI executable.
Existing selection, Composer and compatibility tests continue to cover setup.
