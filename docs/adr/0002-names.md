# ADR 0002: Names and public boundaries

Status: proposed, except the owner-selected package name.

| Surface | Name | Reason |
| --- | --- | --- |
| Composer package | `sympress/runtime` | Owner decision. Composer type remains `composer-plugin`. |
| PHP namespace | `SymPress\\Runtime` | Matches package and organization conventions. |
| Root Composer configuration | `extra.sympress-runtime` | Avoids collisions with other packages using generic `runtime`. |
| Root configuration file | `sympress-runtime.json` | Discoverable next to composer.json. |
| Run-only autoload file | `sympress-runtime-autoload.php` | Loaded only by the runner. |
| Composer run command | `composer sympress-runtime [steps...]` | Positional names keep the upstream step-selection contract. |
| Standalone binary | `vendor/bin/sympress-runtime [steps...]` | Same selection parser and exit behavior. |
| Administrative commands | `sympress-runtime:validate`, `:doctor`, `:check`, `:dump-env`, `:migrate`, `:flush-env-cache` | Use colon commands at Composer boundary; standalone accepts the corresponding subcommands via a separate command parser before step selection. Reject a contributed step whose name collides with a reserved subcommand. |
| Kernel console commands | `wp console runtime:doctor`, `runtime:validate`, `runtime:dump-env` | Register through an optional bundle, keeping bootstrap independent of the kernel. |
| Runtime env prefix | `SYMPRESS_RUNTIME_` | `SYMPRESS_RUNTIME_ENV_LOADED`, `SYMPRESS_RUNTIME_ENV_TO_CONST`; keep WordPress/DB standard variable names. |
| WordPress hook prefix | `sympress.runtime.` | In particular `sympress.runtime.skip-cache-env($skip, $rawEnvironment)`. |
| Getter | `sympress_runtime_getenv()` | Legacy function/filter adapters available only with compatibility enabled. |
| Extension package type | `sympress-runtime-extension` | Automatic runs disabled for extension roots. |
| Extension autoload metadata | `extra.sympress-runtime-autoload` | PSR-4 and files; never added to production Composer autoload. |
| Step contribution metadata | `extra.sympress-runtime.steps` | Typed native service definitions and attributes, separately validated from root options. |
| Step attribute/tag | `#[AsRuntimeStep(name:, priority:)]`, `sympress.runtime.step` | Public runner DI registration, not kernel service registration. |
| Schemas | `schema/runtime-extra.schema.json`, `schema/runtime.schema.json` | Composer extra and root config variants, draft 2020-12 matching kernel schema style. |
| Generated MU loader | `sympress-runtime-mu-loader.php` | Migration must retire the old managed loader transactionally to avoid loading twice. |

Legacy `extra.wpstarter`, `wpstarter.json`, `wpstarter-autoload.php`, extension metadata/type, runtime names and step slugs remain recognized with deprecations while compatibility is on. Native settings win over legacy settings; within each family the root file wins over the inline object or referenced file. Mixing conflicting sources emits a precise provenance diagnostic. Unknown extension-owned settings remain available via Config with registered validators; strict schema validation must not erase this extension point.

The internal runner DI container and SiteKernel's application container are separate. The runtime package does not replace the kernel, scaffolding CLI, core installer or DDEV.
