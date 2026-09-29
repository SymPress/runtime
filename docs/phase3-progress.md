# Phase 3 implementation record

Work continues on `phase-3-default-steps`, stacked on Phase 2. Phase 3 acceptance remains open.

Implemented:

- `muloader`: typed package discovery plus local MU subdirectories, native array-based PHP template, quoted/comma-containing filenames, missing-file skipping and require_once behavior. The loader uses the generated PHP writer and preserves unchanged bytes/mtime. Compatible custom legacy templates still receive MU_PLUGINS_LIST; the native template uses MU_PLUGINS_ARRAY.
- `envexample`: default, custom local and URL sources; existing actual environment/disabled-option skip; optional ask; configured native/dev directory and legacy release root target. Downloads verify checksums before replacing output. Native examples carry a generation marker; the built-in example lists all 157 constants as commented type examples.
- `wpcliconfig`: YAML-quoted core path and PHP-quoted WP_CONFIG_PATH command, including apostrophes. The release profile retains path-only configuration. Overwrite protection and explicit force operate through the common runner.

Latest full composer qa checkpoint: 885 tests, 6,182 assertions, coding standards and PHPStan max pass, with isolated MariaDB and WordPress SHORTINIT enabled. These three steps have dedicated execution/file tests; their complete oracle file comparisons remain pending.

Remaining default steps: dropins, movecontent, publishcontentdev, vcsignorecheck and wpcli. Full providers/eval-file execution, actual pinned WP-CLI, complete file differential coverage and phase delivery remain open. Remote Phase-1/2 checks are blocked solely at dependency resolution by the unmerged QA PHPUnit 13 companion.
