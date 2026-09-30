# Custom steps and runner services

Use `custom-steps` for automatic/full runs and `command-steps` for explicitly selected work. Both accept class-string lists or name-to-class maps. Load their classes through Composer autoloading or the run-only `sympress-runtime-autoload.php`. That file is never executed by static validation.

Implement `SymPress\Runtime\Step\StepInterface`: `name()`, `success()`, `error()`, `allowed(Config, Paths)` and `run(Config, Paths)`. Results are bit flags: ERROR=1, SUCCESS=2, NONE=4. Constructor injection supports the runner services. A step's reported name must match its registration.

Native steps can use `#[AsRuntimeStep(name: 'example', priority: 20)]`. The run-only autoload file can return a callable receiving the runner's `ContainerBuilder` to register services. Enable autoconfiguration to discover step attributes, or tag a service with `sympress.runtime.step` and attributes `name`, optional integer `priority`, and optional `command-only`. Higher priorities run earlier; equal priorities retain registration order. Explicit CLI selection retains the requested order, with WP-CLI last.

Native packages contribute class-string lists/maps through `extra.sympress-runtime.steps`. Their classes must be autoloadable. Registration conflicts are diagnosed. The runner container is separate from the application kernel container.

Optional interfaces provide blocking behavior, file targets, confirmation, skipped-condition messages and postprocessing. Eligibility checks run in this order: `allowed`, overwrite protection, optional confirmation. Native explicit selection bypasses optional confirmation; the release compatibility profile retains it. Postprocessors run once after the selected work.

`Services` provides all 22 inventoried accessor names: `config`, `paths`, `io`, `composerIo`, `composerFilesystem`, `composerConfig`, `filesystem`, `urlDownloader`, `fileContentBuilder`, `overwriteHelper`, `salter`, `pharInstaller`, `packageFinder`, `wpConfigSectionEditor`, `muPluginsList`, `env`, `systemProcess`, `executableFinder`, `phpProcess`, `phpToolProcessFactory`, `wpCliProcess` and `dbChecker`. `runContext` additionally exposes the immutable execution context. `composerIo`, `composerFilesystem` and `composerConfig` expose native equivalents; they are not instances of Composer's concrete classes. Live Composer mutation cannot cross the process boundary. Full legacy PHP adapters remain on the phase checklist.

`env()` returns a shared lazy reader. Merely constructing `Services` does not parse dotenv, connect to a database or download WP-CLI. Database checks run on demand and are memoized. Use `dbChecker()->status()` to distinguish an unknown connection state from a confirmed missing database; the legacy boolean accessors return true only for a confirmed result. Supplied `WPDB_ENV_VALID`, `WPDB_EXISTS` and `WP_INSTALLED` must be a complete, consistent set of boolean values.

Use argv arrays with `SystemProcess` and `PhpProcess` for argument safety. The explicit string overload retains the trusted shell-command API for custom code. Never build a shell string from remote or request data. Capturing execution returns `[stdout, stderr, success, throwable]` and preserves both streams on failure.

The section editor preserves `NAME : { ... } #@@/NAME`, supports append/prepend/replace/delete and treats dollars and backslashes literally. Repeated append/prepend from the same call site is deduplicated. The generated config and its complete section catalog arrive in Phase 2.

Scripts/events, legacy aliases, extension-only autoload metadata and migration reporting remain in their scheduled phases. The existence of a native custom-step path does not establish full legacy PHP compatibility.
