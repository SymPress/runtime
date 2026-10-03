# Deployment and payload retention

## Build and verify a release

Use an isolated release directory and the reviewed `composer.lock`. Provide
environment credentials through your deployment's private secret mechanism.

```sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
vendor/bin/runtime validate
vendor/bin/runtime --no-interaction
vendor/bin/runtime dump-env production
vendor/bin/runtime doctor --json
```

Composer already runs setup when plugins are enabled; the explicit invocation
checks repeatability. If the build has no database access, disable `db-check` for
that build and perform the database/application check in the target environment.
An unknown doctor result is not proof that the database works.

Verify the homepage, login, admin and required application commands as the deployed
PHP identity before switching traffic. A successful package QA suite does not
replace those site checks. Pin a reviewed tagged Runtime release and keep its exact resolved source reference
in the lockfile.

Provide a randomly generated `APP_SECRET` of at least 32 bytes before production
validation. Doctor reports a missing or short value without printing it. Older
projects should provision a new strong secret once through their private environment
or secret store, then rebuild the dump and kernel cache. Do not regenerate an existing
good secret on each deployment: rotation deliberately invalidates signed cache data
and can affect Symfony features using the application secret.

Set `SYMPRESS_PROJECT_DIR` to a persistent project identity, such as the deployment
base directory, before the kernel and object-cache drop-in boot. Its literal value
seeds WordPress cache namespaces/signing and Symfony cache pool prefixes; keep it
identical across release directories and different between projects. Actual package,
configuration and autoloader discovery continues from the active release. Changing
the identity or secret causes a normal cache refill. Provision a durable private
`APP_CACHE_DIR` accessible to the same CLI and PHP-FPM identity and warm it before
traffic; explicit unsafe cache permissions fail with an actionable diagnostic.

After updating interpolation persistence, regenerate ordinary environment caches
and deployment dumps to remove fingerprints stored by older Runtime versions.
New artifacts contain no verifier derived from a process secret. Compiled variable
templates resolve against current process values on every request without loading
Dotenv; command substitutions still require the live parser during application boot.

## Read-only environment inspection

Applications and security checks can request selected raw values without running
setup, loading WordPress or changing the caller's process/ENV/SERVER state:

```php
$snapshot = \SymPress\Runtime\EnvironmentInspection::inspect(
    $activeReleaseDirectory,
    ['APP_SECRET', 'DB_HOST'],
);
```

The result contains `environment`, the requested `values` (null when absent), and
`source` (`dump`, `cache` or `files`). It honors Runtime configuration and live
process precedence. Generated artifacts are decoded as literal data rather than
executed. Command substitutions are rejected. The optional `manifest` argument
means a Composer configuration manifest; `environmentFile` explicitly selects a
file chain and bypasses cached/dumped artifacts. Treat returned values as secrets.

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

For in-place deployments, publish the payload before changing the configuration reference and preserve the previous pair until rollback is no longer needed. Prefer release-directory switching when application/vendor changes must be coordinated. Package recovery persists a private write-ahead journal before mutations. The next setup invocation restores an interrupted transaction under the project locks before planning a fresh repair.

## Recovery when Composer plugins were disabled

Use normal Composer installation with the WordPress installers for routine builds.
The following is a recovery path for a build that deliberately disabled plugins.

```sh
composer install --no-dev --no-plugins --no-scripts --no-interaction
vendor/bin/runtime --no-interaction
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

## Recover from an interrupted package repair

Runtime writes `var/runtime/package-layout.pending.json` (0600) before moving
packages or rewriting Composer metadata. It contains original metadata, binary proxy
snapshots and package move identities. Preserve this file together with the package
and vendor trees. The next setup invocation acquires the maintenance/layout locks,
rolls back an unfinished transaction, then prepares the requested layout again.
A durable committed transaction only needs its retained-backup receipts completed.
Rollback itself is restartable after interruption.

The journal is flushed before mutation and directory entries are synchronized. Real
SIGKILL tests cover preparation, move intent, completed moves, metadata, binaries,
state publication, commit and interrupted rollback. Hardware/filesystem failures that
violate successful fsync semantics remain outside this guarantee. A corrupt journal,
changed package inode, unexpected directory or unsafe path fails closed: preserve the
whole release for inspection and rebuild from the reviewed lockfile if necessary.
Do not edit journal contents or mix metadata from different releases.

## Explicit retention

```sh
vendor/bin/runtime prune --keep=2 --dry-run
vendor/bin/runtime prune --keep=2
```

Prune preserves all payloads referenced by the generated root, core-parent and
configured custom `wp-config.php`, plus the newest N inactive payloads and newest N
owned package backups (each category independently). It verifies payload manifests,
all recorded hashes and the complete file inventory. Extra files, changed payloads,
unknown backups and trees containing symlinks are retained. Backup ownership requires
a Runtime receipt matching the directory inode; older unmarked backups are retained.
An active recovery journal blocks pruning entirely. Generation, recovery and pruning
share a nonblocking maintenance lock; concurrent operations fail without cleanup.

This is local release retention. Keep complete rollback releases separately and
account for in-flight requests before invoking prune. References from other release
directories cannot be inferred. Configurations whose payload reference cannot be
recognized are rejected rather than guessed. No cleanup runs automatically.
