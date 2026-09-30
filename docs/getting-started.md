# Getting started

This guide creates a Composer-managed WordPress site with a `public/` document root.
You need PHP 8.5+, Composer 2.10.3+, a database and a web server. Runtime's repository
is public. Private project dependencies can use Composer authentication or an SSH
agent; keep credentials out of `composer.json`.

Commands below use `runtime`, the executable provided by the pinned 0.2.0 release.
Existing 0.1.0 projects should follow the [upgrade notes](releases/0.2.0.md).

## 1. Describe the project

From 1.0.0-beta.2, installing Runtime alone in an unconfigured project defers
automatic setup when no WordPress core is installed. Composer still installs the
package and `vendor/bin/runtime`; it reports that setup was deferred. Add the
project configuration and WordPress dependencies before running setup. Explicit
setup commands still require core unless you deliberately configure
`require-wp: false`. Existing sites and projects with native or legacy setup
configuration retain automatic setup and validation.

Start in an empty project directory with the complete manifest in
[examples/site/composer.json](../examples/site/composer.json). The example includes
Runtime, the WordPress core installer, the plugin/theme installer and WPackagist.
It leaves database checks and automatic WP-CLI downloads disabled during the first
build so dependency installation does not need a running database.

```sh
mkdir my-site
cd my-site
# Save examples/site/composer.json here as composer.json.
composer install
```

The example pins Runtime `0.2.0`. Commit `composer.lock` and deploy with `composer install`, not an unreviewed update.

The resulting layout is:

```text
my-site/
├── composer.json
├── composer.lock
├── .env                     # private, created in the next step
├── wp-config.php            # generated
├── wp-cli.yml               # generated
├── vendor/
├── var/runtime/             # required generated PHP payload
└── public/                  # web document root
    ├── index.php            # generated
    ├── wp/                  # Composer-managed WordPress core
    └── wp-content/
        ├── mu-plugins/
        ├── plugins/
        └── themes/
```

## 2. Configure the environment

Create `.env` outside `public/`. Replace these example values with your local credentials:

```dotenv
WP_ENVIRONMENT_TYPE=development
WP_HOME=https://my-site.test
WP_SITEURL=${WP_HOME}
DB_HOST=127.0.0.1
DB_NAME=my_site
DB_USER=my_site
DB_PASSWORD='replace-with-your-local-password'
DB_TABLE_PREFIX=wp_
```

Keep `.env`, local overrides, caches and dumps out of version control. Runtime generates
salts and preserves existing ones on subsequent setup runs.

**The physical core directory is not the public site URL.** This example keeps both
public URLs at the site root. Your web-server configuration must route WordPress
endpoints such as `/wp-login.php`, `/wp-admin/` and core assets to the installed core
directory. Runtime does not configure Nginx or Apache. Preserve existing working
routes; do not append `/wp` merely because the files live there.

## 3. Decide whether early code needs Composer

**Native mode defaults `wp-config-autoload` to `false`.** If Composer
`autoload.files` registers early hooks, or environment PHP / `early-hook-file`
uses vendor classes, enable it in your Runtime settings:

```json
{"wp-config-autoload": true}
```

The autoloader then runs after WordPress's hook API and before environment PHP and
early hooks. A later kernel/MU-plugin bootstrap cannot retroactively register hooks
for this earlier phase. This matters especially in projects without a kernel boot.
The environment parser itself works without Composer; legacy profiles default this
option to true. Review preserved `AUTOLOAD` sections when adopting existing files.

## 4. Verify setup and install WordPress

```sh
vendor/bin/runtime validate
vendor/bin/runtime --no-interaction
vendor/bin/runtime doctor
```

The example disables database preflight, so doctor can report an **unknown** database
status (exit 2). Once the database is available, enable `db-check` in the example's
Runtime settings and run doctor again.

Runtime prepares files; WordPress still needs its database installation. Use the
WordPress installer in your browser, or enable `install-wp-cli` and set
`wp-cli-commands` to `["wp cli version"]` to request the tool during setup:

```sh
vendor/bin/runtime --no-interaction wpcli
php wp-cli.phar core install --url=https://my-site.test --title='My site' --admin_user=site-admin --admin_email=admin@example.test --prompt=admin_password
```

Enter a unique password at the prompt. Verify the homepage, root login page and
admin dashboard before considering the site ready.

## 5. Add plugins and themes

The example's `installer-paths` places plugins and themes in the public content
directory. For example:

```sh
composer require wpackagist-plugin/classic-editor
composer require wpackagist-theme/twentytwentyfive
```

Installing a package does not activate it. Activate normal plugins/themes using
WordPress or WP-CLI. MU plugins are discovered during setup; see [Steps](steps.md).

Continue with [environment configuration](environment.md), [custom steps](custom-steps.md)
or the [production deployment guide](deployment.md).
