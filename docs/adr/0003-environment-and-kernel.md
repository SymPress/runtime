# ADR 0003: Environment identity and kernel cache paths

Status: proposed.

## Verified kernel behavior

At kernel commit `c24273066431b3c33b4dd372022ad42c100aafd2`:

- `src/Kernel/SiteKernel.php:7` inherits AbstractKernel. `AbstractKernel.php:50-58` accepts an explicit environment, otherwise uses `EnvConfig::env()`.
- `src/EnvConfig.php:132` starts with `wp_get_environment_type()` when available, then constant/env `WP_ENVIRONMENT_TYPE`, `WP_ENV`, `WORDPRESS_ENV`, VIP names and hosting/debug fallbacks. `normalizeEnv()` maps a smaller alias set than WP Starter and defaults unknown explicit strings to production; it has no WP Starter contains-match.
- `src/Kernel/KernelConfigurationResolver.php:19-27` uses `APP_CACHE_DIR/{environment}/kernel` when configured, otherwise `{project}/var/cache/{environment}/kernel`. `environmentDirectory()` resolves relative overrides against the project and appends the environment even for absolute overrides.
- The same resolver handles APP_BUILD_DIR, APP_SHARE_DIR, APP_LOG_DIR and sorted `config/packages/*`, `config/packages/{env}/*`, `services`, `services_{env}`, `wordpress`, `wordpress_{env}` files in PHP/YAML/YML/INI. Configuration order is library, bundle, site.
- `src/Kernel/ContainerResourceFingerprinter.php:248-250` reads SYMPRESS_KERNEL_BUILD_ID from a constant, otherwise `getenv()`. Merely putting this value in superglobals is insufficient for this call site.
- `src/Discovery/KernelPackageManifestCache.php:102` independently uses `var/cache/{env}/kernel/discovery-packages.php`; account for this when APP_CACHE_DIR is overridden.
- `src/DependencyInjection/EnvironmentParameterLoader.php` deliberately filters values exposed as container parameters. Runtime must populate the environment for `%env()%` without defeating that parameter allowlist or leaking secrets.

## Decision

Define canonical WP_ENVIRONMENT_TYPE before WordPress and kernel boot. Preserve raw legacy environment in WP_ENV for environment-specific files, matching WP Starter's ability to distinguish `preprod-eu-1` from `staging`. Native precedence is canonical WP_ENVIRONMENT_TYPE, then WP_ENV, then WORDPRESS_ENV. Alias mapping includes the upstream union and its boundary-sensitive contains matching; it is not arbitrary substring matching (`my_dev_one` matches, `my_devone` does not).

The kernel sees the mapped canonical value through WordPress. Runner paths use that same mapped value. An explicitly constructed SiteKernel with a different environment cannot be inferred: diagnose the override and require explicit matching runtime input rather than flushing another environment accidentally. Preserve the existing kernel filter API; runtime does not redefine it.

Native dotenv order from lowest to highest priority: `.env` (or env-file), `.env.local`, `.env.{rawEnvironment}`, `.env.{rawEnvironment}.local`, real process environment. Adopt Symfony's test convention of excluding the generic `.env.local` for raw environment `test`; document and test it. Legacy profile keeps the original two-file chain until migration explicitly enables local overrides. Resolve interpolation using already established higher-priority real values. Environment selection is stable during one load; a later file cannot redirect the loader into a different environment chain.

Construct Symfony Dotenv with the chosen environment key. SYMPRESS_RUNTIME_ENV_LOADED or its compatible legacy name bypasses **all** dotenv parsing, including appended/local files. Native cache/dump restores values into both superglobals, while real environment values always win. Never trust HTTP_* request headers as deployment environment; retain the upstream exception to server mirroring for HTTP_* names. For kernel build IDs define the validated constant before boot or explicitly export it for the child process.

The `kernel-cache` step resolves the actual kernel cache/build configuration, accounts for discovery metadata, validates the selected environment and path, then deletes only the intended cache subtree without following external symlinks. Its default matches the requested var/cache path. Optional build-ID generation is explicit and persisted/reported deterministically, never changed on every no-op install.

The build-time dump and runtime cache are separate artifacts. Validate dump environment and format before reading. A production read-only deployment with a valid dump requires no runtime writes. Cache invalidation, process-env precedence and secret file permissions are acceptance requirements, not assumptions.
