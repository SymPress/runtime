# Runtime acceptance record

The implementation is available through the six stacked Runtime PRs. This record deliberately keeps final acceptance open.

## Reproduced evidence

- Full local package QA passes with 1,016 tests / 7,129 assertions, no skips, coding standards and PHPStan max.
- All 407 source-inventory rows map to executed PHPUnit behavioral tests. The evidence checker also rejects duplicate or unknown IDs, missing results, failed tests and skipped-only rows. It does not claim complete branch coverage from a passing test count.
- The isolated differential harness passes 249 cases against the exact release and development commits in `upstream-inventory.json`. It compares constant types and values, environment precedence, generated configuration execution, cache behavior, content hashes/links, WP-CLI arguments, lifecycle callbacks, extension loading, effective step order, all nineteen section edits, MU-loader execution, parsed WP-CLI YAML and active env-example assignments. Intentional differences are asserted as exact expected values, not unrestricted exclusions.
- Real WordPress SHORTINIT connects to a disposable MariaDB database without a Composer autoloader. Its production build dump also boots under an unprivileged identity with read-only project files and unchanged file hashes. A separate actual SiteKernel test loads a precompiled production container under the same permission constraint.
- Fresh, separate DDEV starter and demo projects install WordPress, authenticate into the admin dashboard, boot the kernel once and run `wp console debug:container`. Browser checks capture HTTP 200 responses and zero JavaScript errors. Starter QA passes 6 tests / 47 assertions; demo QA passes 27 tests / 213 assertions plus REST/block/render/ORM smoke.
- Repeated normal Composer installation followed by the standalone runner preserves 511 starter and 632 demo artifact hashes/link targets. The replay follows each project's configured content path and includes built demo assets.

## Open acceptance gates

1. **All plugins disabled in a real consumer.** `composer install --no-plugins` also disables `composer/installers` and the WordPress core installer. On the installed demo it rewrites package metadata/autoload paths from `public/wp-content/...` to `vendor/...`; the following standalone run then loads a stale vendor copy and fails during Monolog container compilation. Normal Composer installation restores the correct metadata and passes. The package's isolated no-plugins equivalence fixture passes, but that does not close this real-consumer gap. The approved Phase-0 architecture explicitly excludes replacing the core installer (ADR 0002); the interaction needs resolution before claiming the work order's full no-plugins acceptance. `tools/consumer-replay.py --disable-all-plugins` retains the failing scenario.
2. **Remote QA.** The separate [QA PHPUnit 13 PR](https://github.com/SymPress/qa/pull/3) is green but still awaits merge authorization. Runtime requires QA main, so local checks do not establish green remote Runtime CI. No merge is implied by repository creation or draft PR publication.
3. **Final parity sign-off.** Matrix rows remain `partial` until the preceding gates are resolved and the complete acceptance record is reviewed. The implementation and behavioral evidence are present; there is no claim of complete drop-in acceptance yet.

## Reproduction

Run `composer qa` with the dedicated `RUNTIME_TEST_DB_HOST`, `RUNTIME_TEST_WORDPRESS_DIR` and `RUNTIME_TEST_WPCLI_BOOTSTRAP` fixtures configured. Then run:

```sh
vendor/bin/phpunit --no-logging --list-tests-xml build/test-list.xml
python3 -m unittest discover -s tools/tests
python3 tools/parity-evidence.py
python3 tools/differential/run.py --oracle-php php8.2 --candidate-php php8.5 --composer /path/to/composer
python3 tools/consumer-replay.py /path/to/starter /path/to/demo
node tools/consumer-smoke.mjs /path/to/demo/packages/sympress-demo /path/to/browser build/consumer-smoke /path/to/starter /path/to/demo
```

The test-list command needs `--no-logging`: otherwise PHPUnit overwrites the execution report while listing tests. Local reports and screenshots live under ignored `build/`; CI uploads the differential, execution-map and JUnit reports. Source-only tests require no WP Starter dependency; the oracle exists only in temporary harness projects.
