# SymPress Runtime

## Scope

Runtime owns Composer-driven project setup and generated WordPress configuration.
Keep application code out of the Composer process. Preserve user files, secrets,
existing salts and supported compatibility interfaces.

Use PHP ^8.5, Symfony ^8.1, PHPUnit 13, English documentation and GPL-2.0-or-later.
Keep product documentation native-first. Technical migration details belong in
`docs/compatibility.md`; license attribution remains in NOTICE. Historical records
under `docs/maintainers/history` are evidence, not current release instructions.

## Before changing behavior

Read the relevant guide and architecture decision in `docs/adr`.
Use `docs/maintainers/parity.md` and its executed test mapping when changing a
compatibility contract. Every intentional difference needs an exact expectation
and a documented decision. Do not remove a supported feature to simplify branding.

## Verification

Run focused behavioral tests, `composer qa` and `python3 tools/check-docs.py`.
Integration CI provisions real database, WordPress, WP-CLI and VCS fixtures and
rejects skips. The comparison harness is isolated from package dependencies.
Bootstrap changes also need a real WordPress/consumer smoke test.

Keep examples executable, links valid and native option/constant references in
sync with source. Do not claim a stable release, full acceptance or a successful
unrun check. See CONTRIBUTING.md for the development workflow.
