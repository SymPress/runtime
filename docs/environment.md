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
vendor/bin/runtime dump-env production
vendor/bin/runtime flush-env-cache
```

A dump takes precedence over the runtime cache. The explicit dump environment
must agree with an actual process selector. A mismatch fails instead of loading
another environment's credentials.

Caches and dumps record compatibility mode. Flush the cache and rebuild the dump
when changing that mode; older formats without the field are treated as
compatibility-enabled.

Native payloads persist file-owned values only; process-only values and process
overrides are read live and never serialized. A file default remains available
when a process override is removed. Native runtime caches store interpolation
templates and salted dependency fingerprints, excluding derived process bytes.
An unchanged dependency resolves the template without loading the dotenv parser.
Changed, newly set or removed dependencies reparse only the original affected
expressions and their file-owned dependencies, retaining unrelated cached values.
Quoting, escaping, defaults and source ordering remain Dotenv behavior.
Deployment dumps also retain safe values and non-secret expressions;
process-derived result bytes are excluded from their persisted value map.
Command results and their dependent values are excluded and evaluated live on
each request, while unrelated values remain cached. Original expressions can
contain file-owned secrets, just like ordinary dump values.
Dynamic environment selectors decline persistence because changing the selected
environment changes the entire source chain and its persistent metadata.

When upgrading, flush existing runtime caches and rebuild deployment dumps: older
artifacts can already contain process values and are not rewritten implicitly.

Both files may contain file-owned secrets. Keep them outside web access and version control.
They default to mode `0600`; provision ownership so the deployed PHP identity can
read them. See [Deployment](deployment.md) for release and rollback steps.

## Production permissions and automatic cache invalidation

Native `cache-env` defaults to `"auto"`. Eligible requests use a cache when one
exists and create it only in a writable environment directory. An unwritable
parent is remembered for the process, and no shutdown write is registered for
that parent. Native `true` explicitly requires a writable cache directory when a new
cache is needed; otherwise bootstrap fails with a value-free error. Existing caches
and deployment dumps remain usable in read-only deployments. Legacy boolean
profiles retain best-effort shutdown writes. `false` disables the runtime cache;
deployment dumps still apply.

Native runtime caches track size and modification time for every attempted file
in the selected chain, including missing overrides. Creating, deleting or changing
one of those files invalidates the cache on the next request. Referenced external
variables are checked as well, including previously absent ones; only their affected
expressions are refreshed. Changes that preserve
both size and modification time require `flush-env-cache`. Deployment dumps remain
fixed until explicitly rebuilt; compatibility profiles retain their existing cache
behavior.

`generated-file-mode` accepts exactly `"0600"` (default) or `"0640"`. The latter
allows a separately provisioned PHP group to read generated configuration, caches
and dumps. Runtime does not select the group or change ownership. Temporary files
are checked against the canonical target directory before any secret content is
written; PHP's system temporary-directory fallback is never used for content.

## File-backed secrets and required values

In native mode, `DB_PASSWORD_FILE=/run/secrets/database-password` supplies
`DB_PASSWORD` when no explicit base value exists. This works for arbitrary names;
an explicit base value, including an empty string, takes precedence. Secret files
must be readable regular files. One final LF is removed, and all other bytes are
preserved. Errors contain the variable name, never the path or secret value.

Each read reopens the secret file. Cache and dump payloads retain references,
never resolved secret values, so rotated files remain effective for new reads.
Already defined PHP constants remain fixed during the request. Compatibility
profiles do not implicitly resolve `_FILE` variables.

Set `required-env` to a name/type map, for example
`{"DB_NAME":"string","DB_USER":"string","SERVICE_ENABLED":"bool","WORKERS":"int"}`.
Validation and deployment dumps reject missing, empty or wrongly typed values.
Supported types are `string`, `int`, `bool` and finite `float`; valid zero and false
values pass. Diagnostics report names and expected types without revealing values.

## Composer-managed WordPress defaults

Native `composer-managed: "auto"` disables WordPress file modifications and
automatic updates in staging and production. Use `true` to apply this to every
environment or `false` to disable these defaults. The editable `COMPOSER_MANAGED`
configuration section defines `DISALLOW_FILE_MODS`, `AUTOMATIC_UPDATER_DISABLED`
and `WP_AUTO_UPDATE_CORE` only when absent. Explicit environment and PHP constants
win. Native production with an HTTPS `WP_HOME` also defaults `FORCE_SSL_ADMIN` to
true. Compatibility profiles default `composer-managed` to false.

`bundle-bootstrap: true` embeds Runtime's environment classes in its generated
bootstrap. Symfony Dotenv and Process remain lazy dependencies. The default is
false; this option does not change parsing, cache or application-autoload rules.

## Canonical URLs and TLS termination

Every profile requires an explicit absolute HTTP(S) `WP_HOME` in staging and
production, including raw names that normalize to those environments. Bootstrap
fails before WordPress loads when it is absent or invalid. Userinfo, query strings,
fragments and whitespace are rejected. Set the canonical URL in the environment
or trusted PHP bootstrap; it is never derived from request host fields there.
Local/development retains the server-name/port fallback with a bounded hostname
syntax and localhost fallback for malformed names. Explicit canonical URLs are
recommended there as well.

For TLS termination, set `WP_FORCE_SSL_FORWARDED_PROTO=true` together with
`SYMPRESS_RUNTIME_TRUSTED_PROXIES=192.0.2.10,2001:db8::/32` (use your real proxy
addresses). Runtime only accepts an exact case-insensitive `https` value when
`REMOTE_ADDR` matches a configured IP or CIDR of the same address family. Missing
trust, malformed addresses and comma-separated scheme chains fail closed. Runtime
never trusts X-Forwarded-For for the peer identity. Configure the ingress proxy to
replace the forwarded scheme header, and restrict direct origin access; a trusted
proxy that passes attacker-supplied headers unchanged breaks that contract.
