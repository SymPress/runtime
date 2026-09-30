# Configuration

All options are optional. Configure `extra.sympress-runtime` as an object or a JSON file path, or create `sympress-runtime.json` in the project root. Root-file values override inline/referenced values by shallow merge; arrays replace earlier arrays. Paths inside a referenced file remain relative to the project root.

Legacy `extra.wpstarter` and `wpstarter.json` are recognized with deprecation diagnostics. Native sources win over legacy sources. Set `compatibility: false` to reject legacy sources. `compatibility-profile: auto` selects release-3.0.1 for legacy-only sources and native otherwise; upstream-dev is explicit. Release defaults retain symlink content publishing, legacy autoload naming and the two-file env policy. See the [parity matrix](parity.md) for exact profile differences.

Top-level Composer extra options `wordpress-install-dir` and `wordpress-content-dir` default to `./wordpress` and `./wp-content`. Core must be inside the root; content must be below the root and within the core parent. The vendor/bin paths come from Composer's actual configuration or installed metadata.

Draft 2020-12 schemas are in `schema/runtime.schema.json` and `schema/runtime-extra.schema.json`. Every run validates configuration before loading custom PHP or writing managed files. `validate` additionally checks existing file/directory paths without evaluating PHP command providers. Unknown extension keys remain available through Config and `appendValidator`; built-in validators cannot be replaced.

`Config` is append-only ArrayAccess returning `Result`. `unwrap()` throws stored errors; `unwrapOrFallback()` falls back only on null/error. `notEmpty()` remains true for false, zero, an empty string and an empty array. Comparison methods are strict. Only new, null or still-default settings can be replaced.

## Native defaults

Internal context fields listed below are readable by steps but rejected in user configuration. They are populated from the actual invocation. These options are implemented; profile-dependent behavior is described below and in the linked user guides.

| Option | Native default |
| --- | --- |
| `autoload` | `"sympress-runtime-autoload.php"` |
| `cache-env` | `true` |
| `check-vcs-ignore` | `true` |
| `command-steps` | `null` |
| `composer-updated-packages` | `[]` |
| `content-dev-dir` | `"content-dev"` |
| `content-dev-op` | `"auto"` |
| `create-vcs-ignore-file` | `true` |
| `custom-steps` | `null` |
| `steps` | `null` |
| `db-check` | `true` |
| `dropins` | `null` |
| `dropins-op` | `"auto"` |
| `early-hook-file` | `""` |
| `env-bootstrap-dir` | `null` |
| `env-dir` | `null` |
| `env-example` | `true` |
| `env-file` | `".env"` |
| `install-wp-cli` | `true` |
| `is-composer-install` | `null` |
| `is-composer-update` | `null` |
| `is-wpstarter-command` | `null` |
| `is-wpstarter-selected-command` | `null` |
| `is-runtime-command` | `null` |
| `is-runtime-selected-command` | `null` |
| `move-content` | `false` |
| `prevent-overwrite` | `null` |
| `register-theme-folder` | `false` |
| `require-wp` | `true` |
| `scripts` | `[]` |
| `skip-db-check` | `false` |
| `skip-steps` | `null` |
| `templates-dir` | `null` |
| `unknown-dropins` | `false` |
| `wp-cli-commands` | `[]` |
| `wp-cli-files` | `[]` |
| `wp-version` | `null` |
| `wp-config-autoload` | `false` |
| `compatibility` | `true` |
| `compatibility-profile` | `"native"` |
| `env-local-overrides` | `true` |
| `allow-insecure-downloads` | `false` |
| `download-checksums` | `[]` |
| `require-download-checksums` | `false` |
| `download-max-bytes` | `16777216` |
| `kernel-boot` | `false` |
| `kernel-build-id` | `null` |

Internal fields: `composer-updated-packages`, `is-composer-install`, `is-composer-update`, `is-runtime-command`, `is-runtime-selected-command`, their two `is-wpstarter-*` compatibility names, and the derived `wp-config-php-path`.

Ask values never block a noninteractive run. Confirmation defaults to yes unless a feature explicitly specifies no (notably unknown dropins). Five invalid answers fall back to the question's configured default. The `prevent-overwrite` list matches normalized root-relative paths using `fnmatch` without backslash escaping. Native unmarked files require confirmation or `--force`; existing directories and symlinks are never replaced by file writes.

`steps` is the native DI contribution list/map. `wp-cli-files` accepts PHP paths or descriptors with `file`, `args` and `skip-wordpress`; command providers remain lazy until WP-CLI execution. [WP-CLI](wp-cli.md) describes their order and failure behavior.

`wp-config-autoload` is false in the native profile and true in `release-3.0.1` and `upstream-dev`. It loads the configured Composer autoloader after WordPress's hook API and before environment PHP/early hooks. The independent environment parser is always shipped in the generated payload. [Migration](migration.md) explains preserving this behavior when changing profiles.

`download-checksums` maps URLs to expected SHA-256 digests. `require-download-checksums=true` rejects downloads without a configured digest; `allow-insecure-downloads` defaults to false. `download-max-bytes` is a positive integer, defaulting to 16 MiB, and bounds downloads through the URL-download service, including WP-CLI PHARs, release metadata and checksum sidecars. Raise it explicitly for a larger trusted artifact. WP-CLI PHAR verification additionally uses its SHA-512 contract. See [the settings cheat sheet](settings-cheat-sheet.md) and [kernel integration](kernel-integration.md).
