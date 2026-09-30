# Phase 2 implementation record

Work continues on `phase-2-wordpress-runtime`, based on the separately committed Phase 1 foundation. Full phase acceptance remains open.

Implemented so far:

- `checkpaths`: content/theme/plugin directories, readable autoload/core checks, webroot environment warning, physical project containment before content writes.
- `index`: executable front controller, literal quoted paths, custom template support and preservation of a core-owned root index.
- `flushenvcache` plus standalone/Composer `flush-env-cache`: precise configured cache deletion, missing-file no-op, symlink target preservation and directory rejection.
- Immutable versioned parser payload: scoped environment/Symfony classes, complete parser command-substitution path, atomic directory publication, content verification on reuse, bundled license notices and no Composer dependency at request time.
- Database preflight before steps with native/release/dev selection timing, deprecated skip diagnostics, disabled/listing laziness and native health-failure propagation. Core checks preserve release listing/dev selected-run differences.
- `wpconfig`: root/webroot profiles and guarded proxy, all 19 executable sections, independent payload, constants, defaults, ordered environment PHP/early hooks, URL/SSL/theme/admin-color hooks, compatible getters, safe health information and WP-CLI guard.
- Regeneration recovers literal salts without executing PHP, preserves edited sections (including dynamic salt definitions within KEYS), repairs missing salt definitions and keeps unchanged bytes/mtime across separate generator processes. Main/proxy syntax is checked before publication and files use mode 0600.
- Runtime cache shutdown policy and native/legacy filters; separate-process restoration keeps real environment precedence. Explicit `dump-env` selects the requested chain, protects existing files and produces a dump usable with a read-only environment directory and no runtime cache writes.

Latest full checkpoint on 2026-09-29: coding standards, PHPStan max and 880 tests / 6,149 assertions passed, including isolated MariaDB and real WordPress SHORTINIT. The salt review added two regression cases: malformed existing PHP is rejected before recovery, and dynamic definitions outside the retained KEYS body cannot be discarded. This does not establish full phase acceptance or the complete differential contract.

Focused generated-runtime checks now include a named executable edit/regeneration dataset for every section (19 tests / 76 assertions). An additional real WordPress 7.1.1 SHORTINIT process connects to the isolated MariaDB and executes SELECT 1 without Composer (1 test / 6 assertions). Set `RUNTIME_TEST_WORDPRESS_DIR` to a read-only core source and `RUNTIME_TEST_DB_HOST` to a dedicated test server; locally these are `/workspace/starter/public/wp` and `127.0.0.1:33079`. The test uses a temporary project/content directory and random database, never the consumer's configuration or database. It is explicitly skipped when fixtures are unavailable.

All 157 constant datasets now execute their valid sample through generated configuration, runtime cache and build dump (157 tests / 1,727 assertions). Reader/cache datasets additionally cover missing values, unrecognized values according to their declared type, and existing definitions (314 tests / 2,603 assertions). Header interpolation and conflicting explicit dump environments also have passing regression checks.

The isolated differential harness passes locally against both pinned upstream commits on PHP 8.2.33, with the candidate on PHP 8.5.9. Its 183 fixtures cover constant values/types, 162 environment-name combinations, actual generated index/wp-config execution, warm-cache behavior, real-value overrides and the dev default-directory defect. Every observed difference is field-specific under D06, D09, D13 or D23. See [the harness contract](differential.md). The dedicated workflow is configured; remote execution is still pending dependency delivery.

Delivery: the private runtime repository is published, with [Phase 1 PR #1](https://github.com/SymPress/runtime/pull/1) based on the approved Phase-0 main branch. Phase 2 remains a separate branch. The [QA companion PR #3](https://github.com/SymPress/qa/pull/3) passes all remote checks. Runtime CI is currently blocked by QA main's PHPUnit 10/11 constraint; its first remote run confirms this exact dependency conflict. Merge approval for the companion is pending.

Remaining: complete generated edge-case review, full WordPress/admin/kernel smoke, compatibility/migration integration, differential CI and phase delivery evidence. The current real-core test is SHORTINIT, not a completed WordPress installation or consumer migration.
