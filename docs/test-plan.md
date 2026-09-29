# Verification and phased delivery

Status: planned. No PHPUnit, differential, DDEV or Composer integration test has been executed for this new package in Phase 0.

## Test identity and traceability

Every `PAR-*` row in parity.md is an acceptance ID. Implement a PHPUnit 13 metadata attribute or explicit dataset registry that maps each ID to actual test methods and datasets. Each test must assert externally observable behavior, not merely the existence of a class/option. A structural coverage check fails on duplicate IDs, unknown IDs, absent mappings, skipped-only mappings or rows marked verified without passing results. The 157 constant rows and 19 section rows are included in that check.

Planned test families:

| IDs | Planned location | Required assertion style |
| --- | --- | --- |
| CFG, OPT, RES | `tests/Unit/Config`, `tests/Integration/Config` | Value/error distinctions, precedence, validation before mutation, source provenance and actual Result operations |
| CLI, RUN, EXT | `tests/Integration/Runner`, `tests/Integration/Composer` | Captured execution trace and subprocess exit/output; all flag combinations, extension roots and callback traces |
| STEP, MU, FS, VCS, DROPIN | `tests/Integration/Steps` | File contents/modes/link targets, retained source files, no-op reruns, failure injection and real temporary VCS repositories |
| ENV, WP, CONST, SECTION, SEC | `tests/Runtime` | Fresh PHP process, stub core/plugin API, typed constants, superglobals, captured hooks, shutdown cache, no vendor autoload dependency |
| DB | `tests/Integration/Database` | Disposable MariaDB service plus fake mysqlcheck for missing/failure cases; flags and query order; no external production DB |
| WPC | `tests/Integration/WpCli` | Argument capture binary and actual pinned WP-CLI fixture, provider scope, download/hash fault cases |
| SVC, IO | `tests/Contract` | Public service methods, named arguments/types, errors, negative paths, adapter diagnostics and process isolation |
| SYM | `tests/Integration/SymPress` | Real kernel package in path fixture, env agreement, boot count, cache paths, commands and migration report |
| QA | `tests/Architecture`, CI | Dependency boundary, legal notices, artifact traceability, real install and deployment checks |

## Runtime harness

Create a separate temporary project for each scenario. Stub ABSPATH/wp-includes/plugin.php records add_action/add_filter calls with priorities and accepted arguments. Stub wp-settings.php records boot count and emits a JSON report. Assertions occur outside the generated code so global constants and shutdown state cannot leak between tests.

The report contains selected constant values with PHP types, selected safe ENV/SERVER entries, loaded-file order, hook arguments, cache existence and filesystem checksums. Secret fixtures are synthetic and redacted from CI logs. Execute registered callbacks where needed; checking only that a hook was registered is insufficient for SSL/cache/admin/theme behavior.

Datasets cover local/development/staging/production, every exact alias, bounded contains matches, conflicting environment variables, unknown environment, pre-defined constants, missing/empty dotenv, interpolation, marker presence, local overrides, missing/unreadable cache, stale dump env, real env overrides, read-only filesystem, custom env paths, debug overrides, all cast types and all catalog constants. Run both cold and warm cache/dump routes. Remove vendor/autoload.php for the generated-config independence case.

Salt tests inject deterministic entropy only into the test harness for normalized comparison; real production code uses cryptographic randomness. Also verify real salt uniqueness/shape, minimum/maximum legacy length, and unchanged salts on a separate-process rerun. Never normalize changed salts away in the idempotence test.

## Real Composer fixtures

Use disposable projects with a path repository to this package, a minimal core fixture and synthetic plugin/theme/MU/dropin/extension packages. Execute real Composer installation with plugin allow-list configuration, not only event method calls. Cover first install, no lock update path, second install, update, no-dev, custom vendor/bin dirs, symlink/copy path repos, --no-interaction and different host Symfony versions. Capture runtime/asset/root-script order. Verify --no-plugins plus standalone equals plugin output.

