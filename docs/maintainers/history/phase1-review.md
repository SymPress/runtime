# Phase 1 review: executable runtime foundation

Suggested PR title: `feat: add isolated Composer runner and complete service foundation`

## Problem and resulting behavior

WP Starter currently couples project setup to Composer's process and dependency graph. The runtime plugin now hands an immutable context to a separate PHP runner, which loads the project's Symfony services. A standalone entrypoint supports installations made with plugins disabled. Both entrypoints validate native and legacy configuration, select steps consistently, and expose the complete 22-name service facade for custom steps.

The foundation includes configuration/schema validation, path resolution, Result semantics, DI registration, extension contribution validation, console/process helpers, downloads, phar tools, environment reading and database checks. Built-in WordPress generation is explicitly pending and cannot return a false success. Starter and demo are not migrated in this phase.

## Review findings resolved

- Composer and project Symfony code remain in different processes; custom vendor/bin directories and nested `COMPOSER` manifests work in actual fixture installs.
- The parent wait dispatches and forwards SIGINT/SIGTERM, restores handlers and propagates exit status. Interactive descriptors remain attached under a PTY.
- Private handoff files are validated before removal. Arbitrary project files, malformed payloads and symlink targets survive rejected handoffs.
- Extension metadata is validated before a provider is executed; invalid values and environment contents are not included in diagnostics.
- Filesystem copy checks physical overlap and conflicting links; failed phar/download replacements preserve existing files.
- Lazy environment access does not trigger parsing, database access or tool downloads during container construction. Native credentials retain exact bytes. Database access errors remain distinguishable from confirmed absence.

## Local verification

Full `composer qa` on PHP 8.5.9 with PHPUnit 13.3.6: 357 tests, 1,567 assertions, coding standards and PHPStan max pass. Tests include real Composer installs/commands, real subprocesses/PTY/signals, a loopback HTTP server, separate PHP constant/cache processes and an isolated MariaDB 11.8 database. Composer validation passes. The `sympress/qa` PHPUnit 13 companion change separately passed its strict QA suite (15 tests, 68 assertions).

251 of the 407 matrix rows have partial registered foundation evidence. No row is declared fully verified: complete generated-config behavior, option effects and differential checks remain outstanding. The database integration test requires a dedicated server via `RUNTIME_TEST_DB_HOST` and is explicitly skipped without it.

## Delivery limits and next phase

There is no runtime remote or PR. A fresh `gh repo view SymPress/runtime` check on 2026-09-29 could not resolve that repository. The reusable workflow caller is present but has not run remotely, and needs the QA companion revision published. No consumer installation/admin/kernel smoke or full differential acceptance is claimed.

The code foundation is ready for Phase 2 implementation; phase delivery remains open until its review/PR and applicable consumer evidence can be recorded. Phase 2 supplies actual generated WordPress configuration, independent bootstrap payload, cache commands, preflight and the first built-in steps. Later phase branches must retain a separate review scope.
