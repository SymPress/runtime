# Runtime 0.1.0 acceptance

Technical sign-off: **accepted for the documented SymPress and standard WordPress
package layouts on 2026-09-30**, following the project owner's request to complete
verification and publish a tagged release.

All **407** source-derived contract rows are `verified`. Their registered behavioral
tests passed, the pinned differential comparisons passed and the consumer gates below
were exercised. Test counts do not establish exhaustive branch coverage or support
for arbitrary third-party installers.

## Release evidence

| Gate | Observed result |
| --- | --- |
| Package QA | PHP 8.5.9; PHPCS and PHPStan max pass; 1,063 tests / 7,411 assertions, zero skips |
| Contract mapping | All 407 IDs have passing executed evidence; no unmapped rows, missing results or failed tests |
| Differential comparisons | All 251 cases pass against the exact R/D source commits in [the inventory](upstream-inventory.json) |
| Composer recovery matrix | Stable CLI/library 2.10.3 and snapshot CLI with pinned upcoming library; both jobs pass normal/offline WPackagist installation and repeated randomized layout tests |
| Starter candidate | 7 tests / 49 assertions; kernel console, root login/admin and HTTP 200 homepage pass |
| Demo candidate | 27 tests / 213 assertions plus REST/block/render/ORM smoke; kernel console, root login/admin and HTTP 200 homepage pass |
| Browser | Both consumers: visible dashboard, root login POST, zero JavaScript errors |
| Repeatability/recovery | 607 starter and 728 demo artifact hashes/link targets unchanged across two normal installs, an all-plugins-disabled install and standalone recovery |
| Dependency removal | Neither consumer lock includes a `wecodemore/*` dependency |
| Documentation | All 40 options and 157 constants; executable site/custom-step examples; old acceptance/matrix links retained |

Consumer replay used Runtime source baseline `9c1ea8f`; subsequent changes affect
tests and acceptance records, not production source. Exact tagged consumer pins
are applied after tag publication and checked in their own PRs.
[Release PR #9](https://github.com/SymPress/runtime/pull/9) records the final reviewed
revision and blocking CI. [PR #8](https://github.com/SymPress/runtime/pull/8) already
merged the independent guides and native MU improvements.

The recovery matrix includes [stable](https://github.com/SymPress/runtime/actions/runs/36713385677/job/109880305942)
and [upcoming](https://github.com/SymPress/runtime/actions/runs/36713385677/job/109880306308) jobs.
Reports identify the actual CLI, library version and pinned source. The development
alias exists only in isolated fixtures; production remains constrained to `~2.10.3`.

## Layout boundary: reviewed and accepted

Normal Composer installation with WordPress installers remains the routine workflow.
Offline preparation supports downloaded WordPress core, plugin, MU-plugin, theme
and dropin packages from WPackagist, private and local repositories.

Recovery targets must be distinct directories inside the project. Existing correct
root-directory and nested layouts remain usable; repairing those layouts, overlapping
destinations or arbitrary installer semantics requires normal Composer installers.
Tests verify rejection without destructive merging. Unknown destination conflicts
preserve user content. See [ADR 0019](../adr/0019-standalone-wordpress-package-layout.md).

These are explicit recovery boundaries. Exception rollback is tested; SIGKILL,
power loss and storage failure are not covered by a crash journal. Follow the
[recovery and retention procedure](../deployment.md). Package backups are never
pruned automatically.

## Review findings and disposition

- Early autoload, bounded downloads, independent request payloads and disabled
  legacy controls were corrected and verified in the previous implementation review.
- Native MU loading retains WordPress global PHP scope, supports linked loaders
  and exposes loading hooks/admin rows. Real WordPress and profile-isolation tests pass.
- The original one-off warning had no diagnostic text and did not recur in 30
  randomized recovery runs or the full randomized suite. The new matrix reproduced
  a Composer/PHPUnit `stat()` probe warning. Tests now use production-style subprocess
  isolation and still fail on nonzero status or unsuppressed stderr. The reproduced
  warning is resolved; identity with the original report cannot be established.
- Both final review axes were rechecked after corrections; no confirmed blocker remains.
- The installed WP-CLI PHAR emits a PHP deprecation from its bundled React Promise
  code during demo QA. Application checks pass; this is separate from Runtime/PHPUnit
  diagnostics.

The initial release is `v0.1.0`. Future production updates require a reviewed tag
and lockfile. [ADR 0022](../adr/0022-release-and-composer-recovery-policy.md) defines
the Composer compatibility and release policy.

## Reproduce the checks

Run `composer qa` with the database, WordPress and WP-CLI fixtures from the
[integration workflow](https://github.com/SymPress/runtime/blob/main/.github/workflows/differential.yml).
Then run:

```sh
vendor/bin/phpunit --no-logging --list-tests-xml build/test-list.xml
python3 tools/parity-evidence.py
python3 tools/differential/run.py --oracle-php php8.2 --candidate-php php8.5 --composer /path/to/composer
python3 tools/consumer-replay.py --disable-all-plugins /path/to/starter /path/to/demo
python3 tools/check-docs.py
```

Use `--no-logging` for discovery so it cannot overwrite the execution report.
CI rejects skipped tests in the full fixture job and uploads exact evidence.
Pinned reference packages exist only in isolated test projects, never as Runtime dependencies.