Extension fixtures cover typed/optional/conditional/file-creation/postprocess steps, default replacements, command-only steps, autoload metadata, all four callback arguments, legacy aliases, dynamic pre-run mutation, halted propagation and equivalent events. Include explicit diagnostics for unsupported live-Composer type hints; do not call that case an unchanged compatibility pass.

## Isolated differential job

Use a dedicated job/container with three isolated roots: oracle-R, oracle-D and candidate. WP Starter is installed only in oracle fixture composer.json files created during that job. It never appears in runtime require/require-dev, ordinary test dependencies or a shared lockfile. Pin source SHAs and Composer/PHP versions in fixture metadata. Store minimal input fixtures and expected behavior in this repository; do not vendor upstream production source here.

The oracle requires its compatible PHP/Symfony graph, while the candidate uses PHP 8.5/Symfony 8.1. Record both environments. Verify the oracle can execute before comparing it; an incompatible oracle is a failed job, not a parity pass. Network tests use controlled fixtures with production transport/validator APIs, and WP-CLI release artifacts are pinned rather than relying on latest changing mid-run.

Compare generated index, wp-config/proxy, MU loader, wp-cli.yml, env example and ignore files, plus runtime reports. Normalize only fixture absolute prefixes, line endings, explicitly nondeterministic test-injected salts and cosmetic branding/formatting. Use parsed structures for YAML and a documented structural projection for generated PHP where the standalone bootstrap necessarily differs. Also compare canonicalized text snapshots of stable template sections. Do not erase executable statements globally.

Every intentional mismatch has an exact fixture ID, fields/paths, R/D expected result, candidate expected result and ADR difference ID. No blanket allowed-diff rule for an entire wp-config file. An unregistered mismatch fails CI. Bugs such as R's wp-cli-files wiring remain captured as named upstream-failure fixtures while candidate tests assert the requested working feature. Strict parity scenarios and approved-difference scenarios both must pass.

## Delivery sequence: one PR per phase

| Phase | Deliverable | Gate |
| --- | --- | --- |
| 0 | This inventory, evidence, ADRs and verification plan | Owner approval; no production code before it |
| 1 | Plugin/child process, DI, Config/schema/validate, Paths/Services, full CLI semantics | Config/selection/service tests, real first-install and no-plugins equivalence fixtures |
| 2 | Env/casts/self-contained config, checkpaths/index/flush, DB checker | Runtime matrix, salt stability, R/D core differential scenarios |
| 3 | All remaining default steps and complete WP-CLI | Filesystem/VCS/WP-CLI integration and full output differential |
| 4 | Custom/command steps, scripts/events, extension autoload/templates | All extension contract fixtures and migration adapter tests |
| 5 | Native dotenv/dump, kernel steps/commands, doctor, hardening | Kernel and asset compiler coexistence, read-only production smoke |
| 6 | Compatibility/migration finalization, then separate starter/demo PRs | All matrix rows verified; consumer runtime smoke and dependency removal |

Implement compatibility seams early enough to test earlier phases; finalize the user-facing migration in Phase 6. Keep separate PRs for consumer repositories. A phase PR cannot replace the final full acceptance gate.

## Final consumer acceptance

Run in fresh temporary consumer clones without disturbing existing user changes. Resolve dependencies and verify `composer why wecodemore/wpstarter` plus direct/locked graph inspection; scan all accessible SymPress repositories for wecodemore requirements. Check the no-wecodemore rule for runtime itself separately from the deliberate oracle exception.

Fresh `ddev composer install`, setup and real WordPress database installation must succeed; record an actual admin login, exactly one kernel boot and `wp console debug:container`. Run `composer qa` with the repository's own conventions and record any missing DDEV/browser availability honestly. Repeat runtime generation and assert no diff. Test the standalone deployment path and read-only dump. Do not mark the overall goal complete while any of these gates remains unrun or failing.

## Documentation delivery

Implementation phases add and maintain README, Getting Started, Configuration, Settings Cheat Sheet, Environment, wp-config/Sections, Steps, CLI, WP-CLI, Custom Steps/Extensions, SymPress Integration and Migration guides. Add CHANGELOG, SECURITY, LICENSE and actual NOTICE attribution. Code/comments/docs/commit messages remain English. Current documents describe proposals only.
