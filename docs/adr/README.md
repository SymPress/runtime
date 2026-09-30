# Phase 0 decisions

The project owner accepted these ADRs with explicit Phase 0 approval on 2026-09-29. The package name is `sympress/runtime`.

1. [Scope, versions and consumer priorities](0001-scope-and-consumers.md)
2. [Names and public boundaries](0002-names.md)
3. [Environment and kernel agreement](0003-environment-and-kernel.md)
4. [Composer process architecture](0004-composer-isolation.md)
5. [Compatibility and intentional differences](0005-compatibility-and-differences.md)
6. [Independent runtime bootstrap and deterministic generation](0006-runtime-generation.md)

Source identifiers `R` and `D` refer to pinned upstream commits in [source evidence](../source-evidence.md). Class names in the inventory are implementation targets; consult the implementation record for currently available APIs.

Implementation decisions after Phase 0:

7. [Filesystem and discovery safety](0007-filesystem-and-discovery-safety.md)
8. [Environment and database service boundaries](0008-environment-and-database-boundaries.md)
9. [Independent payload publication and path guards](0009-independent-payload-and-path-guards.md)
10. [Managed configuration regeneration and build dumps](0010-managed-config-regeneration-and-dumps.md)
11. [Pinned oracle default environment directory defect](0011-oracle-default-env-directory.md)
