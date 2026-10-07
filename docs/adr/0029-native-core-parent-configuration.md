# ADR 0029: Native core-parent configuration

Status: implementation decision requested by the project owner on 2026-10-07.

## Decision

The native main configuration defaults to `Paths::wpParent('wp-config.php')`.
For Core in `public/wp`, this is `public/wp-config.php`. WordPress finds this
file through its standard parent-directory lookup; a fresh native project has
one generated configuration and no forwarding loader. The parser payload and
dotenv files remain outside the document root.

`release-3.0.1` retains its core-parent location. `upstream-dev` retains its root
configuration and proxy. Explicitly supplied invocation-context targets still
win and receive a proxy when the target differs from the core parent. WP-CLI
configuration, section editing, setup drift and production diagnostics resolve
the same profile-dependent target. This supersedes the native location in ADRs
0006 and 0010 and records the intentional difference D31.

## Existing projects

When the new target is absent or contains Runtime's old generated proxy, a
managed root configuration supplies the previous literal salts. Runtime reads
and tokenizes it without executing PHP, publishes the new main configuration
with rebased generated paths, and retains the original root file for rollback.
It never silently removes a user file. Operators archive the retained root file
after verifying the new configuration.

Edited managed sections, unmarked root files, symlinks and dynamic salt providers
require explicit migration before generation writes a replacement. An edited
section may depend on `__DIR__`; preserving its bytes while changing its file
location would change behavior. Back up the old files, move the main file to
the core parent, review relative paths, and regenerate its managed sections.
Ordinary regeneration at a fixed location continues to preserve section edits
and dynamic salt sections. Server configuration denies direct HTTP access to
the generated configuration; default file permissions remain 0600.

## Verification

`WpConfigLocationTest` checks native, release and development layouts,
managed-root salt preservation without PHP execution, and refusal before
replacing edited configurations. `WpConfigTest` checks ordinary boot,
separate-process idempotence and section edits.
The WP-CLI target, production checks, real WordPress bootstrap and payload
rollback tests cover the consumers of the generated configuration.
