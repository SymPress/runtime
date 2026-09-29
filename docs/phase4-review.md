# Phase 4 review

Base: phase-3-default-steps. Head: phase-4-extension-lifecycle.

Custom extensions can now use run/step scripts, typed Symfony events, halt controls, pre-run queue changes and extension-only autoload metadata. Existing legacy Step/Locator implementations run through child-only aliases and constructor adapters; incompatible mandatory Composer dependencies receive an actionable diagnostic. Every native template can be overridden independently. ADR 0014 records exact differences and remaining migration limits.

Local QA: 947 tests, 6,568 assertions, coding standards and PHPStan max. The full differential run passes 206 cases across both pinned WP Starter versions, including twelve new lifecycle/autoload cases with exact D01/D12/D14 expectations. No WP Starter dependency was added to the runtime package and no consumer configuration or database was changed.

Full acceptance remains open: the matrix is 348 partial / 59 planned / zero verified, and the final migration command, native kernel integration, complete output comparisons and fresh consumer smoke are still required. Remote runtime QA is blocked by the unmerged PHPUnit 13 companion in sympress/qa. This PR is a reviewable implementation checkpoint.
