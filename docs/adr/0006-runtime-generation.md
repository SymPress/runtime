# ADR 0006: Self-contained configuration and deterministic generation

Status: proposed.

## Constraint

The work order requires Symfony Dotenv parsing and a generated wp-config without a Composer dependency at runtime. Upstream templates directly require Composer autoload and WordPressEnvBridge. Simply renaming that require would violate the requested boundary. A build-time dump alone would lose first-request dotenv parsing and therefore is not a full replacement.

## Decision

Build a versioned, self-contained bootstrap payload from the runtime's own environment implementation and a narrowly scoped Symfony Dotenv distribution. Embed it in, or emit it alongside, the generated wp-config with direct requires only. It must contain every transitive parser class it uses and no calls to vendor/autoload.php, Composer APIs or runner DI. The exact payload format is an implementation detail to validate in Phase 2; the boundary is an acceptance test that removes vendor/autoload.php before executing the generated config against stub WordPress files.

Keep upstream section identifiers and placeholder compatibility in the generated main file. Native generated artifacts have explicit ownership/version markers. The payload is deterministic for a given runtime version and settings. Build-time dump bypasses parser work but does not replace the parser capability. Atomic replacement must not leave main file and payload on incompatible versions.

Symfony Dotenv code carries its own MIT attribution and license. If any WP Starter source/template code is reused, preserve its MIT notice and list files in NOTICE; prefer independent implementation of the behaviors described in the matrix. GPL-2.0-or-later applies to SymPress-owned implementation. No copied template prose or decorative quotations are needed.

Stable generation preserves managed salts, normalizes paths deliberately, retains section edits according to the tested regeneration policy and only writes changed bytes. Test two completely separate runner processes: an in-process cached Salter is not evidence of idempotence. Random generation is injected only in tests and never replaced with predictable production salts.

Native root wp-config plus webroot proxy follows D while compatibility can maintain R placement. Custom templates may opt out of the native bootstrap but must receive explicit diagnostics about guarantees they replace. Generated Site Health information uses a fixed safe field allowlist for paths, environment and cache metadata, never arbitrary environment values.
