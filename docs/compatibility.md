# Compatibility with existing projects

Use this guide when adopting Runtime in an existing WP Starter project. New
projects can use the native [getting-started guide](getting-started.md).

The compatibility layer is deprecated before Runtime 1.0, remains supported
throughout 1.x, and is scheduled for removal in 2.0. Configuration profiles,
legacy environment controls, PHP aliases and extension metadata continue to
work while you migrate. See the [compatibility policy](compatibility-policy.md).

## Prepare and preview migration

During setup, `muloader` recognizes the exact stock WP Starter loader template and moves the generated file to `wpstarter-mu-loader.php.sympress-backup` only after writing its replacement. The backup preserves the original bytes and is not loaded by WordPress. This also retires a stale generated loader when no MU packages remain. Customized loaders, symlinks and an existing backup require manual review; they are never silently replaced. The recognition template retains the upstream MIT notice in `NOTICE`.

Install the runtime with Composer scripts/plugins disabled while preparing the switch. Keep the existing lockfile and generated files until the migration has been reviewed.

```sh
vendor/bin/runtime migrate --dry-run --json
vendor/bin/runtime migrate
```

The command reads inline `extra.wpstarter`, referenced JSON and root `wpstarter.json`, followed by native sources. Native values win and root files win within each family. It writes `sympress-runtime.json`, preserving the resolved compatibility profile and explicitly recording changed defaults. `skip-db-check=true` becomes `db-check=false`. Option paths remain relative to the project root.

## Choose a behavior profile

Keep `release-3.0.1` or `upstream-dev` while preserving the corresponding WP Starter behavior. Selecting `native` is a separate behavior change: explicit step selections override configured skips, `WP_ENVIRONMENT_TYPE` has first selector priority, local dotenv overrides are enabled, and VCS/step ordering differs. Renaming configuration keys alone does not require switching profiles. Review [ADR 0005](adr/0005-compatibility-and-differences.md) before making that decision.

Legacy profiles default `wp-config-autoload` to true; migration records that inherited value explicitly. It loads the actual configured vendor autoloader after WordPress's hook API and before environment PHP and `early-hook-file`, preserving Composer `autoload.files` callbacks and vendor classes at that stage. Retain it if any early code/plugin relies on this behavior. Native defaults false; the environment parser itself remains independent of Composer either way. Existing edited `AUTOLOAD` sections are preserved, so inspect regeneration diagnostics and custom templates rather than assuming an option change rewrote hand-maintained PHP.

## Migration command reference

| Option | Behavior |
| --- | --- |
| `--output=<path>` | Choose a target inside the project; point `extra.sympress-runtime` to it when it is not the root default |
| `--dry-run` | Analyze and report without creating directories or files |
| `--force` | Replace a differing regular target after review; never replace an original source, directory or symlink |
| `--json` | Print the target, status, profile, option provenance, PHP findings and next actions; configuration values are omitted |

The Composer equivalent is `composer sympress-runtime:migrate` with the same options. Identical output is a no-op, including its modification time and permissions. New output is atomically installed with mode 0600. The command retains all original files and does not change `composer.json` or the lockfile.

## Review PHP changes

PHP files are tokenized without execution. The report gives file/line, symbol, replacement and guidance for legacy namespace imports, relative references inside the legacy namespace, class strings and Composer types. It examines project PHP files, configured providers/templates and installed runtime extension directories from JSON metadata. Generated/dependency/cache directories are excluded from the general project scan; explicitly configured providers and extensions are still included. It never executes `installed.php`, project autoloaders or command providers. `ready=false` means PHP findings need review; `ready=true` only means this static scan found none. Dynamically constructed class names, indirect calls and unsupported custom templates still require human review.

After review, remove `extra.wpstarter`, archive the old root config, replace the WP Starter dependency and allow-plugin entry, and update the lockfile. Convert provider code to the injected `$services` variable (`SymPress\Runtime\Services`), for example `$services->env()` and `$services->dbChecker()`. Replace Composer callback arguments with `RunContext`. A legacy `Steps` object used as an individual step needs an explicit `StepInterface` rewrite.

Compatibility remains available until PHP and configuration migration are complete. Legacy configuration sources, extension metadata and lifecycle names produce diagnostics; loaded PHP references report each legacy symbol once per invocation. Eager type aliases preserve PHP parameter checking without loading the old package. Set `compatibility=false` after removing old sources, extension types/metadata, lifecycle names and deprecated options. It disables generated getter/filter adapters and rejects remaining legacy configuration/metadata clearly. HTTP deprecations go to the log and the WordPress deprecation action, never into the response body.

Regenerate the runtime files, validate, run doctor, and verify WordPress login, MU-loader ownership, kernel boot and `wp console debug:container` in a fresh environment before switching production. The migration command alone is not a full parity or deployment acceptance result.

## Disable compatibility after migration

With `compatibility=false`, `WPSTARTER_ENV_LOADED` and `WP_STARTER_ENV_TO_CONST` no longer control parsing or constant creation. Replace them with `SYMPRESS_RUNTIME_ENV_LOADED` and `SYMPRESS_RUNTIME_ENV_TO_CONST` if those capabilities are needed. Enabled legacy controls emit name-only deprecation diagnostics, without exposing values or writing into HTTP responses. WordPress environment aliases such as `WP_ENV` remain supported.

Caches and build dumps record the compatibility mode. Earlier formats without that field are treated as compatibility-enabled. After disabling compatibility, run `vendor/bin/runtime flush-env-cache` and regenerate any build dump with `vendor/bin/runtime dump-env <environment>` before deploying the new generated configuration. A mode mismatch fails explicitly instead of restoring constants derived through legacy controls.

## Existing extension packages and PHP APIs

Packages of type `wpstarter-extension` remain supported with compatibility enabled.
They may use `extra.wpstarter-autoload` with `psr-4` and `files` entries.
Paths resolve from the installed package directory. Root setup autoload runs first,
then package files. Missing files are skipped; malformed legacy entries are ignored.
Prefer `sympress-runtime-extension` and `extra.sympress-runtime-autoload` for new packages.

The setup child aliases the legacy Config/Result, Paths, IO/Question, Locator, Step,
optional step interfaces, Steps and ScriptHaltSignal names. `Locator` resolves to
`Services`; option-name constants and `Paths::WP_STARTER` remain usable.
An untyped positional constructor receives Services and, if requested, RunContext.
Typed Locator injection is supported.

These adapters preserve supported operations, not arbitrary construction of old
framework objects. Mandatory Composer-object constructor arguments require a rewrite
and produce a diagnostic. Runner is not StepInterface: whole-run callbacks typed
as a legacy Step must accept Runner instead. See [custom steps](custom-steps.md)
for the native service API and callback signatures.

Whole-run script aliases execute in this order when more than one is configured:
`pre-wpstarter`, `pre-`, `pre-runtime`, `pre-sympress-runtime`
(and the equivalent `post-` names). The bare suffix is a retained legacy alias.
Use only the native lifecycle name in new configurations.

## Retained command and environment names

`migrate-from-wpstarter` remains an alias for `migrate`.
Existing `WPSTARTER_*` environment controls and deprecated getter/filter names
remain compatibility interfaces. Consult the migration report before disabling
them; changing product terminology does not remove the adapters.

Release compatibility preserves optional confirmation for explicitly selected
steps and rejects positional selection combined with `--list-steps`.
Native mode permits that combination and bypasses optional confirmation for an
explicit selection. Native MU loading also sorts paths and exposes WordPress
loading hooks/admin rows; legacy profiles retain their original loading behavior.
