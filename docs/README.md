# Runtime documentation

Runtime connects Composer's package layout to WordPress's bootstrap. Composer
installs the packages; Runtime creates the files that let WordPress use them.
At request time, generated configuration loads environment values and starts WordPress.

## The two phases

**Setup** runs after Composer install/update or when you invoke the standalone
binary. It validates configuration, generates files, discovers MU plugins and runs
the steps you selected. Project PHP runs in a separate process from Composer.

**Requests** use the generated `wp-config.php` and its parser payload. They do not
rerun setup. The parser reads environment variables or a prepared dump and defines
WordPress constants. Your WordPress plugins and application then start normally.

## A practical reading order

1. [Getting started](getting-started.md): install the package and boot a site.
2. [Configuration](configuration.md): decide where settings and files belong.
3. [Environment](environment.md): configure development and production.
4. [Steps](steps.md) and [CLI](cli.md): control setup.
5. [Custom steps](custom-steps.md) and [WP-CLI](wp-cli.md): automate project work.
6. [Deployment](deployment.md): build, verify, publish and roll back a release.

Use the [settings cheat sheet](settings-cheat-sheet.md), [constant catalog](constants.md)
and [troubleshooting guide](troubleshooting.md) as references. Existing projects can
follow [the adoption guide](migration.md); optional older interfaces are covered in
[Compatibility](compatibility.md).

The examples describe the native profile. Changing a compatibility profile can
change defaults and ordering; keep an existing project's profile during migration.
