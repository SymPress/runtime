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
composer require sympress/runtime:dev-main --no-plugins --no-scripts
```

The private repository must already be present in Composer's repository
configuration. See [Getting started](getting-started.md) for an example manifest.
Once reviewed, allow the Runtime Composer plugin explicitly.

## Choose what Runtime will own

Review the generated front controller, configuration, MU loader and WP-CLI files.
Set `prevent-overwrite` for files your project must continue to own. Unmarked
configuration is not a reason to use `--force` blindly: compare it first and move
custom boot code into an early hook, a managed section or a custom template.

Enable `wp-config-autoload` if early application code needs Composer classes.
The parser itself does not require the project autoloader.

Existing projects that use older configuration names or PHP interfaces should
follow [Compatibility](compatibility.md) before choosing the native profile.
The migration command can translate configuration and report PHP changes without
executing the inspected files:

```sh
vendor/bin/sympress-runtime migrate --dry-run --json
```

It never changes your Composer manifest or lockfile for you. Review the reported
profile, paths and PHP findings before writing migrated configuration.

## Validate the complete site

```sh
vendor/bin/sympress-runtime validate
vendor/bin/sympress-runtime --list-steps
vendor/bin/sympress-runtime --no-interaction
vendor/bin/sympress-runtime doctor --json
```

Check the homepage, login, admin dashboard, scheduled work and project-specific
commands. If your application uses the kernel, verify it boots once. A successful
static migration report does not exercise those application paths.

When changing environment or compatibility settings, flush the runtime cache and
rebuild any deployment dump. Keep the previous generated configuration and parser
payload together for rollback. See [Deployment](deployment.md).
