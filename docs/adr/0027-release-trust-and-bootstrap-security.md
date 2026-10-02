# ADR 0027: Protected release orchestration and bootstrap security

Status: prepared for review, 2026-10-01; platform completion is a separate gate.

## Release source trust

Release orchestration accepts an explicit signed annotated SemVer tag through
workflow_dispatch on protected main. The dispatch checkout, API main head and tag's
direct commit target must be identical. A stale dispatch fails. Allowed signers
come from the exact protected dispatch commit object, never the tag-controlled tree
or a mutable checkout file. Tests use isolated signing keys and Git repositories
to verify trusted signatures and reject unauthorized keys, attacker-supplied signer
trees, lightweight tags, nested tags and stale main references.

All technical gates run the same reviewed main commit. Publishing rechecks main,
tag object and commit after approval. External actions/reusable workflows use
immutable source references, token permissions are narrowed by job, checkout does
not persist credentials, and installation jobs receive no publishing secrets.
GitHub account tag verification remains distinct from local trusted-list SSH
verification; historical unknown_key tags are not rewritten or relabeled.

## Required platform boundary

Runtime main must require reviewed PR changes and passing actual checks, including
release workflow/signers/verifier/CODEOWNERS changes. A separate owner-review
ruleset requires one code-owner approval. The sole owner may bypass only that
review rule through a PR; the no-bypass technical-check/deletion/force-push
rules remain enforced. Ordinary branch writers cannot merge their own signer
or orchestration policy changes. Release tags v* may be created only by authorized
release administrators and may not be updated or deleted. The `release` environment
allows only main, requires manual owner approval and disables administrator bypass.
There is one owner, so prevent_self_review is deliberately false; this is manual
owner approval, not independent review.

Move Packagist credentials to that environment and remove repository/organization
accessibility. GitHub cannot recover encrypted secret values for migration: the
owner must restore or rotate them. The workflow fails before creating a GitHub
release if both environment credentials are unavailable. A push-capable branch
attacker cannot consume main-only environment secrets or change approved main/tag
source under these policies. Compromise of an authorized bypass/release owner is
outside this guarantee.

A workflow's default read token setting is not a maximum permission ceiling.
An arbitrary branch workflow can request contents:write and mutate release metadata
for an existing approved tag. Environment YAML alone cannot eliminate that residual
GitHub metadata risk. Prospective immutable releases may bound post-publication
mutation; no existing tag/release is rewritten. The source/package trust and
credential confinement guarantees must not be described as universal GitHub release
operation control. This workflow uploads no artifacts after release publication.

## Runtime decisions

D29 and D30 in ADR 0005 specify canonical URLs, trusted-proxy termination, native
process-value exclusion and automatic cache eligibility. ContextFile creates its
exclusive file under umask 0077, restores the previous mask before later work and
checks 0600 before writing bytes. Legacy environment parsing/cache casting contracts
remain available; all profiles receive the URL/proxy safety boundary. Existing
user-edited managed PHP remains preserved and must be reviewed explicitly.

## Scanner execution evidence

The PHP security workflow runs Semgrep 1.178.0 with the exact reviewed upstream
rules commit and one worker. On this host, default parallelism reproduced an
io_uring engine failure while the SARIF CLI still returned zero with no findings.
A zero finding count is insufficient: `tools/check-security-sarif.py` additionally
checks the scanner pin, successful invocation evidence and absence of execution
warnings/errors. Regression fixtures reject the misleading-success case. The
single-worker scan completed on all 133 owned PHP files with 13 rules and no
findings; it does not establish exhaustive security coverage. Upstream records
[the io_uring failure](https://github.com/semgrep/semgrep/issues/11802) separately.
