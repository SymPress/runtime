# ADR 0016: Static migration and compatibility diagnostics

Status: Implemented; final consumer and parity acceptance remains open.

Migration is a separate static operation before setup-only autoloading and package discovery. It reads configuration and installed JSON metadata, tokenizes relevant PHP, and atomically writes a new private configuration. No manifest, source, salt or environment file is rewritten. Existing output conflicts require force; original files and symlink targets are never overwritten. Repeated identical migrations are no-ops.

Moving legacy configuration must not silently switch environment override behavior, the default autoload path or other release defaults. Retain the explicit resolved profile and materialize defaults that differ from native. Record source provenance separately from values, so JSON reports cannot disclose configuration secrets.

The analyzer reports supported native substitutions and manual Composer/runner rewrites. It cannot prove dynamically constructed references safe. Its `ready` field describes only the static PHP findings, not the whole installation.

Legacy PHP interfaces and parameter aliases must be registered eagerly: PHP type checks do not reliably autoload an alias before a native service is passed to it. Diagnose legacy declarations/references from PHP files actually included during setup, deduplicated by symbol. Registering the aliases alone is not a deprecated use. Compatibility disabled rejects legacy configuration, lifecycle names and extension metadata; no adapters are registered in that child process.

Generated runtime deprecations preserve the CLI error/deprecation behavior. HTTP requests invoke the WordPress deprecation action and log a value-free message once per alias, avoiding response corruption even when PHP display_errors is enabled.
