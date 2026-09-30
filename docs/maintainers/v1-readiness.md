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
- [x] Publish the contract milestone and signed `v1.0.0-beta.1` tag at `14368d6`
  after all 16 checks passed on PR #13. The tagged tree is identical to the
  checked PR head `53161d4`; the release workflow also verifies its exact signed
  tag object before publication.

## Phase 3 — Platforms and dependencies

- [x] Linux, Windows and macOS with minimum and latest stable Composer: all six
  jobs passed on the final PR #13 head `53161d4`.
- [x] Windows copy fallback, binary proxies, paths and permission semantics tested
  with minimum/latest Composer at `2101bda`. Windows file flushes remain mandatory;
  the documented platform boundary excludes directory-entry power-loss durability.
- [x] macOS case-insensitive paths and BSD tooling tested on both Composer jobs.
- [x] Lowest supported Symfony 8.1 dependency set tested; the complete QA suite
  passes with stable development tools and the lowest production dependencies.
- [x] Kernel 1.1.0 and QA 0.1.0 resolve stable tags; no dev constraints.
- [x] Package manifest contains no local path repository.
- [x] Composer `^2.10.3` and scheduled compatibility workflow verified by
  [run 36755778970](https://github.com/SymPress/runtime/actions/runs/36755778970)
  on `main` at `14368d6`: both installation/recovery jobs passed. The issue-reporting
  job was correctly skipped on success; no live failure issue was generated.

The final PR integration job passed 1,253 tests and 9,329 assertions with
`--fail-on-skipped`, followed by the isolated differential harness. Ordinary QA
and lowest-dependency jobs report 18 skipped tests where their external fixtures
are absent; these are covered by the dedicated integration job. Minimum and
latest Composer currently both resolve to 2.10.3; this is not evidence of an
upgrade to an unreleased Composer minor.

## Phase 4 — Public distribution

- [x] Full-history secret scan reviewed; public docs contain no private host details.
  The implementation history through `53161d4` was rescanned: 76 commits with
  scanned diffs, zero findings, exit status 0.
- [x] Repository public; license/notice retained.
- [x] Packagist registered by the owner on 2026-09-30; beta 1 and beta 2 source
  revisions match their signed tags. The active GitHub push webhook targets
  `packagist.org/api/github` and reports HTTP 202. Verify propagation of the new
  RC tag before starting consumer acceptance.
- [x] Signed release tags and QA-gated release/changelog/Packagist workflow:
  [beta 2 run 36758372673](https://github.com/SymPress/runtime/actions/runs/36758372673)
  passed after rerunning its failed distribution job following registration.
  All original technical gates had already passed; the rerun verified the exact
  published source revision without changing the tag.
- [x] Private vulnerability reporting enabled; supported-version policy prepared.
- [x] Weekly dependency/action updates configured; PHP security analysis and
  dependency audit pass on PR #13.
- [x] Public newcomer docs, migration guide and platform compatibility table.
- [x] Empty-project installation from Packagist with `^1.0@RC` succeeds, without
  custom repositories or access credentials. Fresh RC archive, exact source SHA,
  repeated installation and explicit missing-Core behavior are recorded in
  [public distribution acceptance](v1-distribution.md).

The initial missing-registration blocker is resolved. The RC tag supplies the
artifact needed for the exact `^1.0@RC` empty-project acceptance command; consumer
acceptance starts only after that public installation and tag propagation pass.
The earlier anonymous public-Git beta 2 installation succeeded, but is not counted
as the separate Packagist installation proof.

## Phase 5 — Release candidate

- [ ] Publish signed `v1.0.0-rc.1` after phases 2–4 pass.
- [ ] Starter and Demo consume the RC with green CI and smoke tests.
- [x] Verify payload rollback, journal recovery and environment switching/cache
  invalidation on the RC tag: 68 tests / 462 assertions plus 2 Composer replay
  tests / 34 assertions passed. See [recovery evidence](rc-recovery.md) for the
  explicit limit: minimum/latest Composer both resolve to 2.10.3, so no transition
  to a newer stable Composer library can yet be tested.
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
