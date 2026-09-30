# Changelog

## Unreleased

No changes yet.

## 1.0.0-beta.1 — 2026-09-30

- Freeze the documented 1.x configuration and extension API; classify implementation details and enforce public examples in architecture tests.
- Add `wp runtime` before WordPress bootstrap, backed by the standalone command, and `composer runtime` aliases.
- Version download-lock files explicitly and reject unknown future cache, dump and payload formats safely.
- Use stable Kernel and QA releases without a local path repository; widen the Composer library requirement to `^2.10.3`.
- Deprecate legacy compatibility adapters before 1.0: supported throughout 1.x, scheduled for removal in 2.0. See the [compatibility policy](docs/compatibility-policy.md).
- Add minimum/latest Composer checks on Linux, Windows and macOS, lowest Symfony 8.1 checks, weekly dependency updates and PHP security analysis.
- Publish the repository under GPL-2.0-or-later with private vulnerability reporting and signed, QA-gated release automation.

This is a contract-validation prerelease. Review the [upgrade notes](docs/releases/1.0.0-beta.1.md). The timed production trial was waived; production field experience is not claimed.

## 0.2.0 — 2026-09-30

- Rename the standalone executable to `bin/runtime`, installed by Composer as `vendor/bin/runtime`; update existing shell and CI scripts when upgrading.
- Prevent environment secrets from reaching temporary-directory fallbacks; support private group-readable artifacts.
- Add native source-aware environment cache invalidation, mounted secret files, typed requirements and names-only environment comparison.
- Add setup drift checks, production diagnostics, explicit database health maintenance and Composer-managed update protection.
- Pin URL downloads with a reviewed lockfile and support exact WP-CLI version/hash assertions.
- Recover interrupted package layout changes from a durable journal and prune only verified inactive payloads and owned backups.
- Optionally bundle environment bootstrap classes while retaining lazy parser loading.

## 0.1.0 — 2026-09-30

Initial tagged release for the documented SymPress and standard WordPress package layouts.

- Generate WordPress configuration, a front controller, MU-plugin loading and WP-CLI configuration from Composer project settings.
- Run setup after Composer install/update or through the standalone command.
- Configure environments with typed WordPress constants, local overrides, stable salts, runtime caches and private production dumps.
- Preserve edited configuration sections and provide custom steps, services, events and extension packages.
- Add optional kernel diagnostics, cache maintenance and explicit boot ownership.
- Support WPackagist, private and local packages through Composer installers, with bounded offline package placement after plugins are disabled.
- Preserve existing projects through optional compatibility profiles and a migration command.
- Load native MU plugins in path order, support symlinked loaders, emit WordPress loading hooks and list each plugin in the admin screen.
- Bound the Composer library to the reviewed 2.10 line and test recovery against stable and a pinned upcoming-library revision.
- Document early Composer hooks, interruption recovery and package-backup retention.
- Provide a complete example project, executable custom-step example, deployment guidance and source-checked option and constant references.

See the [acceptance record](https://github.com/SymPress/runtime/blob/main/docs/maintainers/acceptance.md)
for verification evidence and the boundaries of tested layouts.
