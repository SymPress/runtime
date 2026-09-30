# Deployment and payload retention

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
