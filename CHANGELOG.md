# Changelog

## Unreleased

- Generate native `wp-config.php` directly in the WordPress core parent (`public/` for a `public/wp/` layout), eliminating the default root configuration and forwarding loader. WP-CLI, section editing, setup drift and production diagnostics follow the same target.
- Recover literal salts from previous managed native root configurations without executing PHP; retain the root file for rollback. Customized sections and dynamic salt providers require explicit migration. Legacy profile layouts and alternate configuration targets remain supported.

## 1.2.4 — 2026-10-05

- Keep compiled environment caches and immutable dumps in OPcache on reads. Invalidate only a mutable cache actually rewritten by this process; unchanged writes do not waste shared memory.
- Include the installed Runtime version in generated MU loader and kernel plugin headers for security inventories.
- Production doctor accepts sealed, readable project-owned immutable caches when run as PHP-FPM. Mutable maintenance, external roots, symlinks and group/world write protection retain their ownership boundaries.
- Verify 8,000 warm OPcache reads without a reset and complete two SSH/MariaDB/FPM deployments with the actual PHP identity.

## 1.2.3 — 2026-10-05

- Preserve existing outbound WordPress HTTP integrations in 1.x: production and staging no longer enable `WP_HTTP_BLOCK_EXTERNAL` automatically. Explicit environment/constant opt-in retains operator host overrides and the Composer-managed core-host fallback.
- Validate a non-empty outbound host list without a global wildcard when blocking is enabled. Doctor reports compatibility-preserving disabled blocking separately from host restrictions.
- Report the WordPress hardening configuration switch separately from unverified consumer hook activation. Runtime preflight does not require or install the private Security package.
- Align discovery paths and read-only cache selection with Kernel 1.1.5's `discovery-packages.json` and `meta.json`; legacy PHP metadata is never executed or used to select an unwritable cache.
- Retire safe stale default JSON and legacy PHP discovery files after a configured cache override, while preserving unsafe generations that selected the private fallback and validating all targets before deletion.
- Require Kernel 1.1.5 or newer only when the optional kernel is installed. Update the Runtime/Kernel pair together and warm the selected JSON cache before read-only deployment.
- Match Kernel's private temporary fallback for classic document roots and cold read-only projects, including process ownership without ext-posix. Doctor selects it without creating cache directories; maintenance validates its owner and protected ancestors and preserves legacy default generations.
- Upgrade note: regenerate the managed configuration section and inspect preserved customized sections; an unchanged 1.2.0–1.2.2 generated file still contains its earlier HTTP default.

## 1.2.2 — 2026-10-03

- Align read-only Doctor and explicit cache maintenance with Kernel 1.1.4's selected private fallback, configured cache/build roots and discovery metadata.
- Fail diagnostics for unsafe explicit cache directories; report implicit fallback selection without creating directories or executing cache metadata.
- Mark unused cache/build diagnostics and permissions as not-applicable when the optional Kernel is absent, including WordPress core installed in the project root.
- Support explicitly configured external cache/build roots with mode 0700 and matching ownership; reject symlinks, parent traversal and unsafe permissions before any deletion.
- Require Kernel 1.1.4 or newer when the optional kernel is installed, and pin shared QA workflows to the released 1.2.2 source.

## 1.2.1 — 2026-10-03

- Remove persisted process-value fingerprints; evaluate compiled interpolation templates on every warm request without reparsing Dotenv, including rotated, absent and newly supplied values.
- Add side-effect-free `EnvironmentInspection` for requested raw values, sharing Doctor's configured cache/dump/file precedence and rejecting executable PHP or shell substitutions.
- Require a production `APP_SECRET` of at least 32 bytes in Doctor, refresh diagnostic interpolation and report dynamic dump selectors directly.
- Normalize IPv4-mapped IPv6 proxy peers and networks, refresh mutable OPcache environment data and constrain the tested Composer dependency to the 2.10 minor.

## 1.2.0 — 2026-10-03

- Enable WordPress hardening, disallow unfiltered HTML/uploads and block outbound WordPress HTTP by default in Composer-managed production. Only `api.wordpress.org,downloads.wordpress.org` are admitted until an operator supplies a complete project-specific allowlist.
- Preserve explicit supported overrides and the native profile; production doctor rejects unsafe hardening, upload, HTML and outbound-HTTP overrides.
- Includes the previously unreleased 1.1.4 interpolation-cache, offline Roots installation and production diagnostics fixes listed below.
- Upgrade note: review API-host needs before deploying. Starter now separates its optional legacy content cleanup from hardening; templates that copy older MU hooks must make the equivalent split before enabling production hardening.

## 1.1.4 — 2026-10-02

