# Source evidence

Inventory date: 2026-09-29. This document records source observations, not executed parity results.

## Pinned upstream baselines

| ID | Source | Commit | Why included |
| --- | --- | --- | --- |
| R | [WP Starter 3.0.1](https://github.com/wecodemore/wpstarter/tree/191b5df74920a713a90af9f3f3c7fceaf1f74b83) | `191b5df74920a713a90af9f3f3c7fceaf1f74b83` | Both installed consumer lockfiles use this commit. |
| D | [WP Starter development snapshot](https://github.com/wecodemore/wpstarter/tree/059bbc9198ac51dee86c5233b6b006ca4d8008a4) | `059bbc9198ac51dee86c5233b6b006ca4d8008a4` | Contains features demanded by the work order that are absent in 3.0.1. |

Read-only source checkouts are `/tmp/sympress-runtime-wpstarter-3.0.1` and `/tmp/sympress-runtime-wpstarter-dev`. They are research checkouts, not package dependencies. Recreate them from the pinned commits if temporary files expire. Do not use a floating dev branch as a differential oracle.

[upstream-inventory.json](upstream-inventory.json) records SHA256, line count and public method declarations for text files under src, templates, tests and docs plus composer.json and LICENSE. It also extracts every Config default, known WordPress constant and wp-config section for both baselines. Binary images are excluded from the text inventory. Hidden templates are included.

The source-derived matrix was built from Config/Validator/Result, ComposerPlugin/command/selection/runner, every default step, Env/Filters/bridge, templates, service implementations, upstream test names/assertions and differences between the pinned baselines. Hashing or enumerating a file does not establish test coverage. The manifest is a completeness/drift aid; the behavioral assertions in the matrix are the acceptance contract.

## Required documentation cross-check

The upstream documentation site was opened and the checked-out source chapters were inspected alongside code. Documentation headings and examples disagree with implementation in some places; the matrix records the code behavior separately from requested behavior.

| Chapter | Pinned path | Matrix area |
| --- | --- | --- |
| Introduction | `docs/01-Introduction.md` | Lifecycle and requirements |
| Environment Variables | `docs/02-Environment-Variables.md` | ENV, DB, constant catalog |
| WordPress Integration | `docs/03-WordPress-Integration.md` | WP, SEC, section catalog |
| Configuration | `docs/04-WP-Starter-Configuration.md` | CFG, OPT, RES |
| Steps | `docs/05-WP-Starter-Steps.md` | STEP, RUN, MU, FS, VCS |
| Sample composer.json | `docs/06-A-Commented-Sample-Composer-Json.md` | CFG, WPC, paths |
| WP CLI Commands | `docs/07-WP-CLI-Commands.md` | WPC, SVC |
| Custom Steps Development | `docs/08-Custom-Steps-Development.md` | EXT, SVC, IO |
| Settings Cheat Sheet | `docs/09-Settings-Cheat-Sheet.md` | OPT, version defaults |
| Command-line Interface | `docs/10-Command-Line-Interface.md` | CLI, D03 |

## Workspace baselines

The SymPress workspace root is not a Git repository. These are separate child repositories; none was modified by Phase 0.

| Repository | Local HEAD | Inspected surfaces |
| --- | --- | --- |
| kernel | `c24273066431b3c33b4dd372022ad42c100aafd2` | AGENTS, README/docs, composer, schema, EnvConfig, SiteKernel/AbstractKernel, configuration resolver, environment parameter loader, build fingerprint and discovery cache |
| starter | `66ad917f9b78463202c3aee23752c3eb2c5feceb` | AGENTS, composer/lock, dev-ops config/provider, console, base MU package, environment docs and manifest |
| demo | `e70a6869d8bc660e9b6162d88cc9910c6c8cd96a` | AGENTS, composer/lock, dev-ops config/provider, base MU package and reference-project docs |
| asset-compiler | `d0a17af62e389ba22e789aee11ec7435faccef5d` | AGENTS, architecture/config/command docs, Composer plugin, application factory, dependency constraints |

Starter and demo had pre-existing uncommitted changes, including composer.json/lock, environment examples, DDEV security configuration and workflow files. Consumer observations describe those working trees, not an assertion that their HEAD blobs match. The exact research input hashes are recorded separately in workspace-evidence.json. No environment secrets are captured.

The installed WordPress `_get_dropins()` source in demo includes `php-error.php` and `fatal-error-handler.php`, absent from WP Starter's static ten-name list. Its file hash is included in workspace evidence; verify against the resolved core version again in Phase 3.

`gh repo view SymPress/runtime` could not resolve a repository at inventory time. Phase 0 artifacts are therefore prepared locally. A remote PR URL is not available and is not fabricated. Provisioning/publishing the intended repository must precede the Phase 0 remote PR.

## Most important code/documentation conflicts

1. Installed R has eleven steps; D has twelve. Effective order differs from declaration because the factory moves WP-CLI last.
2. Config contains runtime-only keys and the R-only unknown-dropins option; D computes wp-config-php-path outside DEFAULTS.
3. Source filters skip-config/custom steps before opt-in despite documentation promising opt-in precedence.
4. R wp-cli-files points to the wrong validator; do not omit the documented eval-file feature.
5. Types include float, mixed types, raw strings, octal and table prefix, not just bool/int/string.
6. Raw environment and canonical WordPress environment can intentionally differ; kernel aliases are narrower than WP Starter's.
7. Runtime cache may include loaded variables beyond the accessed subset and can mask real environment changes upstream.
8. Scripts support a Composer argument, and D supports return signals; a descriptor cannot replace a type-hinted Composer object.
9. Asset compiler is currently in-process, with event priority -1000.
10. Root/webroot config placement, runtime getter and section names differ between R and D.

All differences remain visible in ADR 0005 and require named differential expectations before implementation can be accepted.
