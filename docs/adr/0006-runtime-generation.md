# ADR 0006: Self-contained configuration and deterministic generation

Status: accepted by the project owner on 2026-09-29 with Phase 0 approval.

## Constraint

The work order requires Symfony Dotenv parsing and the ability to run generated wp-config without Composer. Upstream templates additionally load Composer early so application files can register hooks and use vendor classes. These are separate capabilities: the parser must remain independent while early project autoload remains available. ADR 0020 corrects the initial unconditional omission discovered during review. A build-time dump alone would lose first-request dotenv parsing and therefore is not a full replacement.

## Decision

Build a versioned, self-contained bootstrap payload from the runtime's own environment implementation and a narrowly scoped Symfony Dotenv distribution. Emit it alongside generated wp-config with direct requires only. The payload contains every transitive parser class it uses and no calls to vendor/autoload.php, Composer APIs or runner DI. It is published under `var/runtime/<fingerprint>/`. With `wp-config-autoload=false`, acceptance tests remove vendor/autoload.php before executing generated configuration. Legacy profiles enable early application autoload by default; this explicit application dependency does not change the parser payload's independence. See [deployment](../deployment.md) for mandatory publication and rollback-safe retention.

Keep upstream section identifiers and placeholder compatibility in the generated main file. Native generated artifacts have explicit ownership/version markers. The payload is deterministic for a given runtime version and settings. Build-time dump bypasses parser work but does not replace the parser capability. Atomic replacement must not leave main file and payload on incompatible versions.

Symfony Dotenv code carries its own MIT attribution and license. If any WP Starter source/template code is reused, preserve its MIT notice and list files in NOTICE; prefer independent implementation of the behaviors described in the matrix. GPL-2.0-or-later applies to SymPress-owned implementation. No copied template prose or decorative quotations are needed.

Stable generation preserves managed salts, normalizes paths deliberately, retains section edits according to the tested regeneration policy and only writes changed bytes. Test two completely separate runner processes: an in-process cached Salter is not evidence of idempotence. Random generation is injected only in tests and never replaced with predictable production salts.

Native root wp-config plus webroot proxy follows D while compatibility can maintain R placement. Custom templates may opt out of the native bootstrap but must receive explicit diagnostics about guarantees they replace. Generated Site Health information uses a fixed safe field allowlist for paths, environment and cache metadata, never arbitrary environment values.
