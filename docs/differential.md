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

The report is `build/differential/report.json`, uploaded by the dedicated CI job. An unregistered mismatch or non-executable oracle fails the run. Temporary oracle sources and dependencies are removed on exit.

This is incremental acceptance evidence. Complete normalized file snapshots, additional cache edge cases, callbacks, the remaining default steps, and real consumer/admin/kernel checks remain required. No whole-file or whole-section exclusion grants acceptance to untested statements. Use `--scope constants`, `--scope environment` or `--scope generated` for a focused rerun; the report identifies that reduced scope.
