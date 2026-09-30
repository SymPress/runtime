# Environment, caches and dumps

Native environment selection uses `WP_ENVIRONMENT_TYPE`, then `WP_ENV`, then `WORDPRESS_ENV`. Legacy profiles preserve their reverse legacy selector precedence. Selection is stable for one load: a later file cannot redirect the loader into another environment chain. The raw environment is retained for environment-specific filenames; WordPress and the kernel receive the canonical local/development/staging/production value. Unknown values use the documented production fallback and diagnostics.

With native defaults, precedence from lowest to highest is `.env`, `.env.local`, `.env.{rawEnvironment}`, `.env.{rawEnvironment}.local`, then actual process environment. Raw `test` omits generic `.env.local`. Legacy profiles default to the two-file `.env`/`.env.{rawEnvironment}` chain until `env-local-overrides` is enabled. `env-dir` and `env-file` change the base location/name. A set `SYMPRESS_RUNTIME_ENV_LOADED` or compatible `WPSTARTER_ENV_LOADED` marker bypasses all dotenv parsing.

Values are made available through the environment reader and superglobals. The reader does not trust HTTP request headers as deployment selectors. Standard WordPress constants use the catalog's explicit casts; missing, empty, invalid, false and zero are distinct. `SYMPRESS_RUNTIME_ENV_TO_CONST` adds named constant conversions using comma-separated `NAME:TYPE` entries; `WP_STARTER_ENV_TO_CONST` is its deprecated compatibility control. Consult the [catalog/parity reference](parity.md#core-constant-catalog-every-constant-is-an-acceptance-row) for the supported names and tested types. Type names include `bool`, `int`, `float`, `int_or_bool`, `string_or_bool`, `string` and `octal_mod`; `raw_string` is available in upstream-dev/native but absent from the release profile. Native also accepts `int|bool`, `string|bool`, `raw-string` and `mod`. Existing constants are retained rather than redefined.

`compatibility=false` ignores both `WPSTARTER_ENV_LOADED` and `WP_STARTER_ENV_TO_CONST`; their native counterparts remain available. Cache/dump compatibility modes must match. Flush runtime caches and rebuild deployment dumps when changing that mode; see [migration](migration.md).

The generated configuration can execute `{rawEnvironment}.php` from `env-bootstrap-dir` and the configured `early-hook-file`. Treat them as trusted application PHP. Enable `wp-config-autoload` when these files need Composer classes; legacy profiles enable it by default. WordPress's hook API is available before project autoload, so Composer `autoload.files` can register early hooks.

Runtime caching writes `.env.cached.php` in the environment directory when eligible. Local/development-mode requests and `sympress.runtime.skip-cache-env` can suppress caching; the filter receives the raw environment as its second parameter. Cached values never override actual process values.

```sh
vendor/bin/sympress-runtime dump-env production
vendor/bin/sympress-runtime flush-env-cache
```

`dump-env` produces a private `.env.dump.php` for the selected raw environment. A valid dump takes precedence over `.env.cached.php` and disables request-time cache writes. Process values still override dumped values. A conflicting actual environment selector causes dump creation/loading to fail rather than silently use another environment. `flush-env-cache` removes the runtime cache only; it never removes the deployment dump. Rebuild or explicitly retire the dump when changing its environment or baked configuration.

Dumps/caches may contain database credentials and must stay outside public access and version control. Defaults use mode 0600; the deployed PHP identity must be able to read required private files. Prepare ownership and compiled kernel caches during the build, then verify a read-only production request. See [deployment](deployment.md) for the parser payload, relocation and rollback requirements.
