# ADR 0019: WordPress package layouts when Composer plugins are disabled

Status: implemented; consumer replay and final acceptance tracked in `docs/acceptance.md`.

## Evidence and requirement

The owner reaffirmed that WPackagist and other Composer package sources must remain usable. A real isolated Composer installation of `wpackagist-plugin/classic-editor`, `wpackagist-theme/twentytwentyfive` and `johnpbloch/wordpress` passes with custom installer paths and a subsequent unchanged Runtime run. Composer remains responsible for repositories, dependency resolution, downloads and package integrity.

Disabling all Composer plugins also disables the WordPress installers. In a fresh installation their packages use vendor defaults; in an existing project Composer can rewrite installed metadata and the autoloader to those defaults without relocating the previously installed files. Reading the rewritten metadata alone therefore cannot provide standalone equivalence.

## Bounded recovery

Preserve the regular Composer installer workflow. An offline preparation stage handles already downloaded WordPress packages before loading project autoload files. It honors the standard installer-path selectors (package name, vendor and package type), path variables, `installer-disable` and package installer-name metadata. Core uses the declared WordPress path. Packages from WPackagist, private repositories and local path repositories are handled by package type, not by a repository-specific allow-list. The matching standard installer must be present in installed metadata; Runtime does not infer the semantics of arbitrary third-party installer plugins.

Persist a deterministic private layout record after a normal setup. A later standalone run distinguishes metadata-only drift from a changed package version. Correct package placement and regenerate installed metadata/autoload files using Composer's library with all plugins and scripts disabled. Composer is now a production PHP dependency; the standalone executable does not call an external Composer binary. A separate preparation process loads class maps and only Composer's transitive function dependencies, leaving project and WordPress autoload files for the main process. Owned binary proxies are regenerated too, without changing the permissions of path-repository source files.

Never invoke a dependency update or download from this stage. Preserve unrelated destinations, roll back package moves and generated metadata on exceptions, and retain replaced package trees under `var/runtime/package-backups`. A per-project lock rejects concurrent recovery. Read-only/listing commands skip preparation. Offline recovery targets must be distinct, non-overlapping directories inside the project, with no escaping parent links. Unknown existing destinations fail before moving files. Correctly installed root-directory and overlapping package layouts remain usable and their Composer metadata is left unchanged. Relocating or repairing the metadata of those layouts still requires the regular installers; no unverified directory merge is attempted. Abrupt process termination can leave retained trees requiring manual recovery; the exception rollback is not a crash-recovery journal.

This narrows ADR 0002's installer exclusion: Runtime does not replace dependency resolution or core downloading. It supplies the missing offline placement/metadata preparation needed by the work order's explicit `--no-plugins` mode. The generated WordPress configuration remains independent of Composer at request time.

## Evidence and remaining acceptance

- `PackageLayoutTest` executes fresh placement for all five package types, custom vendor directories, dev/no-dev metadata, authoritative autoload, metadata drift with stale vendor copies, updates with retained backups, path changes, relative repository links, escaping-parent rejection, unknown destination conflicts, exception rollback and executable Composer proxies.
- `PackageDestinationTest` asserts the ordered standard selectors, variables, defaults and disabled installers.
- `tools/wpackagist-smoke.py` passes both normal and no-plugins installation with real core, plugin and theme packages. It asserts project autoload happens after placement, each standalone invocation loads project files once, Composer scripts are not replayed, and repeated setup preserves checked artifacts. A separate CI job repeats this network integration.
- Consumer replay, complete package QA and differential results are recorded in the acceptance document after each final run. The remote QA dependency gate remains separate.
