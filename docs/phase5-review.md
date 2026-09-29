# Phase 5 review

Base: phase-4-extension-lifecycle. Head: phase-5-kernel-integration.

Runtime now manages kernel cache/build paths and optional boot ownership, persists explicitly requested build IDs, and exposes redacted doctor/check diagnostics. An optional bundle adds runtime commands to the kernel console through the existing WordPress bundle hook. Plain WordPress has no hard kernel dependency, and Composer still delegates application code to an isolated child.

Validation: local composer qa passes 973 tests and 6,711 assertions, coding standards and PHPStan max; the complete differential run passes 206 cases against the two pinned WP Starter versions. Tests include actual SiteKernel compilation/command execution, real WordPress bundle-hook discovery, environment values from caches/dumps and a production kernel boot without writable project files. Existing source and consumer data were preserved.

ADRs 0003/0015 and docs/kernel-integration.md document command behavior, boot detection, cache boundaries and limitations. Final acceptance remains open (355 partial / 52 planned matrix rows), including full migration, complete output comparisons, Windows and fresh consumer smoke. The PHPUnit 13 companion in sympress/qa remains required for remote QA.
