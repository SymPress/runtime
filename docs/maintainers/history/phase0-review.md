# Phase 0 review: sympress/runtime

Suggested PR title: `docs: define WP Starter replacement parity and architecture`

## Problem and result

Starter and demo depend on WP Starter 3.0.1, while the requested replacement includes additional behavior from the upstream development branch. This change pins both baselines and specifies the complete feature union, implementation targets, test identities, consumer priorities and explicit behavior differences before implementation begins.

The package name is `sympress/runtime`. Phase 0 adds documentation and source metadata only. It does not install dependencies, change consumer files or claim runtime parity.

## Review decisions

- Approve both pinned source baselines and native versus legacy profiles.
- Approve the public names in ADR 0002 and the native option proposal.
- Approve canonical WordPress/kernel environment agreement while retaining raw environment filenames.
- Approve a separate Symfony 8.1 runner even though the current asset compiler runs in process.
- Acknowledge that arbitrary live-Composer-typed PHP callbacks require explicit migration; equivalent native services remain available.
- Approve the narrowly scoped differences in ADR 0005 and the self-contained runtime parser payload in ADR 0006.

## Validation

Structural review checks source default/constant/section coverage, unique test IDs, local documentation links and valid JSON evidence. Behavioral tests are planned for implementation phases. No composer qa, differential test or DDEV smoke can be claimed for a documentation-only package.

## Next authorized phase after approval

Phase 1: Composer plugin and process boundary, runner DI, complete configuration/schema/validate, paths/services and CLI selection. Keep each subsequent phase in its own PR. A remote SymPress/runtime repository was not resolvable during Phase 0; the local review artifact is ready, but no remote PR has been created.
