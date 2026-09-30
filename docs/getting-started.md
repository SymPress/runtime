# Getting started

Use PHP 8.5, Composer 2 with plugin API 2.6 or newer, and access to the private SymPress package registry/repositories. Runtime uses Symfony 8.1. There is no stable Runtime release yet: install the reviewed development revision through your existing authenticated Composer repository configuration and commit the resulting lockfile.

For an existing WP Starter project, start with [migration](migration.md); keep its profile and generated files until the transition has been reviewed. For a new project, require `sympress/runtime` and your chosen WordPress core package. Plugins/themes continue to come from WPackagist, private repositories or Composer path repositories. Runtime does not resolve dependencies or download WordPress core.

Configure Composer's installer permissions and paths before installation. This is a minimal root manifest fragment; merge it into the existing project rather than replacing its requirements or scripts:

```json
{
  "config": {
    "allow-plugins": {
      "sympress/runtime": true,
      "composer/installers": true,
      "johnpbloch/wordpress-core-installer": true
    }
  },
  "extra": {
    "wordpress-install-dir": "public/wp",
    "wordpress-content-dir": "public/wp-content",
    "installer-paths": {
      "public/wp-content/plugins/{$name}/": ["type:wordpress-plugin"],
      "public/wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"],
      "public/wp-content/themes/{$name}/": ["type:wordpress-theme"]
    },
    "sympress-runtime": {"compatibility-profile": "native"}
  }
}
```

Create a private `.env` with your actual database credentials and site URL. Never commit secrets. For example, the nonsecret selectors are `WP_ENVIRONMENT_TYPE=development`, `DB_HOST=127.0.0.1` and `WP_HOME=https://example.test`; set `DB_NAME`, `DB_USER` and `DB_PASSWORD` for your database. Runtime creates configuration and content artifacts, but a WordPress database installation still needs your project's installation command or WP-CLI.

```sh
composer install
vendor/bin/sympress-runtime validate
vendor/bin/sympress-runtime --list-steps
vendor/bin/sympress-runtime -n
vendor/bin/sympress-runtime doctor --json
```

Composer install/update already invokes setup when the Runtime plugin is enabled. The explicit standalone invocation above is useful to verify repeatability. Read `doctor` results before deployment: exit 1 means failure; exit 2 means unresolved checks without a known failure.

Set the web document root to `public` for this layout. Commit the manifest, lockfile, application configuration and source; deploy generated configuration and its parser payload as described in [deployment](deployment.md). Native configuration does not load Composer during WordPress bootstrap unless `wp-config-autoload=true`; enable that option if early hooks or plugins need the project's Composer graph. A project with its own MU/kernel bootstrap can retain the native default.

Set `WP_SITEURL` to the public WordPress URL your web server actually serves. In the SymPress starter/demo routing, it is `${WP_HOME}`: the existing server rules map root login/admin endpoints to the physical core directory. Do not append `/wp` merely because Composer stores core in `public/wp`.

For a plugins-disabled build, run `composer install --no-plugins` followed by the standalone binary. Recovery covers the standard installers' distinct in-project directories. Root/nested layouts and arbitrary third-party installers retain the [documented boundaries](adr/0019-standalone-wordpress-package-layout.md).
