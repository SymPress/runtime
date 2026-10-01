# WP-CLI

Use the `wpcli` step to run project automation after the generated configuration
is ready. The `wpcliconfig` step separately writes `wp-cli.yml`.

## Find or install the tool

Runtime first resolves an installed WP-CLI Composer package, then the project's PHAR. `install-wp-cli` controls whether a missing PHAR can be downloaded. The built-in tool requires its release SHA-512 checksum and fails closed on verification errors. A checksum fetched from the same release origin verifies bytes against that origin; it is not an independent authenticity guarantee.

Installing `wp-cli/wp-cli-bundle` with Composer provides the standard command
packages and its `wp-cli/wp-cli` dependency. Runtime uses that dependency's
`php/boot-fs.php` before considering a local PHAR or network access. This is the
entry point used by the [official WP-CLI wrapper](https://github.com/wp-cli/wp-cli/blob/main/bin/wp);
the [bundle manifest](https://github.com/wp-cli/wp-cli-bundle/blob/main/composer.json)
declares the command dependencies. `install-wp-cli: false` continues to allow
installed Composer packages and local PHARs while disabling downloads.

For a reproducible PHAR installation, configure an exact release and optionally an
independently verified SHA-256 digest:

```json
{
  "wp-cli-version": "2.12.0"
}
```

`wp-cli-sha256` accepts a 64-character hexadecimal digest and also checks an existing
local PHAR. The release SHA-512 sidecar remains mandatory for a downloaded PHAR;
`--update-lock` never overrides either explicit digest assertion. With no version
configured, Runtime looks up the latest official release. Native projects fail if
that lookup cannot identify a supported artifact; legacy profiles retain their
minimum-version fallback. A configured version avoids discovery entirely.

Native downloads also use the project [download lock](downloads.md). Its first
successful, verified download records the PHAR SHA-256; later changes at the same
URL or across WP-CLI release URLs require an explicit reviewed lock update. A
previously pinned local PHAR is also checked before execution. Existing project-owned
PHARs without a pin remain usable; select `wpcli` with `--update-lock` to adopt
reviewed local bytes. Already installed Composer packages remain governed by Composer,
and an existing local PHAR does not trigger a download. Move it aside before
requesting a new release download.

The shared `download-max-bytes` limit also bounds the PHAR, release metadata and checksum sidecar downloads. Its default is 16 MiB; set an explicit larger positive byte count if a trusted release requires it. Oversized responses fail before replacing an existing artifact.

## Run files and commands

`wpcliconfig` generates the configured core path and any bootstrap needed by the selected profile. `wpcli` runs last and processes `wp-cli-files` before `wp-cli-commands`. Providers are evaluated only when this step executes, not during validation or listing. Commands use WP-CLI syntax including the leading `wp`, for example:

```json
{
  "wp-cli-files": [
    {"file": "dev-ops/prepare.php", "args": ["example"], "skip-wordpress": true}
  ],
  "wp-cli-commands": ["wp core version", "wp option get home"]
}
```

Each file entry can also be a simple PHP path. Paths are project-relative; descriptors supply ordered arguments and `skip-wordpress` for code that must run without loading WordPress. WP-CLI exposes eval-file arguments as `$args`. Missing or unreadable eval files are reported and skipped. A failed executed command fails the step and stops the remaining commands.

## Load commands from a provider

Command lists may be inline, loaded from JSON or returned by trusted PHP providers. PHP providers receive `$services` (`SymPress\Runtime\Services`) so they can inspect the environment and DB state lazily. Do not put secrets into logged command strings. Quotes and argument boundaries are preserved by the process/tool APIs; use the documented command form rather than appending shell redirections.

For example, save this as `build-scripts/wp-commands.php` and set
`wp-cli-commands` to that path:

```php
<?php

// $services is provided by Runtime when the step executes.
return $services->dbChecker()->status()->installed === true
    ? ['wp core version']
    : ['wp cli version'];
```

## Run the step

```sh
vendor/bin/runtime -n wpcliconfig wpcli
php wp-cli.phar core version
php wp-cli.phar runtime doctor --json
```

Native generated `wp-cli.yml` registers `runtime` before WordPress loads. It
forwards commands and options to the standalone process and preserves the exit
status. No kernel is required. This includes `validate`, `dump-env`,
`flush-env-cache`, `env:diff`, `prune`, `migrate`, and setup step selection.
The wrapper is noninteractive and accepts WP-CLI's `--json` rewrite to
`--format=json`. WP-CLI global flags remain WP-CLI options.

If you maintain your own YAML, preserve its existing `exec` entries and add an
entry that evaluates this PHP (adjust paths to your installed vendor and root):

```php
(require '/srv/site/vendor/sympress/runtime/src/Bridge/WpCli/register.php')('/srv/site');
```

Compatibility profiles keep their existing YAML output; they can opt in with the
same registration. The registration file and callable are supported bootstrap
entry points; implementation classes below it are internal.

`wp console doctor --json` additionally requires the kernel integration. WP-CLI
may emit its own PHP-version deprecations; configure PHP to write them to stderr
if parsing JSON output. Runtime does not suppress upstream diagnostics.

Standalone and Composer execution share the same services and generated configuration. `wp-config-autoload` determines early Composer loading for WordPress requests and must agree with application/MU bootstrap ownership. See [migration](migration.md), [sections](wp-config.md) and [CLI](cli.md).
