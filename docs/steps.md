# Setup steps

Native full-run order is shown below. Each step can be selected by slug: `vendor/bin/sympress-runtime -n wpconfig index`. WP-CLI runs last even in an explicit selection. Configured skips and optional features govern whether a listed step performs work; listing a step does not imply its inputs exist.

| Slug | Work and principal settings |
| --- | --- |
| `checkpaths` | Validate installed core/autoload paths and prepare content directories |
| `wpconfig` | Generate configuration/proxy and scoped environment payload; preserve salts/sections |
| `index` | Generate the front controller for the configured core location |
| `flushenvcache` | Invalidate the runtime environment cache; retain deployment dumps |
| `muloader` | Generate the MU package loader; retire recognized stock legacy loaders safely |
| `envexample` | Generate the environment example when `env-example` is enabled |
| `dropins` | Publish configured files/directories/verified URLs using `dropins-op` |
| `movecontent` | Move core content when `move-content` enables it |
| `publishcontentdev` | Publish `content-dev-dir` through the chosen copy/move/symlink/auto operation |
| `vcsignorecheck` | Check/create relevant ignore rules using `check-vcs-ignore` and `create-vcs-ignore-file` |
| `wpcliconfig` | Generate WP-CLI path/bootstrap configuration |
| `wpcli` | Execute configured eval files and command providers after setup |

Native explicit selection takes precedence over `skip-steps`/`--skip-custom`. Existing compatibility profiles retain their ordering and selection rules; see [Compatibility](compatibility.md).

`kernel-cache` is command-only and therefore does not automatically flush caches after Composer update. Select it when the deployment needs explicit cache invalidation; optional `--generate-build-id` writes a new stable deployment ID. `kernel-boot` reconciles boot ownership when configured. See [kernel integration](kernel-integration.md).

Custom steps implement the public step contracts and can use dependency injection, priorities, optional/blocking/file-creation behavior and lifecycle callbacks. `command-steps` contributes explicit-only work. Extension packages use `sympress-runtime-extension`. The [service and extension guide](custom-steps.md) walks through a complete working step.

## MU plugins

The native loader discovers Composer MU packages and plugin entry points in direct
MU subdirectories. Multiple PHP files require plugin headers; a single entry in a
typed MU package can omit one. Configured dropins are excluded from fallback discovery.

Discovered entries are sorted by path before generation. Each loaded native entry
emits WordPress's `mu_plugin_loaded` action and appears as its own row in the admin
Must-Use list (WordPress 6.3+). A hidden Must-Use list stays hidden. Linked loader
files use `WPMU_PLUGIN_DIR` as their base. Older profiles keep their prior loader
behavior. Rerun `muloader` after adding or removing an entry point.

All publication paths preserve unrelated data and validate their boundaries. `--force` is an explicit overwrite decision, not permission to traverse external links or replace arbitrary directories. URL dropins are verified and size-bounded before their destination changes. A failed step returns nonzero; blocking errors stop subsequent work.
