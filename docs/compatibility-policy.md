# Compatibility policy

This is the contract for Runtime 1.x. It takes effect with 1.0.0; prereleases are
for validation of that contract. A change that breaks a supported interface
requires a new major version. Additions use minor versions; compatible fixes use
patch versions.

## PHP extensions

Use the [public API inventory](api.md) and the examples in [Custom steps](custom-steps.md).
An `@api` member is stable. An `@internal` service class can be used as a typed
handle through its explicitly marked `@api` methods; its constructor and other
methods are implementation details. Public PHP visibility alone is not a support
promise. Composer internals, the container implementation and generated payload
namespaces remain internal.

The architecture suite checks source classification and public extension
fixtures. Public signatures, return semantics, lifecycle ordering and documented
service behavior are covered by the API, extension and parity tests.

## Configuration

All 56 existing schema keys retain their validation and default semantics in 1.x.
New optional keys may be added. Unknown extension keys remain subject to the
documented custom-validator rules. The table records native raw defaults;
[configuration](configuration.md) explains lazy path and provider resolution.

Nine schema keys are invocation state, not user settings: `update-lock`,
`composer-updated-packages`, `is-composer-install`, `is-composer-update`,
`is-wpstarter-command`, `is-wpstarter-selected-command`, `is-runtime-command`,
`is-runtime-selected-command` and `wp-config-php-path`. Runtime supplies them;
the schema's inclusion does not authorize overriding them in project JSON.

| Key | Native raw default |
| --- | --- |
| `allow-insecure-downloads` | `false` |
| `autoload` | `"sympress-runtime-autoload.php"` |
| `bundle-bootstrap` | `false` |
| `cache-env` | `"auto"` |
| `check-vcs-ignore` | `true` |
| `command-steps` | `null` |
| `compatibility` | `true` |
| `compatibility-profile` | `"native"` |
| `composer-managed` | `"auto"` |
| `composer-updated-packages` | `[]` |
| `content-dev-dir` | `"content-dev"` |
| `content-dev-op` | `"auto"` |
| `create-vcs-ignore-file` | `true` |
| `custom-steps` | `null` |
| `db-check` | `true` |
| `download-checksums` | `[]` |
| `download-lock` | `true` |
| `download-max-bytes` | `16777216` |
| `dropins` | `null` |
| `dropins-op` | `"auto"` |
| `early-hook-file` | `""` |
| `env-bootstrap-dir` | `null` |
| `env-dir` | `null` |
| `env-example` | `true` |
| `env-file` | `".env"` |
| `env-local-overrides` | `true` |
| `generated-file-mode` | `"0600"` |
| `install-wp-cli` | `true` |
| `is-composer-install` | `null` |
| `is-composer-update` | `null` |
| `is-runtime-command` | `null` |
| `is-runtime-selected-command` | `null` |
| `is-wpstarter-command` | `null` |
| `is-wpstarter-selected-command` | `null` |
| `kernel-boot` | `false` |
| `kernel-build-id` | `null` |
| `move-content` | `false` |
| `prevent-overwrite` | `null` |
| `register-theme-folder` | `false` |
| `require-download-checksums` | `false` |
| `require-wp` | `true` |
| `required-env` | `[]` |
| `scripts` | `[]` |
| `skip-db-check` | `false` |
| `skip-steps` | `null` |
| `steps` | `null` |
| `templates-dir` | `null` |
| `unknown-dropins` | `false` |
| `update-lock` | `false` |
| `wp-cli-commands` | `[]` |
| `wp-cli-files` | `[]` |
| `wp-cli-sha256` | `null` |
| `wp-cli-version` | `null` |
| `wp-config-autoload` | `false` |
| `wp-config-php-path` | `derived target; not user-settable` |
| `wp-version` | `null` |

Compatibility profiles preserve their own defaults. Both use
`cache-env: true`, `composer-managed: false`, `download-lock: false`,
`autoload: "wpstarter-autoload.php"`, `env-local-overrides: false` and
`wp-config-autoload: true`. The release profile additionally uses
`content-dev-op: "symlink"`, `skip-steps: []`, and `null` for `scripts`,
`wp-cli-commands` and `wp-cli-files`. The selected profile names itself in
`compatibility-profile`. The executed contract snapshot freezes all profiles.

## Commands

The supported entry points are `vendor/bin/runtime`, `wp runtime` and
`composer runtime` (alias of `composer sympress-runtime`). The Composer diagnostic
commands use the colon form, for example `composer runtime:doctor`.

The [CLI reference](cli.md) defines the commands, flags and exit meanings.
`doctor`, `check`, `validate`, `dump-env`, `flush-env-cache`, `env:diff`,
`prune` and `migrate` retain their names and documented inputs. Setup step
selection, all twelve built-in step names, custom steps and explicit kernel
maintenance remain supported. JSON fields already documented retain their
meaning; consumers must tolerate additional fields.

Success is 0. A failed diagnostic or drift check is 1; an inconclusive diagnostic
or drift check is 2. Invalid setup configuration returns 2; other Console input
errors return a nonzero status. Step failure returns a nonzero status. Do not
treat a generic nonzero setup status as a specific diagnostic category.
WP-CLI runs the same binary in a child process and preserves its status. Its own
global flags and startup diagnostics belong to WP-CLI; `--json`/its
`--format=json` rewrite are normalized for Runtime. The wrapper runs
noninteractively. No SymPress kernel or working WordPress bootstrap is required.

Composer installs one executable per binary name. If another dependency also
publishes `runtime`, resolve that collision in the root project or invoke
`php vendor/sympress/runtime/bin/runtime` explicitly.

## Environment, hooks and generated configuration

The [environment guide](environment.md) and [constant catalog](constants.md)
define conversion, precedence and secret-file behavior. The public Runtime
controls `SYMPRESS_RUNTIME_ENV_LOADED` and `SYMPRESS_RUNTIME_ENV_TO_CONST` retain
their behavior. Generated `SYMPRESS_RUNTIME_PATH`, `SYMPRESS_RUNTIME_ENV_PATH`
and `SYMPRESS_RUNTIME_WP_CONFIG_PATH` retain their documented purpose.

The WordPress filter `sympress.runtime.skip-cache-env` and native loader action
`mu_plugin_loaded` retain their arguments and timing. All nineteen existing
[section names and editing semantics](wp-config.md) remain stable. Already-defined
WordPress constants and edited managed sections remain preserved. Template
placeholder names are extension inputs; generated whitespace and private payload
class names are not stable APIs.

## Persisted files and upgrades

The [file format contract](file-formats.md) covers the download lock, runtime
environment cache, build dump and payload manifest. Readers validate formats;
they do not silently interpret an unknown future version. Deployments retain old
payloads for rollback, as described in [Deployment](deployment.md). An internal
recovery journal or Composer metadata file is not a user-editable API.

## Deprecation

A deprecation is documented at least one minor release before removal, and
removal happens only in the next major. Compatibility adapters are deprecated
before 1.0, remain functional for all 1.x releases, and are scheduled for removal
in 2.0. This includes legacy configuration names, environment controls, extension
metadata and legacy PHP aliases. Use the [migration guide](compatibility.md);
deprecation does not silently disable an existing feature in 1.x.

Security fixes may reject unsafe inputs that were never part of the supported
contract. Such changes receive a security/release note and a documented safe
migration path.
