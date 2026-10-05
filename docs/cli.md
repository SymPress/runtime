# Command line

Run these commands from the directory containing your project's `composer.json`.

Version 0.2.0 provides `bin/runtime`, installed by Composer as `vendor/bin/runtime`.
Version 0.1.0 used the executable name `sympress-runtime`. Update shell and CI
scripts when upgrading; see the [0.2.0 upgrade notes](releases/0.2.0.md).

```sh
# Discover commands and their options.
vendor/bin/runtime list
vendor/bin/runtime help doctor

# Inspect configuration without running project providers.
vendor/bin/runtime validate
vendor/bin/runtime --list-steps

# Regenerate only the front controller and WordPress configuration.
vendor/bin/runtime --no-interaction index wpconfig

# Get machine-readable deployment diagnostics.
vendor/bin/runtime doctor --json
```

## Commands and flags

The standalone runner uses the current project directory and its Composer vendor path. `COMPOSER_VENDOR_DIR` overrides the manifest's vendor path. The Composer bridge sends the actual project root, vendor/bin paths, invocation mode, dev mode and console settings through a private, versioned context file and removes it after use.

| Invocation | Meaning |
| --- | --- |
| `vendor/bin/runtime` | Default and custom steps, respecting configured skips |
| `list [--raw] [--format=json]` | List Console commands without loading setup configuration, running providers or recovering package layout |
| `help [command]`, `<command> --help` | Show Symfony Console help; `help run` documents setup options |
| `run [steps]` | Explicit form of the default setup command |
| `vendor/bin/runtime index wpconfig` | Only the requested steps, in that order; WP-CLI is always last |
| `vendor/bin/runtime --skip index` | Exclude the named steps in addition to configured skips |
| `--skip-custom` | Exclude custom steps in full/opt-out mode |
| `--ignore-skip-config` | Ignore only the configured skip list |
| `--list-steps` | List matching steps, sorted; never execute steps or connect to the database |
| `--force` | Allow overwriting protected files; content publication can replace a symlink leaf after preparing its replacement, without modifying the referenced destination; generated configuration and real directories remain protected |
| `validate` | Validate JSON/schema and semantic configuration without executing PHP providers or the run-only autoload file |
| `flush-env-cache` | Remove only the runtime cache in the configured environment directory; WordPress installation is not required |
| `dump-env <environment>` | Resolve an explicit raw environment into a private `.env.dump.php`; does not execute steps or run-only autoload |
| `doctor`, `check` | Read-only project, environment, permission, symlink, database and kernel diagnostics |
| `doctor --json`, `check --json` | Structured redacted results; exit 0 for passed Runtime checks, 1 fail, 2 unknown without failures. Advisory `unverified` consumer activation and `not-applicable` restrictions do not alter this exit status. |
| `kernel-cache` | Explicitly clear only the selected environment's kernel cache/build and discovery metadata |
| `kernel-cache --generate-build-id` | Explicitly generate and persist a new kernel deployment ID |
| `kernel-boot` | Reconcile optional boot ownership; generation requires kernel-boot configuration |
| `migrate` | Write native configuration and statically report PHP migration findings |
| `migrate --output=<path> --dry-run --json` | Preview a private configuration target and structured report without writes |
| `migrate --force` | Replace a differing regular target; originals, directories and symlinks stay protected |
| `-n`, `--no-interaction` | Use documented question defaults |
| `-q`, `-v`, `-vv`, `-vvv`, `--ansi`, `--no-ansi` | Standard Symfony Console output controls |
| `--check [steps]` | Compare managed outputs and setup inputs with the last successful run of this selection; exit 1 for drift, 2 for missing evidence or uninspectable custom behavior. |
| `--dry-run [steps]` | Print the same read-only drift report, without failing solely for drift. Never executes setup providers, database operations or downloads. |
| `--update-lock [steps]` | Refresh download pins for URLs used by the selected steps; explicit configured checksums still apply. Cannot combine with read-only flags. |
| `doctor --production --json` | Strict deployment gate for explicit HTTPS home, debug display, private environment files, dump, file-modification policy, salts and PHP-readable artifacts. |
| `doctor --production --webroot=public --php-user=www-data` | Declare the actual document root and check POSIX permissions for a specific PHP identity. Prefer running the command as that identity to include ACLs. |
| `doctor --database-health [--quick]` | Explicit table health inspection; `--quick` reduces work but does not promise a lock-free check. |
| `prune --keep=2 [--dry-run] [--json]` | Retain active payloads plus the newest N inactive payloads and owned package backups; refuse unsafe or pending recovery state. |
| `env:diff [--json]` | Compare environment example names with the selected file chain/process; never print values or execute substitutions. Exit 1 for missing names. |

