# Migrating WP Starter configuration

During setup, `muloader` recognizes the exact stock WP Starter loader template and moves the generated file to `wpstarter-mu-loader.php.sympress-backup` only after writing its replacement. The backup preserves the original bytes and is not loaded by WordPress. This also retires a stale generated loader when no MU packages remain. Customized loaders, symlinks and an existing backup require manual review; they are never silently replaced. The recognition template retains the upstream MIT notice in `NOTICE`.

Install the runtime with Composer scripts/plugins disabled while preparing the switch. Keep the existing lockfile and generated files until the migration has been reviewed.

```sh
vendor/bin/sympress-runtime migrate --dry-run --json
vendor/bin/sympress-runtime migrate
```

The command reads inline `extra.wpstarter`, referenced JSON and root `wpstarter.json`, followed by native sources. Native values win and root files win within each family. It writes `sympress-runtime.json`, preserving the resolved compatibility profile and explicitly recording changed defaults. `skip-db-check=true` becomes `db-check=false`. Option paths remain relative to the project root.

| Option | Behavior |
| --- | --- |
| `--output=<path>` | Choose a target inside the project; point `extra.sympress-runtime` to it when it is not the root default |
| `--dry-run` | Analyze and report without creating directories or files |
| `--force` | Replace a differing regular target after review; never replace an original source, directory or symlink |
| `--json` | Print the target, status, profile, option provenance, PHP findings and next actions; configuration values are omitted |

The Composer equivalent is `composer sympress-runtime:migrate` with the same options. Identical output is a no-op, including its modification time and permissions. New output is atomically installed with mode 0600. The command retains all original files and does not change `composer.json` or the lockfile.

PHP files are tokenized without execution. The report gives file/line, symbol, replacement and guidance for legacy namespace imports, relative references inside the legacy namespace, class strings and Composer types. It examines project PHP files, configured providers/templates and installed runtime extension directories from JSON metadata. Generated/dependency/cache directories are excluded from the general project scan; explicitly configured providers and extensions are still included. It never executes `installed.php`, project autoloaders or command providers. `ready=false` means PHP findings need review; `ready=true` only means this static scan found none. Dynamically constructed class names, indirect calls and unsupported custom templates still require human review.

After review, remove `extra.wpstarter`, archive the old root config, replace the WP Starter dependency and allow-plugin entry, and update the lockfile. Convert provider code to the injected `$services` variable (`SymPress\Runtime\Services`), for example `$services->env()` and `$services->dbChecker()`. Replace Composer callback arguments with `RunContext`. A legacy `Steps` object used as an individual step needs an explicit `StepInterface` rewrite.

Compatibility remains available until PHP and configuration migration are complete. Legacy configuration sources, extension metadata and lifecycle names produce diagnostics; loaded PHP references report each legacy symbol once per invocation. Eager type aliases preserve PHP parameter checking without loading the old package. Set `compatibility=false` after removing old sources, extension types/metadata, lifecycle names and deprecated options. It disables generated getter/filter adapters and rejects remaining legacy configuration/metadata clearly. HTTP deprecations go to the log and the WordPress deprecation action, never into the response body.

Regenerate the runtime files, validate, run doctor, and verify WordPress login, MU-loader ownership, kernel boot and `wp console debug:container` in a fresh environment before switching production. The migration command alone is not a full parity or deployment acceptance result.
