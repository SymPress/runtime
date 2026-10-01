# Configuration

This page describes the Runtime 1.x configuration contract. Existing schema keys
and their profile-specific defaults remain stable in 1.x; new optional keys may
be added. See the [compatibility policy](compatibility-policy.md) and
[1.0 upgrade notes](releases/1.0.0.md). Projects moving from 0.1 must also review
the native-default changes in the [0.2 upgrade notes](releases/0.2.0.md).

Runtime settings describe setup behavior. WordPress environment values such as
database credentials and public URLs belong in [environment configuration](environment.md).

## Choose a configuration file

For most projects, use `sympress-runtime.json` in the project root:

```json
{
  "compatibility": false,
  "db-check": false,
  "wp-config-autoload": true,
  "skip-steps": ["wpcli"]
}
```

Alternatively, put an object in `extra.sympress-runtime` in `composer.json`,
or set that value to a JSON filename such as `"dev-ops/runtime.json"`.
Paths inside any settings file are still relative to the project root.

If both sources exist, the root `sympress-runtime.json` overrides the inline or
referenced object. The merge is shallow: a later array replaces the earlier array.
Older names and profile selection are documented in [Compatibility](compatibility.md).

## Keep Composer paths consistent

These are Composer extras, outside the Runtime settings object:

```json
{
  "extra": {
    "wordpress-install-dir": "public/wp",
    "wordpress-content-dir": "public/wp-content"
  }
}
```

Defaults are `wordpress` and `wp-content`. Core must be inside the project;
content must be below the root and within the core parent. Match the paths to
your Composer `installer-paths`. Vendor/bin paths come from Composer's actual
configuration. See the [complete manifest](../examples/site/composer.json).

## Validate before running setup

```sh
vendor/bin/runtime validate
```

Validation checks JSON/schema and semantic paths without executing PHP command
providers or the setup autoload file. Ordinary setup also validates before it
loads custom PHP or writes managed files. Schemas are available at
[schema/runtime.schema.json](../schema/runtime.schema.json) and
[schema/runtime-extra.schema.json](../schema/runtime-extra.schema.json).
Extension keys can be validated with `Config::appendValidator()`.

## Settings reference

The table lists every user-configurable option and its native default.
Compatibility profiles can use different defaults.

