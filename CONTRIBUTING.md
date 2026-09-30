# Contributing to Runtime

Work from a branch based on `main`. Describe the behavior you want to change and
include a small reproduction for a bug. Keep fixes focused and update the user
guide when public behavior changes.

## Local setup

Use PHP 8.5+, Composer 2.10.3+ and Git. The development dependencies include SymPress
QA and the kernel integration fixture; authenticate private repositories if your
package source requires it.

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
