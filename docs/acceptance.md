# Runtime acceptance record

The implementation is available through the six stacked Runtime PRs, ending at [Phase 6](https://github.com/SymPress/runtime/pull/6). Consumer changes are separate drafts: [starter #29](https://github.com/SymPress/starter/pull/29) and [demo #49](https://github.com/SymPress/demo/pull/49). Their lockfiles pin Runtime commit `cfc5ffd`. This record deliberately keeps final acceptance open.

## Reproduced evidence

- Full local package QA passes with 1,043 tests / 7,298 assertions, no skips, coding standards and PHPStan max.
- All 407 source-inventory rows map to executed PHPUnit behavioral tests. The evidence checker also rejects duplicate or unknown IDs, missing results, failed tests and skipped-only rows. It does not claim complete branch coverage from a passing test count.
- The isolated differential harness passes 249 cases against the exact release and development commits in `upstream-inventory.json`. It compares constant types and values, environment precedence, generated configuration execution, cache behavior, content hashes/links, WP-CLI arguments, lifecycle callbacks, extension loading, effective step order, all nineteen section edits, MU-loader execution, parsed WP-CLI YAML and active env-example assignments. Intentional differences are asserted as exact expected values, not unrestricted exclusions.
- Real WordPress SHORTINIT connects to a disposable MariaDB database without a Composer autoloader. Its production build dump also boots under an unprivileged identity with read-only project files and unchanged file hashes. A separate actual SiteKernel test loads a precompiled production container under the same permission constraint.
- Fresh, separate DDEV starter and demo projects install WordPress, authenticate into the admin dashboard, boot the kernel once and run `wp console debug:container`. Browser checks capture HTTP 200 responses and zero JavaScript errors. Starter QA passes 6 tests / 47 assertions; demo QA passes 27 tests / 213 assertions plus REST/block/render/ORM smoke.
- Repeated normal Composer installation, a global `composer install --no-plugins`, and the standalone runner preserve 543 starter and 664 demo artifact hashes/link targets. The replay follows each project's configured content path and includes built demo assets and the deterministic package-layout record. Both consumer QA suites pass again after this recovery.
- Real WPackagist `classic-editor` and `twentytwentyfive`, together with Composer WordPress core, install at configured paths both normally and through fresh no-plugins installation plus standalone recovery. The dedicated [GitHub job](https://github.com/SymPress/runtime/actions/runs/36630166059/job/109617080698) passes too. Project autoload files execute after placement, once per standalone invocation; recovery does not replay Composer scripts. Package tests cover all five WordPress types, metadata drift, updates with retained backups, path changes, repository links, authoritative autoload and executable proxies.

## Open acceptance gates

1. **Layout boundary review.** The reproduced starter/demo and WPackagist no-plugins gaps are fixed by offline package placement and metadata/autoload recovery, documented in [ADR 0019](adr/0019-standalone-wordpress-package-layout.md). Root-directory installs, overlapping package destinations and arbitrary third-party installer semantics still require the regular Composer installers. Unknown destination conflicts fail while preserving files. This is a deliberate safety boundary, not a claim that every possible Composer installer layout has been verified.
2. **Consumer CI access.** The [QA PHPUnit 13 PR](https://github.com/SymPress/qa/pull/3) and Runtime phases 1–5 were reviewed and merged after successful CI. All six Runtime PRs passed remote QA before the final Phase 6 review fixes; those fixes pass the complete local suite above and require their own final CI result before merging. Starter and demo still need CI read access to the private Runtime repository and must move their dependency to Runtime main after Phase 6 merges. No CI credentials or repository access have been changed.
3. **Final parity sign-off.** Matrix rows remain `partial` until the preceding gates are resolved and the complete acceptance record is reviewed. The implementation and behavioral evidence are present; there is no claim of complete drop-in acceptance yet.

## Review corrections

The standards review found that offline recovery restrictions also rejected already-correct root and nested package layouts. Preparation now preserves those regular-installer layouts, with regression tests for repeated setup and rejection without mutation when recovery is actually required. The specification review found that URL dropins ignored `--force` when the destination was a symlink. Verified downloads now replace only the link; failed checksum verification leaves the link and its source intact. Both corrections are covered by the full local suite above.

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
