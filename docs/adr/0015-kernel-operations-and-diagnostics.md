# ADR 0015: Kernel operations and diagnostics

Status: implementation decision, 2026-09-29. Implements ADR 0003 and native acceptance rows PAR-SYM-001/003/004/005/006.

## Cache identity and scope

`kernel-cache` is an explicitly selected maintenance step. It resolves the canonical environment through the same WordPress environment mapping used by generated configuration. Default paths are `var/cache/{environment}/kernel`; APP_CACHE_DIR and APP_BUILD_DIR overrides resolve relative to the project or retain their absolute directory. The actual SiteKernel path API is compared in integration tests. If an active App kernel was explicitly constructed for a different environment, cache operations fail rather than selecting another environment silently.

Runtime 1.2.3 uses a pure counterpart of Kernel 1.1.5's location policy, because calling Kernel's resolver can create and log a private fallback. Its implicit selection is `var/cache-private-{effective-user}/{environment}/kernel` when the normal location is public, group/world writable or unavailable. Existing read-only generations are selected by the presence of `meta.json`; legacy `meta.php` cannot select an unwritable location. Tests compare selected paths, JSON discovery writes and real compiled warmup with the installed Kernel. Doctor reports fallback selection without creating it and fails unsafe explicit roots.

All targets are checked before deletion. Cache/build directories are cleared without following child symlinks. In-project targets retain the generic project boundary. Explicit external cache/build roots have a separate boundary: the root must already exist with mode 0700, its owner must match the current PHP identity, and root/ancestor symlinks, parent traversal, foreign descendant owners and group/world writable directories fail. Discovery follows the selected cache as `discovery-packages.json`. Safe default `discovery-packages.json` and legacy `discovery-packages.php` files are removed when overrides leave stale metadata there; an unsafe implicit generation that caused fallback is never adopted or deleted. No legacy PHP metadata is executed. Other environments and sibling asset caches remain intact. This does not change the twelve upstream default steps or run a kernel inside Composer.

Build IDs remain unchanged unless `kernel-build-id` is configured or `--generate-build-id` is used with explicit `kernel-cache` selection. IDs allow 1-128 letters, digits, dots, underscores and hyphens. They are stored atomically in a private `var/runtime/{environment}/kernel-build-id.json`. Generated configuration exposes the ID to the kernel's constant-based reader before boot. Precedence is an existing constant, environment value, configured fallback, persisted ID. Invalid persisted content fails without echoing its value.

## Boot ownership

`kernel-boot` is disabled by default and enters automatic setup only when enabled or a previous generated boot file needs reconciliation. Installed packages may declare `extra.sympress-runtime.boots-kernel: true`. The runtime also inspects installed MU entry files and top-level MU PHP files for the existing SymPress App boot entry, using tokens without executing those files. Copy and symlink installations are covered. Ambiguous calls and multiple owners fail explicitly.

An existing boot provider suppresses generation and removes a previous runtime-owned boot file. Disabling the option likewise removes only the owned file. An unmanaged file is retained. When generation is enabled without a provider, sympress/kernel must be installed; the generated MU plugin loads the project autoloader and boots SiteKernel only if no App kernel exists. This is an optional boot entry, not a replacement for the kernel lifecycle.

## Console registration

RuntimeBundle is optional. Kernel is a development dependency for real integration tests and a suggested production package, not a runtime requirement for plain WordPress. Runtime 1.2.3 conflicts with Kernel versions below 1.1.5 so Composer resolves the pair using the JSON metadata contract. The bundle registers doctor, check, validate and dump-env in the kernel console without sharing the setup container. Definitions and execution are delegated to the native RuntimeCommand.

The pinned kernel discovers library metadata but does not automatically activate composer-plugin bundles. Therefore generated configuration registers RuntimeBundle through the kernel's existing public `symfony_register_bundles` filter, after project autoloading is available. The callback checks both optional classes before registration. A project with a custom configuration template can register the bundle in `config/bundles.php` instead. Tests exercise actual WordPress hook functions, actual kernel discovery, a compiled SiteKernel container and native command execution. No kernel source modification is required.

Fresh environment values, runtime caches and build dumps all reach `%env()%` through both superglobals. The production smoke warms an actual kernel, makes the entire fixture read-only, drops the process identity when running as root, and verifies cache reuse and unchanged file hashes with an intentionally invalid dotenv source bypassed by the dump.

## Diagnostics

doctor/check perform read-only checks of entry paths, content directories, symlink boundaries, required database environment names, database status, kernel cache/build paths, boot ownership and permissions. They do not execute setup autoload files, steps or PHP providers. Database status flags are labeled as provided when used. Configuration-disabled or unavailable checks remain unknown; missing kernel support is not-applicable. Exit codes are 0 for passing applicable checks, 1 for failures and 2 for unknown results without failures. JSON and text output contain names and fixed diagnostics, not credential values; configuration/inspection exceptions are redacted.

Readonly production permissions are accepted when the relevant artifact is readable and a valid environment dump is available. External cache roots retain mode 0700. Doctor observes the calling UID and filesystem namespace; it cannot prove the PHP-FPM identity or a different service namespace. A readable directory check is not proof of a complete warm kernel; the separate compiled-kernel smoke provides that evidence. Fresh DDEV consumer/admin/WP-CLI acceptance and Windows execution remain final delivery gates.
