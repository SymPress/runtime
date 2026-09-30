# Phase 3 implementation record

Work continues on `phase-3-default-steps`, stacked on Phase 2. Phase 3 acceptance remains open.

Implemented:

- `muloader`: typed package discovery plus local MU subdirectories, native array-based PHP template, quoted/comma-containing filenames, missing-file skipping and require_once behavior. The loader uses the generated PHP writer and preserves unchanged bytes/mtime. Compatible custom legacy templates still receive MU_PLUGINS_LIST; the native template uses MU_PLUGINS_ARRAY.
- `envexample`: default, custom local and URL sources; existing actual environment/disabled-option skip; optional ask; configured native/dev directory and legacy release root target. Downloads verify checksums before replacing output. Native examples carry a generation marker; the built-in example lists all 157 constants as commented type examples.
- `wpcliconfig`: YAML-quoted core path and PHP-quoted WP_CONFIG_PATH command, including apostrophes. The release profile retains path-only configuration. Overwrite protection and explicit force operate through the common runner.
- `wpcli`: lazy JSON/PHP providers with scoped service access, version check followed by eval-file and command queues, literal argument arrays, fixed core path and first-failure termination. Errors omit command arguments. A real WP-CLI 2.12.0 fixture executes eval-file and reads the WordPress 7.1.1 version.
- `dropins`: all twelve catalog entries from packages, local maps and development sources; remote sources with checksum validation; package/license retention; per-file overwrite protection; prepared sibling replacement; release copy-only and unknown-name policies.
- `movecontent`: explicit whole-tree core-content move, disabled with theme registration, complete destination preflight and physical-overlap protection.
- `publishcontentdev`: four content types plus dropins, copy/symlink/auto/none/ask, source preservation and partial results. Release whole-directory copy retains hidden entries; native/dev filter immediate dotfile/VCS entries.
- `vcsignorecheck`: real Git/Mercurial/Subversion status, worktree detection, missing-template creation and verification, tracked-file detection, cache/dump/environment variant paths and preservation of existing ignore files. CI requires the real VCS fixture tests to execute without skips.

Latest full composer qa checkpoint: 920 tests, 6,438 assertions, coding standards and PHPStan max pass, with isolated MariaDB, WordPress SHORTINIT, real WP-CLI and all three VCS executables enabled. All twelve default steps now have implementations. The complete differential run passes 194 cases, including Phase 3's eleven comparisons: copied/link content trees, whole-content moves, WP-CLI argv, package source/license retention and the dev marker-only VCS false positive.

Remaining acceptance: complete generated template snapshots and publication failure differential cases, Windows execution, fresh consumer smoke and phase delivery. The matrix remains partial; an implemented step is not blanket proof of every row. Remote Phase-1/2 checks are blocked solely at dependency resolution by the unmerged QA PHPUnit 13 companion.
