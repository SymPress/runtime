# Phase 5 implementation record

Kernel maintenance now resolves the actual canonical environment, APP_CACHE_DIR/APP_BUILD_DIR overrides and separately located discovery metadata. Explicit kernel-only operations avoid WordPress/database preflight, while mixed selections retain it. Target validation happens before deletion; other environments and asset caches remain intact. Build-ID rotation is explicit, persisted privately and exposed as the constant consumed by the kernel before boot.

Optional boot generation detects declared package ownership and existing SymPress MU entries, including copied and symlinked packages. Existing owners suppress generation; previously owned generated files are reconciled when ownership changes or the option is disabled. Multiple/ambiguous owners fail, and unmanaged files remain intact.

doctor/check provide text or JSON diagnostics without running setup autoload files or custom steps. Missing, unknown, provided, not-applicable and failed conditions remain distinguishable. Database/parse failures do not print credential values. Composer forwards the native JSON and build-ID options.

The optional RuntimeBundle contributes doctor/check/validate/dump-env to the actual SiteKernel console. Generated configuration uses the existing kernel bundle filter because the pinned kernel does not activate composer-plugin metadata automatically. Tests prove this route using real WordPress hook code and kernel discovery. The setup container remains independent. Plain WordPress retains no hard kernel dependency.

Integration tests compare real kernel and runtime environment/cache/build paths, reject explicit kernel-environment mismatches, resolve `%env()%` from fresh values/runtime cache/build dump, and execute validate through a compiled kernel console. A production fixture warms the real kernel and then boots from its dump/cache with an invalid dotenv source and no writable project files. All fixture file hashes remain unchanged.

The local verification checkpoint passes composer qa with 973 tests / 6,711 assertions, coding standards and PHPStan max. MariaDB, real WordPress/WP-CLI and Git/Mercurial/SVN integrations were enabled. The complete pinned-oracle differential run remains 206 passing cases. The kernel source used by the integration tests is c24273066431b3c33b4dd372022ad42c100aafd2. No kernel or consumer source was changed.

The 407-row acceptance matrix is 355 partial / 52 planned / zero verified. Full migration reporting, final output comparisons, Windows checks and fresh starter/demo DDEV/admin/console smoke remain Phase 6 gates. Remote runtime dependency resolution still requires the separate PHPUnit 13 companion in sympress/qa.
