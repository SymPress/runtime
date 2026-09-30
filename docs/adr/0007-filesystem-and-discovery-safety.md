# ADR 0007: Filesystem and discovery safety

Status: implementation decision, 2026-09-29; differential fixtures pending.

Phase 1 source review exposed edge cases in the helpers used by later publishing steps. These decisions extend the preservation requirements in ADR 0005 (D10/D20); they do not remove copy, move, symlink or MU discovery capabilities.

- Directory copy and move reject physical ancestor/descendant overlap, including paths through symlinked parents. The caller receives `false` before copying. A caller needing a rearrangement must use separate, nonoverlapping staging paths.
- Directory copies preserve source symlinks instead of traversing them. Existing target links are accepted only when the corresponding source is the same link; conflicting links or file/directory types cause failure before copying. This avoids following a target link into unrelated user data and handles source loops without recursion. Ordinary copy and move remain available.
- MU discovery returns an empty list when a directory is absent, as expected during fresh setup. Upstream Finder can throw for that missing directory. Typed single-file packages retain the no-header exception; untyped folders still require a header in the first 8192 bytes. Configured local dropins remain excluded from untyped discovery.
- Within each directory, MU candidates use lexical path order for reproducible generation. Package order remains Composer metadata order. Upstream Finder uses filesystem enumeration order by default.

Behavioral coverage: `ServicesTest::testDirectoryCopiesRejectAliasRecursionAndPreserveSymlinkTargets`, `MuPluginListTest`. The differential job must retain explicit expectations for these edge cases; it must not globally sort or suppress errors in upstream output.
