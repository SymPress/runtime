# Phase 6 progress

Migration and compatibility finalization are implemented. Full local QA passes with **979 tests / 6,768 assertions**, coding standards and PHPStan max. The 12 isolated lifecycle differential cases pass against both pinned upstream baselines after the changes. The previous complete differential run covered 206 cases; a fresh complete run remains required for final acceptance.

Tests cover migration source precedence, explicit profile/default preservation, disabled DB-check conversion, source retention, private atomic output, dry-run, conflicts/force, symlinks and outside-root rejection, second-run no-op, exact static PHP findings and Composer command forwarding. An actual PHP HTTP server proves deprecations never contaminate response bodies or reveal environment values. Legacy child-process tests preserve typed interfaces and service injection and verify deduplicated diagnostics.

Consumer migration work is isolated in `../.worktrees/runtime-starter` (base `66ad917`) and `../.worktrees/runtime-demo` (base `e70a686`). Existing uncommitted changes in the original starter/demo checkouts remain untouched. Fresh DDEV smoke environments use separate project names and databases. Consumer QA/smoke, final row-by-row evidence, complete generated-artifact differential cases and remote CI acceptance are still open.

The remote QA dependency remains gated by the separate `sympress/qa` PHPUnit 13 PR. No merge has been performed without the pending approval.
