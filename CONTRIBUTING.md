# Contributing to Runtime

Use `1.x` as the base and pull-request target for maintenance and security fixes
to the stable 1.x line. Port applicable fixes to `main` as well. Use `main` for
1.1 development; Composer identifies that branch as `1.1.x-dev`. It is not a
stable release or a replacement for the documented `^1.0` installation constraint.

Describe the behavior you want to change and include a small reproduction for a
bug. Keep fixes focused and update the user guide when public behavior changes.
New 1.1 features must preserve the published 1.x compatibility contract.

## Local setup

Use PHP 8.5+, Composer 2.10.3+ and Git. All development dependencies, including
SymPress QA and the kernel integration fixture, are publicly available.

```sh
composer install
composer qa
```

`composer qa` runs coding standards, PHPStan and PHPUnit. For a focused change:

```sh
vendor/bin/phpunit --filter NameOfYourTest
composer cs
composer static-analysis
```

Run `python3 tools/check-docs.py` after changing documentation or examples.
It checks internal links, JSON examples and the native option/constant references.
It complements actual example execution; it does not prove a deployment works.

## Integration tests

Database, real WordPress, WP-CLI, VCS and read-only filesystem tests need their
fixtures. The [integration workflow](https://github.com/SymPress/runtime/blob/main/.github/workflows/differential.yml) provisions
them and rejects skipped tests. See [the test plan](https://github.com/SymPress/runtime/blob/main/docs/maintainers/test-plan.md)
for local fixture variables and the isolated compatibility comparison.

Test a generated configuration in a real WordPress process when bootstrap changes.
Test custom steps in the standalone process, not only as direct PHP calls. Changes
to public defaults must preserve compatibility profiles or document the exact
intentional difference and its regression test.

## Before requesting review

Run the relevant tests and full QA, inspect the diff for credentials/generated
files, and explain the observed behavior and any unrun checks. Do not add real
`.env` files, dumps, private keys or production database data.

Use concise Conventional Commit messages. Release metadata and a stable tag are
separate from merging a change; do not label a development build as a stable release.