| Setting | Native default | Purpose |
| --- | --- | --- |
| `autoload` | `"sympress-runtime-autoload.php"` | PHP file loaded only when setup runs. |
| `cache-env` | `"auto"` | Automatic writable-only caching; explicit native `true` requires writability for new caches. `false` disables cache reads and writes. Native caches invalidate when source paths, sizes or modification times change. |
| `generated-file-mode` | `"0600"` | Private generated PHP/cache/dump permissions; `"0640"` permits a shared deployment/PHP group. No world access is accepted. |
| `composer-managed` | `"auto"` | Protect Composer-managed files from dashboard updates in staging/production; `true` enables it everywhere, `false` disables the section. |
| `required-env` | `{}` | Required nonempty environment names mapped to `string`, `int`, `bool` or `float`, checked by validate, doctor and dump-env. |
| `download-lock` | `true` | Pin URL-download SHA-256 hashes in `sympress-runtime.lock`; see [Downloads](downloads.md). |
| `wp-cli-version` | `null` | Exact WP-CLI download release (at least 2.5.0); an installed Composer bundle takes priority. |
| `wp-cli-sha256` | `null` | Independent SHA-256 pin for a WP-CLI PHAR; retained even during explicit lock updates. |
| `bundle-bootstrap` | `false` | Bundle owned environment classes into one bootstrap file; Symfony parser dependencies remain lazy. |
| `check-vcs-ignore` | `true` | Check relevant Git, Mercurial or SVN ignore rules; also accepts `ask`. |
| `command-steps` | `null` | Class list or name-to-class map for explicitly selected custom work. |
| `content-dev-dir` | `"content-dev"` | Directory containing project plugins/themes to publish. |
| `content-dev-op` | `"auto"` | Publication operation: `auto`, `copy`, `symlink` or `none`. |
| `create-vcs-ignore-file` | `true` | Create missing ignore files; also accepts `ask`. |
| `custom-steps` | `null` | Class list or name-to-class map included in full runs. |
| `steps` | `null` | Native DI step contributions. |
| `db-check` | `true` | Run connection/install preflight. Native `health` directs table inspection to explicit `doctor --database-health`; it does not scan tables on every install. |
| `dropins` | `null` | Map destination filenames to local files or download URLs. |
| `dropins-op` | `"auto"` | Local dropin operation: `auto`, `copy`, `symlink` or `none`. |
| `early-hook-file` | `""` | Trusted PHP file executed before the main WordPress bootstrap. |
| `env-bootstrap-dir` | `null` | Directory for environment-specific PHP; null resolves to the environment directory. |
| `env-dir` | `null` | Directory containing dotenv files; null resolves to the project root. |
| `env-example` | `true` | Generate an example; also accepts `ask`, a local template path or URL. |
| `env-file` | `".env"` | Base dotenv filename inside the environment directory. |
| `install-wp-cli` | `true` | Allow downloading WP-CLI when an executing command needs it. |
| `move-content` | `false` | Move bundled core content into the configured content directory; also accepts `ask`. |
| `prevent-overwrite` | `null` | Root-relative path/glob list, boolean or `ask` controlling overwrite protection. |
| `register-theme-folder` | `false` | Register bundled core themes; also accepts `ask`. |
| `require-wp` | `true` | Require installed WordPress during normal preflight. |
| `scripts` | `[]` | Pre/post step and whole-run callbacks. |
| `skip-db-check` | `false` | Deprecated inverse database switch; use `db-check` for new projects. |
| `skip-steps` | `null` | List of step names to omit from full runs. |
| `templates-dir` | `null` | Directory containing overrides for individual bundled templates. |
| `unknown-dropins` | `false` | Release compatibility profile only: allow unrecognized dropin names; also accepts `ask`. Native mode permits these names regardless of this setting. |
| `wp-cli-commands` | `[]` | Command strings starting with `wp `, or a JSON/PHP provider path. |
| `wp-cli-files` | `[]` | Eval-file paths/descriptors, including arguments and skip-wordpress. |
| `wp-config-autoload` | `false` | Load the configured project Composer autoloader before early PHP hooks. |
| `wp-version` | `null` | Override discovered core version. |
| `compatibility` | `true` | Accept the optional older configuration/API adapters. |
| `compatibility-profile` | `"native"` | Default behavior profile; older profiles are documented separately. |
| `env-local-overrides` | `true` | Read `.local` environment overrides. |
| `allow-insecure-downloads` | `false` | Permit HTTP as well as HTTPS for explicitly trusted sources. |
| `download-checksums` | `[]` | URL-to-SHA-256 map used before publishing downloads. |
| `download-max-bytes` | `16777216` | Positive maximum bytes per download; 16 MiB by default. |
| `require-download-checksums` | `false` | Reject downloads without a configured SHA-256 digest. |
| `kernel-boot` | `false` | Generate a kernel bootstrap only when no existing owner is found. |
| `kernel-build-id` | `null` | Explicit deployment ID; otherwise use the established environment/persisted identity. |

## Questions and overwrite protection

Options accepting `ask` use console confirmation. A noninteractive run uses the
question's default; it does not wait for input. Most confirmation defaults are yes;
unknown dropins default to no. After five invalid answers the configured default
is used.

`prevent-overwrite` matches normalized root-relative paths with `fnmatch`
without backslash escaping. Native unmarked files require confirmation or
`--force`. Directories and symlinks are not ordinary writable files. Content
publication may replace a symlink leaf after preparing its replacement, while
generated configuration still rejects symlink targets.

## Downloads

URL downloads use HTTPS by default, verify any configured SHA-256 digest and
enforce `download-max-bytes` while streaming. Size/checksum failures leave an
existing destination unchanged. Set `require-download-checksums` when every
download must be pinned. WP-CLI also checks its release SHA-512 checksum.

## Values supplied by the runner

Steps can read `composer-updated-packages`, `is-composer-install`,
`is-composer-update`, `is-runtime-command`, `is-runtime-selected-command`
and the derived `wp-config-php-path`. These are invocation context, not writable
project settings. Older aliases are listed in [Compatibility](compatibility.md).
