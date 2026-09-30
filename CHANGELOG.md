# Changelog

## Unreleased

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
