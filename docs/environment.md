# Environment configuration

Keep the same application code across environments and supply the values that
differ: database credentials, public URLs, debugging and service settings. Runtime
reads these values before WordPress starts.

## Start with a private .env file

```dotenv
WP_ENVIRONMENT_TYPE=development
WP_HOME=https://my-site.test
WP_SITEURL=${WP_HOME}
DB_HOST=127.0.0.1
DB_NAME=my_site
DB_USER=my_site
DB_PASSWORD='your-local-password'
DB_TABLE_PREFIX=wp_
WP_DEBUG=true
WP_DEBUG_DISPLAY=false
```

Actual process environment values take priority. In native mode, files are loaded
from lowest to highest priority:

1. `.env`
2. `.env.local`
3. `.env.{environment}`
4. `.env.{environment}.local`
5. The process environment

The generic `.env.local` file is skipped for the raw `test` environment. Disable
`env-local-overrides` to use only the base and environment-specific files.
`env-dir` and `env-file` change the directory and base filename.

## Select an environment

Native selection checks `WP_ENVIRONMENT_TYPE`, then `WP_ENV`, then
`WORDPRESS_ENV`. It fixes the selected file chain for one load: a value read
later cannot redirect the loader into another environment's files.

The raw name selects files; WordPress and the kernel receive a normalized
`local`, `development`, `staging` or `production` value. Unknown names use the
production fallback and a diagnostic. Existing compatibility profiles retain
their own precedence; see [Compatibility](compatibility.md).

Use `SYMPRESS_RUNTIME_ENV_LOADED` only when trusted bootstrap code has already
loaded the environment. A set marker skips dotenv parsing.

## WordPress constants and types

The [constants reference](constants.md) lists every supported built-in name and
its type. Missing, empty, false and zero are distinct values. Existing PHP
constants are retained rather than redefined.

You can add your own mappings:

```dotenv
SYMPRESS_RUNTIME_ENV_TO_CONST=PROJECT_CACHE:bool,PROJECT_LIMIT:int
PROJECT_CACHE=true
PROJECT_LIMIT=100
```

Supported native type names include `bool`, `int`, `float`, `string`,
`raw_string`, `int_or_bool`, `string_or_bool` and `octal_mod`.
Their aliases and exact behavior are in the constants reference. Do not use a
sanitizing string cast for passwords.

## Early PHP configuration

Set `env-bootstrap-dir` to load `{environment}.php` from a project directory.
Use `early-hook-file` for shared boot code. These files are trusted PHP.

Set `wp-config-autoload: true` if this code needs Composer classes. WordPress's
hook API loads before that autoloader, so Composer `autoload.files` can register
early hooks. If your MU/application bootstrap owns autoloading, keep its order
deliberate; Runtime does not require a second application bootstrap.

## Runtime cache versus deployment dump

| | Runtime cache | Deployment dump |
| --- | --- | --- |
| File | `.env.cached.php` | `.env.dump.php` |
| Created by | An eligible request | `dump-env <environment>` |
| Intended use | Avoid repeated dotenv parsing | Prepare a fixed environment before deployment |
| Request-time writes | Possible when caching is enabled | Disabled while a valid dump is present |
| Removed by `flush-env-cache` | Yes | No |

Runtime caching is skipped for the raw `local` environment or a truthy
`WP_DEVELOPMENT_MODE`. The `development` environment alone does not disable it. The
`sympress.runtime.skip-cache-env` filter can suppress it for other environments;
its second argument is the raw environment name. Process values always win over
cached or dumped values.

```sh
vendor/bin/sympress-runtime dump-env production
vendor/bin/sympress-runtime flush-env-cache
```

A dump takes precedence over the runtime cache. The explicit dump environment
must agree with an actual process selector. A mismatch fails instead of loading
another environment's credentials.

Caches and dumps record compatibility mode. Flush the cache and rebuild the dump
when changing that mode; older formats without the field are treated as
compatibility-enabled.

Both files may contain secrets. Keep them outside web access and version control.
They default to mode `0600`; provision ownership so the deployed PHP identity can
read them. See [Deployment](deployment.md) for release and rollback steps.
