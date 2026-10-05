# Kernel operations

The package works without sympress/kernel. Runtime 1.2.3 requires Kernel 1.1.5 or newer when the optional kernel is installed. When the kernel is installed, generated wp-config registers the optional RuntimeBundle through the kernel's public bundle filter. This makes `wp console doctor`, `wp console check`, `wp console validate` and `wp console dump-env production` available. If you override wp-config.php, register `SymPress\Runtime\Bridge\Kernel\RuntimeBundle::class => ['all' => true]` in `config/bundles.php` or preserve the generated filter.

Standalone commands:

```sh
vendor/bin/runtime doctor --json
vendor/bin/runtime check
vendor/bin/runtime validate
vendor/bin/runtime dump-env production
vendor/bin/runtime kernel-cache
vendor/bin/runtime kernel-cache --generate-build-id
```

Composer exposes `sympress-runtime:doctor`, `sympress-runtime:check`, `sympress-runtime:validate`, `sympress-runtime:dump-env` and the equivalent step flags on `composer sympress-runtime`. `--generate-build-id` requires an explicit kernel-cache selection.

Doctor exits with 0 when applicable checks pass, 1 on a failure, or 2 when a check remains unknown. `--json` includes each check's ID, status and redacted detail. Database-disabled status is unknown; it is never reported as a successful connection. A missing optional kernel is not-applicable. No setup autoload file or custom step executes during diagnosis.

`kernel-cache` preserves other environments and asset directories. It honors APP_CACHE_DIR and APP_BUILD_DIR and clears discovery metadata in the selected cache, including Kernel 1.1.5's private fallback. The selected discovery path is `discovery-packages.json`; read-only cache selection probes `meta.json` without reading or executing metadata. Safe stale default JSON and legacy PHP discovery files are also retired after an override. The unsafe old generation that triggered fallback stays untouched. Align explicit SiteKernel environment overrides before running maintenance.

Explicit cache/build roots outside the project must already exist, belong to the
user running PHP and have mode 0700. Runtime rejects root or ancestor symlinks,
parent traversal, foreign directory owners and group/world writable directories
before deleting any selected target. External ancestors must belong to that user
or the system root user; shared writable ancestors require the sticky bit, as on
the system temporary directory. It removes only the selected environment's
`kernel` directories, preserving sibling caches and other environments. These
rules apply only to kernel maintenance; generated project artifacts retain their
project boundary.

Doctor computes the same cache location without booting Kernel, creating a
fallback or changing permissions. A missing selected directory remains unknown;
an unsafe explicit root fails. `kernel.cache-migration` warns when Kernel would
select its private per-user fallback. Warmup must run as the PHP-FPM identity.
If that project-local fallback is public or unwritable, Kernel 1.1.5 selects
`sympress-kernel-{user}-{project-hash}` under the canonical system temporary
directory. Runtime validates that selected root and its protected ancestors;
shared writable temporary parents require the sticky bit. A missing root remains
unknown in Doctor and is created only by Kernel warmup. Existing symlinked,
foreign-owned or group/world writable roots fail. Cache maintenance stays inside
the selected root and preserves the old default generation. Configure a durable
APP_CACHE_DIR when deployment caches must survive temporary-directory cleanup.
Without ext-posix, process ownership is verified with a transient `tmpfile()` /
`fstat()` probe that is closed immediately; cache directories and configuration
remain unchanged during inspection.
The guard also treats the known WordPress core/content paths and conventional
`public` directory as public without booting WordPress. Supply `doctor --webroot`
for a different server document root; diagnostics cannot discover web server
configuration from PHP files.
Doctor observes the calling identity; a CLI check cannot prove the web process's
UID or its filesystem namespace. See the [1.2.3 upgrade notes](releases/1.2.3.md).

This is an explicit command-only step: Composer update does not automatically run it. A deployment requiring a deliberate clear or new build ID must invoke it and warm the selected environment before traffic switches.

To request a generated kernel MU plugin, set `kernel-boot: true`. A package that already owns boot can declare `extra.sympress-runtime.boots-kernel: true`; existing SymPress App boot entry files are also detected. The runtime never adds a second generated owner. Previously generated boot files are removed when another owner appears or the option is disabled; unmanaged files remain untouched.

Set `kernel-build-id` to an explicit deployment identifier, or generate one with the command above. Normal setup does not rotate IDs. The generated configuration defines SYMPRESS_KERNEL_BUILD_ID from the environment, configured fallback or persisted deployment ID, while preserving an existing constant. Persist `var/runtime/{environment}/kernel-build-id.json` with the deployment artifacts if you use command-generated IDs.

Build dumps and kernel caches are separate artifacts. Generate the environment dump and warm the kernel before making production files read-only. The runtime package's tests verify an actual compiled kernel reading a dump without writable project files; consumer deployment and admin smoke still need their own verification.

The generated environment-parser payload under `var/runtime/<fingerprint>/` is another required artifact, even when the kernel is absent. It must survive cache cleanup and accompany its generated configuration. See [deployment and rollback-safe payload retention](deployment.md).
