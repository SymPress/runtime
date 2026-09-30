# WP-CLI

Runtime first resolves an installed WP-CLI Composer package, then the project's PHAR. `install-wp-cli` controls whether a missing PHAR can be downloaded. The built-in tool requires its release SHA-512 checksum and fails closed on verification errors. A checksum fetched from the same release origin verifies bytes against that origin; it is not an independent authenticity guarantee.

The shared `download-max-bytes` limit also bounds the PHAR, release metadata and checksum sidecar downloads. Its default is 16 MiB; set an explicit larger positive byte count if a trusted release requires it. Oversized responses fail before replacing an existing artifact.

`wpcliconfig` generates the configured core path and any bootstrap needed by the selected profile. `wpcli` runs last and processes `wp-cli-files` before `wp-cli-commands`. Providers are evaluated only when this step executes, not during validation or listing. Commands use WP-CLI syntax including the leading `wp`, for example:

```json
{
  "wp-cli-files": [
    {"file": "dev-ops/prepare.php", "args": ["example"], "skip-wordpress": true}
  ],
  "wp-cli-commands": ["wp core version", "wp option get home"]
}
```

Each file entry can also be a simple PHP path. Paths are project-relative; descriptors supply ordered arguments and `skip-wordpress` for code that must run without loading WordPress. WP-CLI exposes eval-file arguments as `$args`. Missing or unreadable eval files are reported and skipped, preserving upstream behavior. A failed executed command fails the step and stops the remaining commands.

Command lists may be inline, loaded from JSON or returned by trusted PHP providers. PHP providers receive `$services` (`SymPress\Runtime\Services`) so they can inspect the environment and DB state lazily. Do not put secrets into logged command strings. Quotes and argument boundaries are preserved by the process/tool APIs; use the documented command form rather than appending shell redirections.

```sh
vendor/bin/sympress-runtime -n wpcliconfig wpcli
php wp-cli.phar core version
php wp-cli.phar console doctor --json
```

The last command additionally requires the SymPress kernel console integration. It accepts WP-CLI's rewrite of `--json` to `--format=json`. WP-CLI may emit its own PHP-version deprecations; Runtime diagnostics redact their structured configuration output but do not claim to suppress all upstream messages.

Standalone and Composer execution share the same services and generated configuration. `wp-config-autoload` determines early Composer loading for WordPress requests and must agree with application/MU bootstrap ownership. See [migration](migration.md), [sections](wp-config.md) and [CLI](cli.md).
