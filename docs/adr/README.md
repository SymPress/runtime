# Architecture decisions

- [0021: Native MU-plugin loading](0021-native-mu-plugin-loading.md)
- [0022: Tagged releases and Composer recovery compatibility](0022-release-and-composer-recovery-policy.md)
- [0023: Private environment writes and production payloads](0023-env-production.md)
- [0024: Crash recovery journal and owned artifact pruning](0024-layout-journal.md)
- [0025: Read-only operations and native deployment defaults](0025-production-operations.md)
- [0026: Console command discovery](0026-console-command-discovery.md)

The project owner accepted these ADRs with explicit Phase 0 approval on 2026-09-29. The package name is `sympress/runtime`.

1. [Scope, versions and consumer priorities](0001-scope-and-consumers.md)
2. [Names and public boundaries](0002-names.md)
3. [Environment and kernel agreement](0003-environment-and-kernel.md)
4. [Composer process architecture](0004-composer-isolation.md)
5. [Compatibility and intentional differences](0005-compatibility-and-differences.md)
6. [Independent runtime bootstrap and deterministic generation](0006-runtime-generation.md)

Source identifiers `R` and `D` refer to pinned upstream commits in [source evidence](https://github.com/SymPress/runtime/blob/main/docs/maintainers/source-evidence.md). Class names in the inventory are implementation targets; consult the implementation record for currently available APIs.

Implementation decisions after Phase 0:

7. [Filesystem and discovery safety](0007-filesystem-and-discovery-safety.md)
8. [Environment and database service boundaries](0008-environment-and-database-boundaries.md)
9. [Independent payload publication and path guards](0009-independent-payload-and-path-guards.md)
10. [Managed configuration regeneration and build dumps](0010-managed-config-regeneration-and-dumps.md)
11. [Pinned oracle default environment directory defect](0011-oracle-default-env-directory.md)
12. [WP-CLI arguments and diagnostics](0012-wp-cli-argument-and-diagnostic-boundaries.md)
13. [Content publication and VCS verification](0013-content-publication-and-vcs-verification.md)
14. [Extension lifecycle and legacy API](0014-extension-lifecycle-and-legacy-api.md)
15. [Kernel operations and diagnostics](0015-kernel-operations-and-diagnostics.md)
16. [Migration and compatibility diagnostics](0016-migration-and-compatibility-diagnostics.md)
17. [Consumer CLI isolation and link replacement](0017-consumer-cli-isolation-and-link-replacement.md)
18. [Relative environment example directories](0018-relative-environment-example-directory.md)
19. [Standalone WordPress package layouts](0019-standalone-wordpress-package-layout.md)
20. [Early project autoload and bounded service downloads](0020-early-autoload-and-download-limits.md)
