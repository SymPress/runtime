# ADR 0014: Extension lifecycle and legacy API

Status: implementation decision, 2026-09-29.

This implements the script/event and extension contracts, with the D01, D12 and D14 differences from ADR 0005.

## D12 lifecycle results and control

One runner-local Symfony dispatcher carries typed pre/post run and step events. Scripts are an ordinary subscriber at priority 0; the bridge cannot double-dispatch callbacks. Function strings, static-method strings, nested static callback pairs and programmatic PHP callables are accepted. Script names normalize case and step aliases. Root run aliases execute in the documented legacy/bare/runtime/native order.

Pre events carry NONE, post-step events carry actual flags, and post-run carries the aggregate. The source always reports SUCCESS to post-run and its Composer command can return zero after a failed step or callback. Runtime returns nonzero for these failures. Callback exceptions do not prevent later callbacks; exception contents are redacted. Halt signals preserve stop-only, halt-and-stop and halt-with-propagation behavior. Post events cannot undo an executed step. A pre-run halt prevents execution but still produces post-run. Skipped steps produce no step events. Pre-run queue changes are permitted; changes during a step are ignored, and WP-CLI remains last.

## D14 supported PHP aliases

Aliases are installed explicitly in the isolated runtime child after static validation and before root/package autoload files. They never enter the package's normal Composer autoload metadata. Legacy Config/Result, Paths, IO/Question, Locator, step interfaces, Steps and halt signals resolve to native equivalents. Config option constants and the WP_STARTER path key remain available. DI aliases provide the shared Services object to Locator-typed constructors. Untyped positional constructors receive Services and RunContext. Existing incompatible loaded legacy classes are diagnosed rather than overwritten.

RunContext is an immutable execution descriptor, not Composer. Required Composer constructor arguments identify the class and parameter that must migrate. Arbitrary live Composer mutation and re-instantiation of old internals cannot be preserved across the required process boundary. Whole-run callbacks formerly typed as Step need a Runner union/type because Runner's queue API is distinct from a single step. The Phase 6 migration analyzer must enumerate such changes; these adapters alone are not a claim that every PHP extension is migrated.

## D01 extension autoload validation

Both extension package types are recognized. Native metadata takes precedence, is statically validated, and permits multiple fallback directories per prefix. Invalid legacy metadata is ignored as upstream does. Package files and PSR-4 paths resolve against actual install paths, with root autoload first. Returning a native container configurator is supported. Classes/files load only in the child; owned loaders are unregistered when their registry is released.

Upstream compares namespace prefixes case-insensitively without a boundary and unconditionally requires the derived path. Runtime requires an exact namespace boundary and case, verifies readability, and permits another loader to handle a missing class. This retains intentional extension loading while avoiding unrelated class interception and missing-file fatals. Differential fixtures assert the exact available/wrong-case/wrong-boundary/missing-class outcomes against both pinned sources, alongside callback ordering and result differences. No broad output exclusions are used.

## Template overrides

All eight native templates, including hidden environment/VCS files, share ordered custom-directory lookup and bundled fallback. Placeholder rendering retains scalar conversion, case/whitespace matching, unknown placeholders and literal dollars/backslashes. A custom template intentionally controls its output; the runtime cannot assume that it preserves the bundled template's functionality.
