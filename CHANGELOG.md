# Changelog

## Unreleased

No changes yet.

## 1.0.0 — 2026-09-30

- Establish the 1.x compatibility contract for documented PHP extension APIs, configuration/defaults, command behavior, environment controls, hooks, managed sections and persisted formats.
- Use `sympress/runtime:^1.0` from Packagist in public installation guides and the complete site example; retain exact resolved versions in project lockfiles.
- Support `vendor/bin/runtime`, native `wp runtime` and Composer aliases without requiring the SymPress kernel.
- Retain deprecated compatibility adapters throughout 1.x, with removal scheduled for 2.0.
- Document upgrades from 0.x and prereleases, retained payloads for rollback, platform limits and the stable security support policy.
- Verify Packagist propagation with a release-specific metadata URL so a cached pre-release package response cannot hide a newly published tag.

See the [1.0 installation and upgrade guide](docs/releases/1.0.0.md),
[compatibility policy](docs/compatibility-policy.md),
[compatibility migration guide](docs/compatibility.md) and
[parity comparison](https://github.com/SymPress/runtime/blob/main/docs/maintainers/parity.md).
The owner waived timed production and independent migration trials; no completed
production field trial is claimed.

## 1.0.0-rc.1 — 2026-09-30

- Make Runtime available directly from Packagist, with automatic GitHub updates and verification of the published source revision in the release workflow.
- Begin release-candidate validation of the frozen 1.x API, configuration and file-format contracts, including the corrected empty-project installation path.
- Remove the Runtime-specific VCS repository from the public site example; retain WPackagist for WordPress plugins and themes.

Follow the [RC upgrade and installation guide](docs/releases/1.0.0-rc.1.md). This candidate is for technical evaluation; the owner waived production and independent migration field trials, and no production experience is claimed.

## 1.0.0-beta.2 — 2026-09-30

- Allow the Composer plugin to be installed in an unconfigured project before WordPress Core is added. Automatic setup is deferred for that initial state; explicit setup and configured projects retain missing-core validation.
- Cover a fresh Composer installation, repeat installation and explicit setup failure in a coreless project with a regression test.

This fixes the empty-project installation issue found in beta 1. See the [beta 2 upgrade notes](docs/releases/1.0.0-beta.2.md). Packagist registration remains a separate distribution gate; this prerelease does not establish production field experience.

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
