# Settings cheat sheet

Put settings in `extra.sympress-runtime` (an object or a root-relative JSON path) or `sympress-runtime.json`. Root configuration wins within the native family; native sources win over legacy sources. Arrays replace rather than merge recursively. [Configuration](configuration.md) contains the complete default table and internal fields; [CLI](cli.md) lists every command and flag.

| Task | Settings | Behavior |
| --- | --- | --- |
| Preserve an existing project's behavior | `compatibility-profile` | Keep its selected profile during adoption; see [Compatibility](compatibility.md) |
| Finish legacy migration | `compatibility: false` | Rejects legacy configuration/API usage; does not itself select the native profile |
| Early Composer classes/hooks | `wp-config-autoload: true` | Require project autoload before environment PHP and early hooks; legacy default is true |
| Select environment files | `env-dir`, `env-file`, `env-bootstrap-dir`, `early-hook-file` | Project-relative paths; `.env` and project root are defaults |
| Local overrides | `env-local-overrides: true` | Native default; legacy defaults false |
| Runtime cache/example | `cache-env`, `env-example` | Both default true; a build dump takes precedence over runtime caching |
| Build without DB access | `db-check: false` | Disable DB preflight; deprecated `skip-db-check` is translated by migration |
| Bootstrap without installed core | `require-wp: false` | Changes preflight; steps that need real core still require their inputs |
| Protect artifacts | `prevent-overwrite: ["relative/path", "pattern/*"]` | Root-relative patterns; review `--force` and existing-file rules before use |
| Publish local content | `content-dev-dir`, `content-dev-op` | Default `content-dev`, operation `auto`; absent input is optional |
| Publish dropins | `dropins`, `dropins-op`, `unknown-dropins` | File, directory or URL sources; unknown dropins require explicit handling |
| Move core content | `move-content` | Default false; opt in deliberately |
| Retain bundled themes | `register-theme-folder` | Default false; adds the core themes directory |
| Verify Git/Hg/SVN ignores | `check-vcs-ignore`, `create-vcs-ignore-file` | Both default true; VCS availability is diagnosed |
| Control routine steps | `skip-steps`, `custom-steps`, `steps`, `command-steps` | Native explicit selection overrides configured skips; command-only steps need explicit selection |
| Lifecycle callbacks | `scripts`, `autoload`, `templates-dir` | Run-only PHP autoload, scripts and template overrides; [extension API](custom-steps.md) |
| Run WP-CLI | `install-wp-cli`, `wp-cli-files`, `wp-cli-commands` | Package preferred over verified PHAR; eval files precede commands |
| Pin/report core version | `wp-version` | Optional version override used by version discovery |
| Verify downloads | `download-checksums`, `require-download-checksums` | SHA-256 mapping by URL, optionally mandatory |
| Bound downloads | `download-max-bytes` | Positive integer bytes; default 16777216 (16 MiB) for URL service downloads |
| Permit insecure transport | `allow-insecure-downloads` | Default false; opt in only for a deliberately trusted source |
| Kernel ownership/build | `kernel-boot`, `kernel-build-id` | Boot ownership opt-in; stable build identity, not regenerated on every install |

The top-level Composer extras `wordpress-install-dir` and `wordpress-content-dir` are outside the Runtime settings object. Configure `installer-paths` consistently with them; changing a Runtime path does not rewrite arbitrary third-party installer rules. See [steps](steps.md) for all twelve slugs and [environment](environment.md) for constant casts.
