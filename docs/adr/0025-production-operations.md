# ADR 0025: Read-only operations and native deployment defaults

Status: implemented; release verification is recorded in the change review.

## Decisions

Native configuration uses automatic writable-only environment caching and
Composer-managed staging/production defaults. Legacy profiles retain boolean
cache defaults, explicit invalidation and automatic health scans when configured;
their file-update defaults are unchanged. ADR 0023 covers environment payloads.

Table scanning moves to explicit native `doctor --database-health`, with optional
`--quick`. Normal preflight still checks connectivity and installation state.
Doctor requires explicit WP_HOME in staging/production; strict production mode
also checks HTTPS, private files, debug and update policy, salts and deployment
dump. It does not execute project configuration to infer arbitrary PHP behavior.

Production diagnostics statically scan environment source files for command
substitution. Without a dump they refuse to parse such input. Generated dump PHP
remains trusted executable configuration, as in the existing bootstrap boundary.

The setup drift gate compares the last successful selection's input fingerprint
and managed output hashes/modes. It never replays arbitrary setup code, downloads,
database work or providers. Changed inputs conservatively require regeneration.
Missing baseline and uninspectable custom behavior return unknown (exit 2);
detected changes return drift (exit 1). No unknown result becomes a pass.
This makes the CI contract useful without presenting a speculative execution
plan as a guarantee for user-defined PHP callbacks.

URL downloads use a private SHA-256 trust-on-first-use lock in native mode.
Explicit configured hashes always take precedence; only `--update-lock` refreshes
pins for selected URLs. First trust still depends on authenticated transport or
an independently supplied hash. The lock does not turn same-origin checksums
into an independent authenticity proof. Legacy locking stays opt-in.

Native *_FILE resolution, typed requirements and a names-only active environment
diff support secret mounts and deployment checks without requiring a Symfony
secrets-vault dependency. Optional bundled bootstrap embeds only owned env
classes; no generated third-party monolith is necessary.

## Boundaries

Runtime does not certify web-server access rules or another user's ACL permissions
from incomplete evidence. Production diagnostics fail or report unknown instead.
Environment size/mtime invalidation detects normal edits and new override files;
same-size edits with a preserved timestamp need an explicit flush. Deployment
dumps remain intentionally immutable until regenerated.

Payload pruning and crash rollback use the ownership and locking rules in
[ADR 0024](0024-layout-journal.md). Existing 0.1.0 tags and consumer locks remain
immutable; these features require a separately reviewed subsequent release.
