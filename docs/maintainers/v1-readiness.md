# Runtime 1.0 readiness

This is a requirement checklist, not a release certification. An item is complete
only when its implementation and current evidence are recorded here.

## Scope and decisions

The owner requested the V1 roadmap on 2026-09-30. On the same date the owner
explicitly waived the two-week production and migrated-project trial: adoption
will begin after V1 is available. Therefore V1 must not claim that production soak
testing or an independent migration deployment has already happened.

- E1: `runtime` is the standalone and WP-CLI command name. Existing package,
  configuration, lockfile and constant names remain unchanged. Document binary
  name collisions. A Composer alias may be added without removing existing names.
- E2: PHP `^8.5` remains the explicit supported minimum.
- E3: widen the Composer library to `^2.10.3` only with minimum/latest CI and a
  scheduled compatibility check that opens an issue on failure.
- E4: compatibility adapters remain throughout 1.x, are deprecated before V1,
  and may be removed in 2.0. No supported behavior is silently dropped.
- E5: publish the audited repository under GPL-2.0-or-later on GitHub and Packagist.

## Phase 1 — Complete 0.2.0 adoption

- [x] Runtime v0.2.0 published from `c732625` after release PR #12 and passing CI.
- [x] Starter upgraded to exact 0.2.0 in PR #31; fresh DDEV setup, browser smoke
  and remote QA/DDEV checks passed before merge.
- [x] Demo upgraded to exact 0.2.0 in PR #51; fresh DDEV setup, authenticated
  browser/Playwright smoke and all remote checks passed before merge.
- [x] Download locks reviewed and committed in both consumers; the same verified
  WP-CLI 2.12.0 artifact is pinned by both locks.

## Phase 2 — Freeze the contract

- [x] Source-derived PHP API inventory; every source class/interface marked.
- [x] Stable service surface excludes unreviewed concrete implementation methods.
- [x] Extension examples and architecture tests enforce the public API boundary.
- [x] Prepare compatibility policy for PHP, all 56 schema keys/defaults, commands,
  flags/exits, environment controls, hooks, config sections and persisted formats.
- [x] `wp runtime` shares command behavior/options/exits with the standalone binary;
  real WP-CLI tests and [source consumer smoke](v1-source-smoke.md) passed.
- [x] Version lock/cache/dump/payload formats and test unsupported/older formats.
- [x] Document compatibility deprecation and 2.0 removal boundary.
- [ ] Publish the contract milestone and `v1.0.0-beta.1` after its gates pass.

## Phase 3 — Platforms and dependencies

- [ ] Linux, Windows and macOS with minimum and latest stable Composer.
- [ ] Windows copy fallback, binary proxies, paths and permission semantics tested.
- [x] macOS case-insensitive paths and BSD tooling tested on both Composer jobs.
- [x] Lowest supported Symfony 8.1 dependency set tested; the complete QA suite
  passes with stable development tools and the lowest production dependencies.
- [x] Kernel 1.1.0 and QA 0.1.0 resolve stable tags; no dev constraints.
- [x] Package manifest contains no local path repository.
- [ ] Composer `^2.10.3` and scheduled compatibility reporting verified.

## Phase 4 — Public distribution

- [x] Full-history secret scan reviewed; public docs contain no private host details.
  The implementation history through `efcb915` was rescanned: 73 commits with
  scanned diffs, zero findings, exit status 0.
- [x] Repository public; license/notice retained.
- [ ] Packagist registration and automatic GitHub update integration verified.
- [ ] Signed release tags and QA-gated release/changelog/Packagist workflow.
- [x] Private vulnerability reporting enabled; supported-version policy prepared.
- [x] Weekly dependency/action updates configured; PHP security analysis and
  dependency audit pass on PR #13.
- [x] Public newcomer docs, migration guide and platform compatibility table.
- [ ] Empty-project installation from Packagist with `^1.0@RC` succeeds.

## Phase 5 — Release candidate

- [ ] Publish signed `v1.0.0-rc.1` after phases 2–4 pass.
- [ ] Starter and Demo consume the RC with green CI and smoke tests.
- [ ] Verify payload rollback, journal recovery, Composer upgrade and environment
  switching/cache invalidation scenarios.
- [ ] RC fixes are bug fixes only; high/critical issues are resolved.
- Production/non-SymPress field trials and minimum elapsed soak time: explicitly
  waived by the owner. Technical fixtures do not constitute production experience.
- Independent external security review: optional, not claimed as completed.

## Phase 6 — Publish 1.0

- [ ] Signed `v1.0.0` and release highlights/upgrade instructions published.
- [ ] Starter and Demo use `^1.0` with reviewed locks and passing CI.
- [ ] Announcement links to parity evidence, migration and compatibility policy.
- [ ] Create `1.x` maintenance branch; advance `main` to 1.1 development.
- [ ] Final requirement-by-requirement audit and 407/407 executed parity evidence.
