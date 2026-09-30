# SymPress Runtime agent contract

## Current delivery state

The project owner approved Phase 0 on 2026-09-29 and subsequently authorized PR review and merges. Phases 1–6 are merged. Implement review corrections according to the accepted ADRs and track fresh evidence in `docs/acceptance.md`; do not treat historical phase reports as current completion claims.

## Implementation contract after approval

- Package: `sympress/runtime`; PHP `^8.5`, Symfony `^8.1`, PHPUnit 13; English artifacts; GPL-2.0-or-later.
- Read `docs/parity.md`, the ADRs and `docs/test-plan.md` first. Keep source facts separate from proposed behavior.
- Every parity row needs a real behavioral assertion registered under its test ID before its status can become verified. Documentation checks do not establish runtime parity.
- No `wecodemore/*` dependencies in this package. The sole WP Starter installation exception is the isolated differential fixture job.
- Keep Composer integration at its API boundary. Run application services in a separate PHP process with the project autoloader. Do not load the project Symfony graph into Composer.
- Preserve user files and existing secrets. Never print or commit environment contents, dumps or generated salts.
- Respect all existing consumer changes. Starter and demo had unrelated uncommitted work at the Phase 0 baseline.
- Every deliberate behavior difference needs an ADR and exact differential expectation; no blanket snapshot exclusions.
- Use small Conventional Commits and one PR per implementation phase. Do not claim completion without fresh consumer smoke evidence.

## Verification

Match kernel QA conventions: `sympress/qa`, `sympress/coding-standards`, PHPStan `max`, PHPUnit 13 and the actual reusable workflows repository. In implementation phases run focused tests and `composer qa`; use isolated WordPress runtime processes, temporary Composer fixture projects and fresh DDEV consumer smoke tests as described in the test plan.