- Cache native process interpolation as Dotenv-produced templates with validated dependency fingerprints, keeping derived process secrets out of persisted values.
- Resolve unchanged interpolation caches without loading the parser and selectively refresh affected expressions when referenced process variables change, appear or disappear.
- Preserve safe unrelated values in runtime caches and deployment dumps; exclude process-derived and command-derived result bytes and keep dynamic source-chain selection ineligible for persistence.
- Include interpolation support in the independently generated and optionally bundled bootstrap without changing private cache permissions.
- Preserve each expression's external origin through source-chain overrides and cache replay, including inherited Dotenv ownership markers and HTTP-prefixed process inputs; keep native process precedence and trusted file defaults consistent.
- Ignore inactive quoted/comment/escaped default assignments, handle braced and unbraced assignments, and prune dependencies after explicit writes without discarding unrelated cached values.
- Recover offline WordPress-core layouts installed by either Roots or johnpbloch without requiring Composer lifecycle plugins in the standalone Runtime command.
- Fail production diagnostics for explicitly disabled environment caching or forwarded scheme use without valid trusted proxies; never execute persisted dynamic commands during diagnostics.
- Remove the accidentally tracked WordPress download ZIP; the existing `.wp-cli/` ignore and archive exclusion prevent its inclusion in ordinary commits and packages.

## 1.1.3 — 2026-10-02

- Keep probed database status reader-local and out of environment caches so a cold WordPress install cannot retain pre-install status in later diagnostics.
- Reload native ordinary caches after a generated Runtime payload change, preserving explicitly configured flags and existing format-1 deployment dumps.

## 1.1.2 — 2026-10-02

- Require a canonical WP_HOME in staging/production and configured trusted proxy IP/CIDRs before accepting forwarded HTTPS.
- Create private Composer handoff files from the first byte and exclude real process values and derived process secrets from native environment persistence.
- Implement native automatic writable-cache eligibility, preserving existing read-only cache/dump use, and ignore the download-lock guard in generated VCS files.
- Run release orchestration from protected main with independent signer-source verification, immutable workflow references and environment-gated publishing.

## 1.1.1 — 2026-09-30

- Describe the production and database health flags in diagnostic help, including the requirement for `--quick`, and identify `check` as an alias of `doctor` in command listings.
- Update the README, release guides and maintainer documentation.

## 1.1.0 — 2026-09-30

- Support Symfony Console `list`, `help`, command-specific help and shell completion without interpreting them as setup steps.
- List every Runtime operation with its own description; retain direct step selection and add explicit `run [steps]` syntax for command-like custom step names.
- Keep help and command discovery read-only, including before package layout recovery and when setup configuration is invalid.
- Verify command discovery and help through the native WP-CLI bridge.

See the [1.1 upgrade notes](docs/releases/1.1.0.md).

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

## 1.0.0-rc.1 — 2026-09-30

- Make Runtime available directly from Packagist, with automatic GitHub updates and verification of the published source revision in the release workflow.
- Begin release-candidate validation of the frozen 1.x API, configuration and file-format contracts, including the corrected empty-project installation path.
- Remove the Runtime-specific VCS repository from the public site example; retain WPackagist for WordPress plugins and themes.

Follow the [RC upgrade and installation guide](docs/releases/1.0.0-rc.1.md).

## 1.0.0-beta.2 — 2026-09-30

- Allow the Composer plugin to be installed in an unconfigured project before WordPress Core is added. Automatic setup is deferred for that initial state; explicit setup and configured projects retain missing-core validation.
- Cover a fresh Composer installation, repeat installation and explicit setup failure in a coreless project with a regression test.

This fixes the empty-project installation issue found in beta 1. See the [beta 2 upgrade notes](docs/releases/1.0.0-beta.2.md).

## 1.0.0-beta.1 — 2026-09-30

- Freeze the documented 1.x configuration and extension API; classify implementation details and enforce public examples in architecture tests.
- Add `wp runtime` before WordPress bootstrap, backed by the standalone command, and `composer runtime` aliases.
- Version download-lock files explicitly and reject unknown future cache, dump and payload formats safely.
- Use stable Kernel and QA releases without a local path repository; widen the Composer library requirement to `^2.10.3`.
- Deprecate legacy compatibility adapters before 1.0: supported throughout 1.x, scheduled for removal in 2.0. See the [compatibility policy](docs/compatibility-policy.md).
- Add minimum/latest Composer checks on Linux, Windows and macOS, lowest Symfony 8.1 checks, weekly dependency updates and PHP security analysis.
- Publish the repository under GPL-2.0-or-later with private vulnerability reporting and signed, QA-gated release automation.

This is a contract-validation prerelease. Review the [upgrade notes](docs/releases/1.0.0-beta.1.md).

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
