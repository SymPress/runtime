# ADR 0010: Managed configuration regeneration and build dumps

Status: implementation decision, 2026-09-29; full differential acceptance pending.

## Generated configuration

Native configuration lives at the project root and a webroot proxy points to it when needed. Release compatibility keeps the webroot location. Every target is checked before publication; `--force` can override file protection but cannot replace directories or symlinks. PHP syntax is checked for both main and proxy content before either is published. Files are replaced atomically with mode 0600, and identical content is not rewritten. Deployment must run PHP with read access to these private files.

The main file retains all 19 section names from the source union. A comment stores hashes of the generated section bodies. On regeneration, a section whose current body differs from the previous generated hash is retained and reported. Untouched sections receive template/settings updates. This protects edits through the section API; arbitrary edits outside sections are not a supported persistence mechanism. A custom template receives a diagnostic that it owns the bootstrap guarantees it replaces, as required by ADR 0006.

The KEYS section is treated specially. Literal fallback salts are read with PHP tokens without executing the existing configuration, including quoted/escaped bytes and literal concatenation. Existing section content is retained and only missing key definitions are appended. Dynamic definitions inside a retained KEYS section remain executable and are preserved. A dynamic salt outside a preservable section causes a diagnostic before replacement, because evaluating user PHP merely to discover a secret would be unsafe; migration must retain that code explicitly. `--force` does not rotate existing salts.

Generated native content paths remain explicit absolute strings even before the directory exists, rather than becoming false from `realpath()`. Relative URL path segments are percent-encoded; filesystem literals preserve their original bytes. Normal upstream directory names produce identical URLs, while quote/space-containing names remain valid. These edge differences require exact differential fixtures.

The native getter and compatible getter/filter use one initialized reader. Legacy runtime calls issue a diagnostic once per alias through WordPress's deprecation API when available, otherwise a PHP deprecation. Existing WordPress constants are retained, environment-specific PHP and early hooks keep the original order, and Site Health exposes only a fixed path/environment/cache/flag allowlist. A proxy WP-CLI invocation returns before loading WordPress settings.

## Build-time dump

`dump-env <raw-environment>` and `composer sympress-runtime:dump-env <raw-environment>` resolve an explicit file chain without running custom steps or run-only autoload code. A conflicting actual process environment fails rather than producing a misleading dump. The explicit selector is included even when no dotenv file exists. The output is `.env.dump.php` in the configured environment directory, distinct from `.env.cached.php`.

The generated runtime prefers a valid build dump over the ordinary cache, even when runtime caching is disabled. It verifies format/profile/environment and retains actual process precedence. Dump mode never schedules runtime cache writes. The dump is published atomically with mode 0600 and may be made read-only for deployment. Flush removes only the ordinary cache. Unmarked existing dump files are preserved.

Evidence includes separate generator/request processes, private/read-only file modes, managed section edits for every named section, real environment overrides, local cache policy/filter behavior, PHP template syntax, target protection and a real WordPress 7.1.1 SHORTINIT database smoke. Full admin/kernel boot and compatibility differential checks remain distinct acceptance gates.
