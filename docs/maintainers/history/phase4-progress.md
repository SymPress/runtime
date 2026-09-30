# Phase 4 implementation record

The runner now executes scripts through four typed Symfony lifecycle events. Function/static callbacks, nested callback pairs, PHP closures, aliases, exact result flags, halt/propagation controls, pre-run queue changes and error continuation are implemented. The script bridge runs once at priority 0. Existing custom/command-step registration, constructor injection, ordering, selected-only behavior and default replacement remain available.

Extension package discovery supports native and legacy types with actual install paths, validated native metadata, compatible legacy metadata, PSR-4 fallback directories and ordered file loading. Root autoload precedes extension autoload. Static validation executes neither. Files may configure the independent runner container, including event subscribers. Owned loaders are released; a separate normal application process does not see extension-only classes or legacy aliases.

The child-only legacy API supports Config/Result and option constants, Paths and its package key, IO/Question, Locator injection, step interfaces, Steps and ScriptHaltSignal. Typed Locator and untyped positional constructor fixtures run without WP Starter. Required Composer-object arguments produce specific migration diagnostics. Whole-run callbacks use Runner, and context callbacks receive RunContext. Arbitrary Composer object operations still require the Phase 6 migration analysis.

All eight templates have per-template override/fallback tests. Placeholder tests cover case/whitespace matching, scalar conversion, unchanged unknown/nonscalar entries, literal dollars/backslashes and empty-template errors.

Local verification: composer qa passed 947 tests and 6,568 assertions, coding standards and PHPStan max. MariaDB, real WordPress/WP-CLI and all three VCS tools were enabled. The final Config/Paths constant additions also passed focused legacy/path tests and coding standards. Differential coverage adds twelve lifecycle/autoload cases across the two pinned oracles: exact callback order, NONE/SUCCESS/ERROR/partial results, callback exceptions, context identity and namespace/missing-class behavior. D01, D12 and D14 expectations are documented in ADR 0014.

The 407-row matrix remains 348 partial, 59 planned and zero verified. Phase 4 rows now have behavioral evidence, but comprehensive migration reporting, final template differential coverage, Windows checks and fresh consumer acceptance remain outstanding. Remote runtime QA still needs the separate PHPUnit 13 compatibility change in sympress/qa.
