# Phase 1 implementation record

Phase 0 was approved by the project owner on 2026-09-29. ADRs 0001–0006 are accepted by that approval. The `phase-1-foundation` branch implements the foundation; the parity matrix remains the final acceptance checklist.

Local verification uses an isolated DDEV webserver image containing PHP 8.5 and Composer, mounted over the workspace. No running consumer site is changed.

The shared `sympress/qa` package previously restricted PHPUnit to 10/11. A minimal companion change adds PHPUnit 13 to its allowed versions, retaining support for existing consumers.

## Verified foundation checkpoint

On 2026-09-29, PHP 8.5.9, Composer 2.10.3, Symfony 8.1.x and PHPUnit 13.3.6 were resolved in the isolated `sympress-runtime-qa` container.

- `composer qa`: passed coding standards, PHPStan max and 112 tests / 544 assertions.
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

These results establish the tested foundation behavior only. The full parity matrix remains unverified, including option effects implemented by later steps. A matrix row is not marked verified merely because its option can be parsed.

## Remaining work

Phase 1 is still in progress. Finish/review the remaining service boundaries, signal/TTY behavior and the phase review record. Environment, database and PHP/WP-CLI tool services overlap the subsequent phases. The missing public services are `env`, `dbChecker`, `pharInstaller`, `phpToolProcessFactory` and `wpCliProcess`; they are not represented by no-op implementations. All other upstream service accessors have native implementations, subject to the full parity review and legacy adapter work.

No built-in WordPress generation step is implemented yet. Default registry entries fail clearly if selected, rather than pretending setup succeeded. Scripts/events and complete legacy adapters, kernel commands, migration, differential CI and real consumer smoke remain in their scheduled phases.

The runtime repository has no remote configured. The shared-workflow caller is committed configuration only; no remote CI or PR execution is claimed. CI dependency resolution requires a QA revision containing the companion PHPUnit 13 change.
