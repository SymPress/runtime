# ADR 0001: Scope, pinned baselines and consumer priorities

Status: accepted by the project owner on 2026-09-29 with Phase 0 approval.

## Evidence

Both local consumer locks resolve `wecodemore/wpstarter` 3.0.1 to `191b5df74920a713a90af9f3f3c7fceaf1f74b83` (R). The current upstream `dev` head is `059bbc9198ac51dee86c5233b6b006ca4d8008a4` (D). The annotated 3.0.1 tag object is different from its commit; differential fixtures must pin the commit.

R has eleven default steps. D adds the VCS check, `db-check`, `dropins-op`, `check-vcs-ignore`, `create-vcs-ignore-file`, `ScriptHaltSignal`, `ConditionalStep`, aliases, a root wp-config with a webroot proxy and a runtime getenv function. R has `unknown-dropins`, a `getenv` WordPress filter, a configurable Salter length and different defaults. The current documentation describes parts of D, not just the installed release. Neither baseline alone satisfies the work order.

`ComposerPlugin::defaultSteps()` puts `vcsignorecheck` last in D; `SelectedStepsFactory::factory()` then moves WP-CLI command steps to the end. Thus the effective execution order is checkpaths, wpconfig, index, flushenvcache, muloader, envexample, dropins, movecontent, publishcontentdev, **wpcliconfig, vcsignorecheck, wpcli**. The work order requests **vcsignorecheck, wpcliconfig, wpcli** for the last three. This is an explicit order difference, not evidence that upstream already behaves that way.

## Actual consumers

Evidence is the local working tree, including pre-existing changes, at the commits recorded in source evidence.

| Consumer | Evidence | Behavior to preserve first |
| --- | --- | --- |
| Both | `composer.json: extra`, `dev-ops/wpstarter.json` | Core `public/wp`, content `public/wp-content`, config referenced from `extra.wpstarter`; env-example false, register-theme-folder false, prevent-overwrite `[.gitignore]`, cache-env true, content-dev-op copy. |
| Starter | `dev-ops/wpstarter.json`, `dev-ops/orchestrate.php:5-53` | Commands provider `dev-ops/orchestrate.php`; legacy namespace, Env bridge and DB flags; version-only on invalid DB env, DB check on installed site, create DB and install otherwise; random fallback password; siteurl, rewrite and theme commands. |
| Demo | `dev-ops/wpstarter.json`, `dev-ops/orchestrate.php:5-66` | Provider path is `../dev-ops/orchestrate.php`, unlike starter. Resolve and diagnose this exact path against the source path rules before migration; do not silently reinterpret relative paths as config-file-relative. Adds activation, seeding and homepage setup on existing and new sites. Its current fallback admin password is static: this is a consumer migration issue to fix with the same explicit/generated-password policy as starter. |
| Both | `packages/base-mu-plugins/composer.json`, `app-starter.php` | MU package provides kernel boot already. Do not generate a second boot MU plugin. Test symlink and copy installs because their root resolution differs. |
| Starter | `bin/console`, `docs/environment.md`, `.sympress/cli.json` | Preserve setup/check/doctor before vendor exists; documented `WORDPRESS_ENV` becomes a supported legacy source. Existing console entry point remains public. |
| Both | `composer.json: scripts` | Post-install/update cleanup removes core wp-content. Runtime must finish publishing content before root cleanup; compare actual Composer execution order in integration tests. |
| Both | `composer.json: require`, installer paths | Composer-managed WordPress, WP-CLI bundle and custom MU installation paths; inspect installed package metadata, not only vendor/name conventions. |

No custom steps, custom templates, extension packages, or step scripts are configured in these two WP Starter config files. Their PHP command providers still directly use upstream classes; configuration migration alone is insufficient. The absence of other features in these consumers never removes them from the parity scope.

A tracked-manifest/lock scan of the local multi-repository workspace found WP Starter references in starter and demo only. Re-run the scan, inspect GitHub organization repositories and run dependency resolution checks in Phase 6 before making the stronger organization-wide completion claim.

## Decision

Use the union of R, D, all ten upstream documentation chapters and the explicit work order. R is the installed-consumer oracle; D is a separate feature oracle. Record version differences rather than silently choosing one. The default native profile follows the work order; a legacy compatibility profile preserves release-specific behavior where necessary. No claim of drop-in compatibility is valid until both suites and migrated consumers pass.

Prioritize config/env/path generation, DB status, PHP command providers, MU loading and boot ownership first. Complete every remaining matrix row before consumer replacement. Keep the existing consumer working trees unchanged in Phase 0.
