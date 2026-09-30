# Deployment and payload retention

## Build and verify a release

Use an isolated release directory and the reviewed `composer.lock`. Provide
environment credentials through your deployment's private secret mechanism.

```sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
vendor/bin/sympress-runtime validate
vendor/bin/sympress-runtime --no-interaction
vendor/bin/sympress-runtime dump-env production
vendor/bin/sympress-runtime doctor --json
```

Composer already runs setup when plugins are enabled; the explicit invocation
checks repeatability. If the build has no database access, disable `db-check` for
that build and perform the database/application check in the target environment.
An unknown doctor result is not proof that the database works.

Verify the homepage, login, admin and required application commands as the deployed
PHP identity before switching traffic. A successful package QA suite does not
replace those site checks. Runtime currently has no stable release tag; keep the
exact reviewed development revision in the lockfile.

## Required artifacts

`var/runtime/<fingerprint>/` is required application code. Generated `wp-config.php` references its `bootstrap.php` on every request. **Do not exclude all of `var/` or clear `var/runtime` as a cache cleanup.** Each immutable payload includes its manifest, scoped environment classes, Symfony Dotenv/Process source and license notices.

Deploy these artifacts together with the reviewed lockfile/application release:

| Artifact | Requirement |
| --- | --- |
| Generated `wp-config.php` and core-parent proxy | Match the selected profile, path layout and payload |
| `var/runtime/<fingerprint>/` | Include every payload referenced by an active or rollback configuration |
| Generated `index.php`, MU loader and optional kernel bootstrap | Keep consistent with installed core/content packages |
| `.env.dump.php` when using build dumps | Private per-environment input; PHP-readable; contains secrets |
| `.env`/environment PHP/early hooks when used | Supply securely, outside public access; do not publish secrets in artifacts/logs |
| WordPress core, installed plugin/theme/MU packages and application vendor | Required by application features; early autoload explicitly requires vendor |
| Kernel build ID and precompiled production cache | Preserve the selected environment/build relationship for read-only operation |

`var/runtime/package-layout.json` records package identities/paths for later offline repair; retain it when the deployment will rerun setup. `var/runtime/package-backups/` contains preserved package trees, not automatically disposable cache. Manage them under the same reviewed retention policy as deployment backups.

Build in the destination release layout where possible. Generated configuration uses relative paths, so moving the whole tree while preserving relationships is supported by those path expressions. Changing the relative core/content/vendor/environment locations requires regeneration. Dumps can contain absolute application values; compiled kernel caches, Composer path-repository links and application artifacts can also depend on the build root. Rebuild those for the destination, or explicitly verify their portability before switching traffic. Do not assume a relocated directory is a validated deployment.

Generate the environment dump and warm the application's production kernel cache before making the release read-only. Default private-file mode is 0600: provision the PHP runtime identity/ownership so it can read required configuration and secrets without making them public. Verify a real WordPress request and kernel boot under that identity with writes disabled.

Publish complete releases atomically and retain the previous configuration, its referenced payloads and matching application packages for rollback. Payload cleanup is deliberately not automatic. Before manually removing an old fingerprint, inventory references from every active and retained release, account for workers/in-flight requests and rollback windows, then remove only explicitly unreferenced directories. Do not infer safety from file age or the newest fingerprint alone; never traverse symlinks during cleanup. Keeping an old payload is preferable to breaking an active or rollback release.

For in-place deployments, publish the payload before changing the configuration reference and preserve the previous pair until rollback is no longer needed. Prefer release-directory switching when application/vendor changes must be coordinated. The existing exception rollback for package recovery is not a crash-recovery journal; retain backups and inspect an interrupted recovery before rerunning it.

## Build with Composer plugins disabled

```sh
composer install --no-dev --no-plugins --no-scripts --no-interaction
vendor/bin/sympress-runtime --no-interaction
```

The standalone runner can restore standard WordPress core/plugin/theme/MU-package
placement and Composer autoload metadata before loading project code. It does not
resolve dependencies, download missing packages or replay Composer scripts.
Run any required application build scripts explicitly.

Recovery supports distinct package directories inside the project. Root-directory
installs, overlapping destinations and arbitrary third-party installer semantics
require the regular Composer installers. Existing correct layouts continue to work;
unknown conflicts fail without deleting user content.

## Update and roll back

Update the dependency in a development branch, review its changelog and lockfile,
regenerate the configuration, then repeat the build and application checks above.
Switch back to the complete prior release if verification fails. Do not pair an old
configuration with a new payload or delete retained payloads before the rollback
window closes.
