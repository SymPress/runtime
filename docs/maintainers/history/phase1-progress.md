# Phase 1 implementation record

Phase 0 was approved by the project owner on 2026-09-29. ADRs 0001–0006 are accepted by that approval. The `phase-1-foundation` branch implements the foundation; the parity matrix remains the final acceptance checklist.

Local verification uses an isolated DDEV webserver image containing PHP 8.5 and Composer, mounted over the workspace. No running consumer site is changed.

The shared `sympress/qa` package previously restricted PHPUnit to 10/11. A minimal companion change adds PHPUnit 13 to its allowed versions, retaining support for existing consumers.

## Verified foundation checkpoint

On 2026-09-29, PHP 8.5.9, Composer 2.10.3, Symfony 8.1.x and PHPUnit 13.3.6 were resolved in the isolated `sympress-runtime-qa` container.

- `composer qa`: passed coding standards, PHPStan max and 357 tests / 1,567 assertions, including the isolated MariaDB fixture.
- `composer validate --strict`: passed.
- Companion `sympress/qa`: full strict `composer qa` passed with PHPUnit 13, 15 tests / 68 assertions. Its temporary vendor symlink was removed after validation.
- Real Composer fixture installation: first install without a lock (Composer emits post-update), repeated install, no-dev, custom vendor/bin directories, command capability and standalone after no-plugins installation.
- Real Composer process boundary: project runtime application/container absent in host; child loads project Symfony; runtime executes before root scripts; step failure stops root scripts and propagates nonzero.
- Config/Result: precedence, profiles, invalid inputs, all documented option validation/context cases, lazy provider validation, mutation rules and schema coverage.
- Native CLI selection, DI attributes/tags/autowiring, priority and blocking/partial-result semantics.
- Paths, package metadata/dev filtering, filesystem source retention, overwrite guards, processes, template rendering, questions, salts and section editing.
- Effective quiet/debug/ANSI/noninteractive flags in injected contexts; actual installed-package step contribution; both native and legacy extension roots suppress automatic setup.
- Package contribution schema validation, including malformed metadata rejection without executing providers or exposing invalid values.
- MU discovery, header boundaries, duplicate directory exclusion and configured local dropin exclusion.
- URL transport: HTTPS on every redirect, verified TLS even with HTTP opt-in, SHA256 before replacement, required checksum, HTTP failure, redacted diagnostics and a real loopback server exercising relative redirects.
- Directory copy/move: physical path overlap checks, no following source symlink loops, target symlink protection and repeat-copy behavior.
- PHP tool resolution: Composer package before local phar before permitted verified download; selected PHP executable, argument boundaries and inherited/custom environment are exercised with real processes. Failed downloads/checks leave existing targets intact and remove temporary files; successful phars use mode 0550.
- Lazy shared WP-CLI process: offline existing-phar discovery, semantic version ordering, cached official release lookup with pinned fallback, SHA512 rejection/success and quoted fixed WordPress path. These are tool-service tests; they do not establish complete WP-CLI step/provider parity.
- Real child signals: SIGINT returns 130 and SIGTERM returns 143; handlers remain dispatchable during the wait and are restored afterward. A PTY fixture checks inherited interactive stdin/stdout and ANSI context.
- All 22 inventoried service accessor names have implementations. Environment loading is lazy, including when constructing `Services`; malformed dotenv is diagnosed only when the service is used.
- Env: real process precedence, interpolation, stable native/legacy file chains, loaded markers, HTTP header exclusion, typed custom constants, existing constants, all 157 catalog entries in separate PHP processes, atomic mode-0600 cache files, fresh-process hydration, real-value overrides and malformed-cache rejection. Generated WordPress bootstrap and build-time dumps are not covered by these reader tests.
- DB: lazy memoized probes, complete consistent supplied status flags, redacted unknown connection status, host/port/socket parsing and private temporary mysqlcheck options. An isolated MariaDB 11.8 container proves missing database, empty schema, existing users table, actual mysqlcheck and rejected credentials. It creates and drops only a random fixture database. To include this integration test, set `RUNTIME_TEST_DB_HOST` to a dedicated test server (locally `127.0.0.1:33079` inside the QA container); otherwise it is explicitly skipped.
- Composer handoff: private context files are validated before deletion; arbitrary project files and symlinks are preserved. Nested custom `COMPOSER` manifests retain the actual project working root for both plugin and standalone entrypoints.

These results establish the tested foundation behavior only. The full parity matrix remains unverified, including option effects implemented by later steps. A matrix row is not marked verified merely because its option can be parsed.

## Remaining work

Phase 1 is still in progress. Review the complete foundation against the source contract and record the phase review. All public service accessor names are present, subject to complete behavioral parity and legacy adapter work. Reader/database work also supplies part of the Phase 2 foundation.

The matrix now distinguishes `partial` assertions from `planned` behavior; no row is marked fully verified yet. Named constant datasets prove only their stated reader/definer checks. Compatibility, generated artifacts and differential evidence remain mandatory.

No built-in WordPress generation step is implemented yet. Default registry entries fail clearly if selected, rather than pretending setup succeeded. Scripts/events and complete legacy adapters, kernel commands, migration, differential CI and real consumer smoke remain in their scheduled phases.

The runtime repository has no remote configured. The shared-workflow caller is committed configuration only; no remote CI or PR execution is claimed. CI dependency resolution requires a QA revision containing the companion PHPUnit 13 change.
