# Alternative-project PR review

Reviewed on 2026-09-30 using the live GitHub API and PR diffs. The alternative
WP Starter project currently has four open PRs. A cached web listing reporting
zero open PRs was stale.

| PR | Proposal | Runtime decision |
| --- | --- | --- |
| [#151](https://github.com/wecodemore/wpstarter/pull/151) | Sort MU plugins, emit `mu_plugin_loaded`, expose individual admin rows | Adopt for native setup. Keep old profiles unchanged. Tests cover ordering, linked loader paths, event arguments, hidden lists, metadata fallback and existing entries. Runtime uses its own template and branding. |
| [#150](https://github.com/wecodemore/wpstarter/pull/150) | Permit Symfony Dotenv 8 | Already satisfied: Runtime requires Symfony ^8.1. No dependency change needed. |
| [#148](https://github.com/wecodemore/wpstarter/pull/148) | Modern PHP types, PHPStan and CI modernization | Runtime already requires PHP ^8.5, uses typed code, PHPStan max and split QA/integration workflows. Its Composer-process isolation remains essential; do not port the older architecture or lower the PHP requirement. |
| [#147](https://github.com/wecodemore/wpstarter/pull/147) | Environment-example directory, question normalization, raw secrets, fallback MU discovery | Already implemented in native Runtime. Retain exact old-profile behavior where explicitly promised. Native secrets retain bytes rather than adding PHP escaping. |

## Evidence

- `tests/Contract/MuPluginListTest.php`: typed/fallback discovery, dropin exclusion and ordering.
- `tests/Integration/MuLoaderRuntimeTest.php`: native hooks/admin integration and profile isolation.
- `tests/Integration/WordPressBootTest.php`: a real WordPress boot with a symlinked loader, loading hooks and admin plugin metadata.
- `tests/Integration/DefaultArtifactStepsTest.php`: configured example directory and repeated generation.
- `src/Console/Question.php` and its contract tests: normalized answer keys.
- `src/Env/Filters.php`, `ConstantCatalog.php` and environment tests: explicit raw-secret conversions.
- `composer.json`, `phpstan.neon.dist` and CI: supported PHP/Symfony versions and quality checks.

The MU change adds native behavior rather than changing the pinned comparison
baselines. The reasoning is recorded in [ADR 0021](../adr/0021-native-mu-plugin-loading.md).
No source from an open PR is fetched or executed by Runtime at installation time.
