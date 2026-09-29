# Command line

The standalone runner uses the current project directory and its Composer vendor path. `COMPOSER_VENDOR_DIR` overrides the manifest's vendor path. The Composer bridge sends the actual project root, vendor/bin paths, invocation mode, dev mode and console settings through a private, versioned context file and removes it after use.

| Invocation | Meaning |
| --- | --- |
| `vendor/bin/sympress-runtime` | Default and custom steps, respecting configured skips |
| `vendor/bin/sympress-runtime index wpconfig` | Only the requested steps, in that order; WP-CLI is always last |
| `vendor/bin/sympress-runtime --skip index` | Exclude the named steps in addition to configured skips |
| `--skip-custom` | Exclude custom steps in full/opt-out mode |
| `--ignore-skip-config` | Ignore only the configured skip list |
| `--list-steps` | List matching steps, sorted; never execute steps or connect to the database |
| `--force` | Allow overwriting protected files; directories and symlinks remain protected |
| `validate` | Validate JSON/schema and semantic configuration without executing PHP providers or the run-only autoload file |
| `flush-env-cache` | Remove only the runtime cache in the configured environment directory; WordPress installation is not required |
| `-n`, `--no-interaction` | Use documented question defaults |
| `-q`, `-v`, `-vv`, `-vvv`, `--ansi`, `--no-ansi` | Standard Symfony Console output controls |

`--skip` without names is an error. Native explicit selection wins over `skip-steps` and `--skip-custom`. Command-only steps are available only through explicit selection or listing. Unknown selected names produce diagnostics; a partial valid selection still executes, while a selection containing no valid steps fails.

Composer exposes `composer sympress-runtime [steps] [flags]`, `composer sympress-runtime:validate` and `composer sympress-runtime:flush-env-cache`. Install/update events run at priority 0, before asset-compiler's documented -1000 priority. The host uses Composer APIs and PHP standard-library process launching; project Symfony services are instantiated only in the child.

Exit status is zero for successful/no-op execution, nonzero for invalid input, configuration errors or any failed step. A blocking step stops subsequent work on pure ERROR; SUCCESS|ERROR continues but the command still fails. Composer event failures stop subsequent root scripts.

Legacy profiles retain their documented selection differences, including rejecting positional opt-in combined with listing. Native mode permits this combination. The current test suite verifies root-script ordering; real asset-compiler coexistence remains a later acceptance check.

During phased implementation, pending built-in steps are labeled and fail if selected. `doctor`, `check`, `dump-env` and `migrate` are reserved command names and are not yet implemented.