## Selecting steps

`list` lists Console commands; `--list-steps` lists your configured setup steps.
Direct selection such as `runtime index wpconfig` still works. If a custom step
shares a Console command name such as `list`, `help` or `run`, select it explicitly
with `runtime run list` or `runtime -- list`. For an opt-out selection, use
`runtime run --skip list`. Only the first positional name selects a command;
`runtime index help` selects the two steps.

`--skip` without names is an error. Native explicit selection wins over `skip-steps` and `--skip-custom`. Command-only steps are available only through explicit selection or listing. Unknown selected names produce diagnostics; a partial valid selection still executes, while a selection containing no valid steps fails.

## Running through Composer

`composer runtime` aliases `composer sympress-runtime`; diagnostic aliases use
the colon form, for example `composer runtime:doctor --json`. Existing names stay
supported in 1.x.

Composer exposes `composer sympress-runtime [steps] [flags]`, `composer sympress-runtime:validate`, `composer sympress-runtime:flush-env-cache` and `composer sympress-runtime:dump-env <environment>`. Install/update events run at priority 0, before asset-compiler's documented -1000 priority. The host uses Composer APIs and PHP standard-library process launching; project Symfony services are instantiated only in the child.

## Exit status

Exit status is zero for successful/no-op execution, nonzero for invalid input, configuration errors or any failed step. A blocking step stops subsequent work on pure ERROR; SUCCESS|ERROR continues but the command still fails. Composer event failures stop subsequent root scripts.

Legacy profiles retain their documented selection differences, including rejecting positional opt-in combined with listing. Native mode permits this combination. See [Compatibility](compatibility.md) for retained command aliases.

## Kernel console and environment dumps

Native generated `wp-cli.yml` registers `wp runtime`. For example,
`wp runtime doctor --json` and `wp runtime validate` run the same standalone
application in a child process before WordPress loads, without needing the kernel.
All Runtime subcommands and setup flags are forwarded; the wrapper is
noninteractive. WP-CLI retains ownership of its global options. Its `--json`
rewrite to `--format=json` is supported. Existing user-owned YAML is preserved:
review and regenerate it with `vendor/bin/runtime --force wpcliconfig`, or add
the registration described in [WP-CLI](wp-cli.md).

Through the kernel console, `wp console doctor --json` and `wp console check --json` accept WP-CLI's automatic rewrite to `--format=json`. The bridge also accepts explicit `--format=text` or `--format=json`; other formats are rejected.

All twelve default steps are implemented. Composer also exposes `sympress-runtime:doctor`, `sympress-runtime:check` and `sympress-runtime:migrate`, including `--json`. Kernel-only maintenance does not require a database connection or an installed WordPress core; mixed selections retain normal preflight checks. See [migration](migration.md) and [kernel integration](kernel-integration.md) for the corresponding contracts.

A build dump takes precedence over the runtime cache and disables request-time cache writes. Actual process values still win. The requested raw environment must agree with an actual process environment selector; otherwise dump creation fails. Remove or rebuild the dump to switch its environment. Flush never deletes a build dump. Deploy generated configuration and dumps with PHP-readable private permissions; defaults are mode 0600.
