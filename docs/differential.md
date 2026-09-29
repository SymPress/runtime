# Isolated differential verification

`tools/differential/run.py` creates temporary oracle projects below the ignored `build/differential/` directory. It checks out both approved WP Starter commits, verifies HEAD, and installs WP Starter only into those projects with plugins and scripts disabled. The fixture explicitly enables its plugin only when exercising its generation command. The runtime package and ordinary test dependency graph never require an upstream package.

Run after installing runtime development dependencies:

```sh
python3 tools/differential/run.py \
  --oracle-php /usr/bin/php8.2 \
  --candidate-php /usr/bin/php8.5 \
  --composer /usr/local/bin/composer
```

The CI job is isolated from the shared QA job. Oracle PHP is 8.2.33, candidate PHP is 8.5.9 and Composer is 2.10.3. Both upstream dependency graphs pin Dotenv 6.4.45 and Composer Installers 2.3.0. Each report records the actual interpreters, source commits, installed versions and lockfile SHA-256 values. Path repository lock hashes include the temporary source path and therefore record a run's provenance rather than serving as a cross-run identity.

Current comparisons:

- Every one of the 157 catalog constants in missing, valid, unrecognized and predefined cases, with explicit PHP value/type and definition state. Both legacy profiles must match their oracle exactly.
- Native byte-exact secret handling has field-specific D09 expectations for the 13 raw-string catalog entries. The report records each exact before/after value using synthetic fixtures. No general escaping normalization is permitted.
- 27 aliases, boundary-sensitive names and unknown values through each of WP_ENV, WORDPRESS_ENV and WP_ENVIRONMENT_TYPE, against both baselines.
- The real upstream Composer command and standalone candidate generate index/wp-config files, then a fresh process executes the index through a stub core. Runtime reports compare constants, debug defaults, URLs, content/core paths, database defaults, salts, prefix and boot count across four canonical environments. Only the two path fields substitute the fixture root. D13 explicitly requires an upstream Composer classloader and a candidate without one.
- Warm caches must bypass malformed environment files and retain the exact cold runtime report. A real DB_PASSWORD override is checked separately: D06 requires the oracle's cached value and the candidate's actual process value.
- The dev oracle's omitted `env-dir` failure is preserved as `dev/default-env-dir` under D23. Generated-runtime comparisons explicitly set `env-dir: "."` on both sides; the candidate default also has to generate and boot successfully. See ADR 0011.
- Content publication compares complete target trees with exact file hashes and normalized symlink destinations in copy/symlink modes. Core-content moves compare the full destination and source removal. D02 records the release's hidden-file copy and copy-only dropins; the release compatibility profile also restores hidden-file publication.
- A synthetic installed dropin package reproduces D10: both oracles remove its source and license; the dev oracle leaves a dangling symlink. The candidate must retain source/license and expose a working link with the expected content hash. Only fixture package metadata is added; upstream source is never patched.
- WP-CLI fixtures compare exact argument queues. D17 records the release validator's missing eval-file execution; D24 records dev's splitting of a spaced argument and filtering of zero, versus native literal arguments.
- The dev VCS fixture has an upstream generation marker but no ignore rules. D18 requires the oracle's accepted marker and the candidate's failing actual protection check. A separate CI test executes real Git, Mercurial and SVN and fails if their tests are skipped.

The report is `build/differential/report.json`, uploaded by the dedicated CI job. An unregistered mismatch or non-executable oracle fails the run. Temporary oracle sources and dependencies are removed on exit.

This is incremental acceptance evidence. Complete generated PHP/template snapshots, additional cache and publication failure cases, callbacks, and real consumer/admin/kernel checks remain required. No whole-file or whole-section exclusion grants acceptance to untested statements. Use `--scope constants`, `--scope environment`, `--scope generated` or `--scope steps` for a focused rerun; the report identifies that reduced scope.
