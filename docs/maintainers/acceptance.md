# Runtime acceptance record

Baseline verified on 2026-09-30: all implementation phases and [Runtime review corrections #7](https://github.com/SymPress/runtime/pull/7) are merged at `60249ee25ead52f94e2b83e9be4ce472cd3b2fcf`. [Starter #29](https://github.com/SymPress/starter/pull/29) and [demo #49](https://github.com/SymPress/demo/pull/49) are also merged after successful CI. A repository-scoped read-only deploy key now supplies their private Runtime dependency. There is no stable release tag; final compatibility sign-off remains separate from these successful checks.

## Reproduced evidence

- Full local package QA after the review corrections passes with 1,059 tests / 7,365 assertions, no skips, coding standards and PHPStan max.
- All 407 source-inventory rows map to executed PHPUnit behavioral tests. The evidence checker also rejects duplicate or unknown IDs, missing results, failed tests and skipped-only rows. It does not claim complete branch coverage from a passing test count.
- The isolated differential harness passes 251 cases against the exact release and development commits in `upstream-inventory.json`, including early Composer classes and hooks. It compares constant types and values, environment precedence, generated configuration execution, cache behavior, content hashes/links, WP-CLI arguments, lifecycle callbacks, extension loading, effective step order, all nineteen section edits, MU-loader execution, parsed WP-CLI YAML and active env-example assignments. Intentional differences are asserted as exact expected values, not unrestricted exclusions.
- Real WordPress SHORTINIT connects to a disposable MariaDB database without a Composer autoloader. Its production build dump also boots under an unprivileged identity with read-only project files and unchanged file hashes. A separate actual SiteKernel test loads a precompiled production container under the same permission constraint.
- Fresh, separate DDEV starter and demo projects install WordPress, authenticate into the admin dashboard, boot the kernel once and run `wp console debug:container`. Browser checks capture HTTP 200 responses and zero JavaScript errors. Starter QA passes 6 tests / 47 assertions; demo QA passes 27 tests / 213 assertions plus REST/block/render/ORM smoke.
- Repeated normal Composer installation, a global `composer install --no-plugins`, and the standalone runner preserve 543 starter and 664 demo artifact hashes/link targets. The replay follows each project's configured content path and includes built demo assets and the deterministic package-layout record. Both consumer QA suites pass again after this recovery.
- Real WPackagist `classic-editor` and `twentytwentyfive`, together with Composer WordPress core, install at configured paths both normally and through fresh no-plugins installation plus standalone recovery. The dedicated [GitHub job](https://github.com/SymPress/runtime/actions/runs/36630166059/job/109617080698) passes too. Project autoload files execute after placement, once per standalone invocation; recovery does not replay Composer scripts. Package tests cover all five WordPress types, metadata drift, updates with retained backups, path changes, repository links, authoritative autoload and executable proxies.

## Open acceptance gates

1. **Layout boundary review.** The reproduced starter/demo and WPackagist no-plugins gaps are fixed by offline package placement and metadata/autoload recovery, documented in [ADR 0019](../adr/0019-standalone-wordpress-package-layout.md). Root-directory installs, overlapping package destinations and arbitrary third-party installer semantics still require the regular Composer installers. Unknown destination conflicts fail while preserving files. This is a deliberate safety boundary, not a claim that every possible Composer installer layout has been verified.
2. **Revision validation.** The baseline Runtime integration, WPackagist and QA jobs pass, and consumer PRs are merged. Every subsequent behavior change still requires its own QA and integration evidence.
3. **Final parity sign-off.** Matrix rows remain `partial` pending formal review of the complete acceptance record and documented layout boundaries. The implementation and behavioral evidence are present; there is no claim of complete drop-in acceptance yet.

## Review corrections

The standards review found that offline recovery restrictions also rejected already-correct root and nested package layouts. Preparation now preserves those regular-installer layouts, with regression tests for repeated setup and rejection without mutation when recovery is actually required. The specification review found that URL dropins ignored `--force` when the destination was a symlink. Verified downloads now replace only the link; failed checksum verification leaves the link and its source intact. Both corrections are covered by the full local suite above.

The subsequent independent review identified missing early project autoload, unbounded service downloads, missing fixture enforcement in CI and incomplete user/deployment documentation. [ADR 0020](../adr/0020-early-autoload-and-download-limits.md) records the implementation corrections. User guides now include required payload deployment and rollback-safe retention; old payloads are never automatically pruned. NOTICE identifies the three source-derived API files individually.

Final source review also found legacy loaded-marker/constant-mapping controls remained active with compatibility disabled. Runner, generated configuration, doctor and cached/dumped environments now honor that switch. Regression tests cover ignored legacy controls, retained native controls, name-only diagnostics and cache-mode mismatches.

The consumer URL correction restores `WP_SITEURL=${WP_HOME}`. Both Nginx configurations are byte-identical to their original PR bases. Starter setup/dry-run defaults agree; its updated QA passes 7 tests / 49 assertions. Demo QA passes 27 tests / 213 assertions plus live runtime smoke. Fresh browser sessions verify root login form actions, root admin dashboards, HTTP 200 homepages and no JavaScript errors. Existing cached environment values were cleared with Runtime's cache-flush command.

The differential workflow now provisions disposable MariaDB, mysqlcheck, Git/Hg/SVN and pinned SHA-256-verified WordPress 7.1.1/WP-CLI 2.12.0 fixtures before running the **entire** PHPUnit suite with `--fail-on-skipped`. The generic reusable QA job may still lack integration fixtures; its green result alone is not full integration evidence. `tools/ci-fixtures.py` was exercised locally, and all six fixture-dependent DB/WordPress/WP-CLI/read-only tests passed (81 assertions, no skips) using its downloaded artifacts. The corrected workflow passed for merged PR #7, including the no-skip fixture suite.

## Native documentation and MU-loader update

The next change adds native path ordering, symlink-aware MU loading, `mu_plugin_loaded` events and individual admin rows. The original compatibility profiles still pass all 251 differential comparisons. A real WordPress boot verifies the new loader and metadata behavior. The documented example installs actual WordPress core and runs the example custom step in the standalone process. Documentation checks cover all 40 public settings and 157 typed constants. Exact final test counts and CI status are recorded in the change's PR.

## Reproduction

Run `composer qa` with the dedicated `RUNTIME_TEST_DB_HOST`, `RUNTIME_TEST_WORDPRESS_DIR` and `RUNTIME_TEST_WPCLI_BOOTSTRAP` fixtures configured. Then run:

```sh
vendor/bin/phpunit --no-logging --list-tests-xml build/test-list.xml
python3 -m unittest discover -s tools/tests
python3 tools/parity-evidence.py
python3 tools/differential/run.py --oracle-php php8.2 --candidate-php php8.5 --composer /path/to/composer
python3 tools/consumer-replay.py /path/to/starter /path/to/demo
python3 tools/consumer-replay.py --disable-all-plugins /path/to/starter /path/to/demo
python3 tools/wpackagist-smoke.py --php php8.5 --composer /path/to/composer
node tools/consumer-smoke.mjs /path/to/demo/packages/sympress-demo /path/to/browser build/consumer-smoke /path/to/starter /path/to/demo
```

The test-list command needs `--no-logging`: otherwise PHPUnit overwrites the execution report while listing tests. Local reports and screenshots live under ignored `build/`; CI uploads the differential, execution-map and JUnit reports. Source-only tests require no WP Starter dependency; the oracle exists only in temporary harness projects.
