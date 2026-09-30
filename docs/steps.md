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

Release-3.0.1 retains its automatic order without the VCS check; that step is still available explicitly. Upstream-dev places `wpcliconfig` before `vcsignorecheck`. Native explicit selection takes precedence over `skip-steps`/`--skip-custom`; legacy profiles retain their upstream selection rules. Do not switch to native just to rename the package during migration.

`kernel-cache` is command-only and therefore does not automatically flush caches after Composer update. Select it when the deployment needs explicit cache invalidation; optional `--generate-build-id` writes a new stable deployment ID. `kernel-boot` reconciles boot ownership when configured. See [kernel integration](kernel-integration.md).

Custom steps implement the public step contracts and can use dependency injection, priorities, optional/blocking/file-creation behavior and lifecycle callbacks. `command-steps` contributes explicit-only work. Extension packages use `sympress-runtime-extension`; compatibility also recognizes `wpstarter-extension`. The complete [service and extension guide](custom-steps.md) describes registration and the process-isolated callback context.

All publication paths preserve unrelated data and validate their boundaries. `--force` is an explicit overwrite decision, not permission to traverse external links or replace arbitrary directories. URL dropins are verified and size-bounded before their destination changes. A failed step returns nonzero; blocking errors stop subsequent work.
