# Kernel operations

The package works without sympress/kernel. When the kernel is installed, generated wp-config registers the optional RuntimeBundle through the kernel's public bundle filter. This makes `wp console doctor`, `wp console check`, `wp console validate` and `wp console dump-env production` available. If you override wp-config.php, register `SymPress\Runtime\Bridge\Kernel\RuntimeBundle::class => ['all' => true]` in `config/bundles.php` or preserve the generated filter.

Standalone commands:

```sh
vendor/bin/sympress-runtime doctor --json
vendor/bin/sympress-runtime check
vendor/bin/sympress-runtime validate
vendor/bin/sympress-runtime dump-env production
vendor/bin/sympress-runtime kernel-cache
vendor/bin/sympress-runtime kernel-cache --generate-build-id
```

Composer exposes `sympress-runtime:doctor`, `sympress-runtime:check`, `sympress-runtime:validate`, `sympress-runtime:dump-env` and the equivalent step flags on `composer sympress-runtime`. `--generate-build-id` requires an explicit kernel-cache selection.

Doctor exits with 0 when applicable checks pass, 1 on a failure, or 2 when a check remains unknown. `--json` includes each check's ID, status and redacted detail. Database-disabled status is unknown; it is never reported as a successful connection. A missing optional kernel is not-applicable. No setup autoload file or custom step executes during diagnosis.

`kernel-cache` preserves other environments and asset directories. It honors APP_CACHE_DIR and APP_BUILD_DIR and removes separately located discovery metadata. Unsafe or escaping symlink paths fail before any clearing. Align explicit SiteKernel environment overrides before running maintenance.

This is an explicit command-only step: Composer update does not automatically run it. A deployment requiring a deliberate clear or new build ID must invoke it and warm the selected environment before traffic switches.

To request a generated kernel MU plugin, set `kernel-boot: true`. A package that already owns boot can declare `extra.sympress-runtime.boots-kernel: true`; existing SymPress App boot entry files are also detected. The runtime never adds a second generated owner. Previously generated boot files are removed when another owner appears or the option is disabled; unmanaged files remain untouched.

Set `kernel-build-id` to an explicit deployment identifier, or generate one with the command above. Normal setup does not rotate IDs. The generated configuration defines SYMPRESS_KERNEL_BUILD_ID from the environment, configured fallback or persisted deployment ID, while preserving an existing constant. Persist `var/runtime/{environment}/kernel-build-id.json` with the deployment artifacts if you use command-generated IDs.

Build dumps and kernel caches are separate artifacts. Generate the environment dump and warm the kernel before making production files read-only. The runtime package's tests verify an actual compiled kernel reading a dump without writable project files; consumer deployment and admin smoke still need their own verification.

The generated environment-parser payload under `var/runtime/<fingerprint>/` is another required artifact, even when the kernel is absent. It must survive cache cleanup and accompany its generated configuration. See [deployment and rollback-safe payload retention](deployment.md).
