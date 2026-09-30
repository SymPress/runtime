# Adopting Runtime in an existing site

Start in a branch or an isolated copy of your site. Keep the working lockfile,
generated configuration and a database backup available until the new build has
passed your normal application checks.

## Preserve the site layout

Record the core directory, content directory, vendor directory and web document
root. Match `wordpress-install-dir`, `wordpress-content-dir` and Composer's
`installer-paths` to that layout. Preserve `WP_HOME`, `WP_SITEURL` and working
web-server routes. Adding Runtime does not require a URL change.

Install the package without running setup while preparing the configuration:

```sh
composer require sympress/runtime:0.2.0 --no-plugins --no-scripts
```

If using the Git source directly, add its public HTTPS repository to Composer's
configuration. See [Getting started](getting-started.md) for an example manifest.
Once reviewed, allow the Runtime Composer plugin explicitly.

## Choose what Runtime will own

Review the generated front controller, configuration, MU loader and WP-CLI files.
Set `prevent-overwrite` for files your project must continue to own. Unmarked
configuration is not a reason to use `--force` blindly: compare it first and move
custom boot code into an early hook, a managed section or a custom template.

### Preserve early Composer hooks

**Native mode leaves `wp-config-autoload` disabled by default.** Enable it when
your current Composer `autoload.files` registers early WordPress hooks, or
environment PHP and `early-hook-file` need vendor classes:

```json
{"wp-config-autoload": true}
```

It loads Composer after the WordPress hook API and before those early files.
A later MU/kernel boot does not replace this ordering. Legacy profiles default to
true and migration records that inherited setting. Inspect any preserved
`AUTOLOAD` section after regeneration; existing custom PHP is not silently replaced.
The environment parser itself does not require the project autoloader.

Existing projects that use older configuration names or PHP interfaces should
follow [Compatibility](compatibility.md) before choosing the native profile.
The migration command can translate configuration and report PHP changes without
executing the inspected files:

```sh
vendor/bin/runtime migrate --dry-run --json
```

It never changes your Composer manifest or lockfile for you. Review the reported
profile, paths and PHP findings before writing migrated configuration.

## Validate the complete site

```sh
vendor/bin/runtime validate
vendor/bin/runtime --list-steps
vendor/bin/runtime --no-interaction
vendor/bin/runtime doctor --json
```

Check the homepage, login, admin dashboard, scheduled work and project-specific
commands. If your application uses the kernel, verify it boots once. A successful
static migration report does not exercise those application paths.

When changing environment or compatibility settings, flush the runtime cache and
rebuild any deployment dump. Keep the previous generated configuration and parser
payload together for rollback. See [Deployment](deployment.md).
