# ADR 0021: Native MU-plugin loading

Native setup sorts discovered MU entry paths before generating the loader.
The native loader resolves entries against `WPMU_PLUGIN_DIR`, including when
the loader itself is linked from another directory. It emits `mu_plugin_loaded`
after loading each entry, preserving the event path when plugin code uses a
similarly named local variable.

The admin `plugins_list` filter adds individual entries with plugin metadata,
preserves existing rows, falls back to the entry's directory name when a Name
header is absent, and sorts the visible list by display name. It does not unhide
an empty/hidden Must-Use list. The filter is available in WordPress 6.3+.

This is a native improvement informed by the [alternative-project review](https://github.com/SymPress/runtime/blob/main/docs/maintainers/upstream-review.md).
The two compatibility profiles retain discovery ordering and loader output
behavior required by the pinned comparison baseline. The new native template
placeholder `MU_NATIVE` selects the behavior; custom templates retain responsibility
for their own implementation.

Regression coverage: `MuPluginListTest`, `MuLoaderRuntimeTest` and
`DefaultArtifactStepsTest`. Existing package entry discovery, root MU files,
configured dropin exclusion and missing-file handling remain supported.
