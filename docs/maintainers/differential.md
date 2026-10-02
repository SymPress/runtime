# Historical differential verification

The external comparison harness was retired on 2026-09-30. It installed and
executed excluded upstream packages, including in an automatically triggered
CI job. The executable harness has been removed. Do not reinstall those packages
to run current tests or qualify a release.

The integration workflow still provisions WordPress, WP-CLI, database and VCS
fixtures, runs Runtime's contract suite with `--fail-on-skipped`, and verifies
executed coverage using `tools/parity-evidence.py`. Supported compatibility
profiles and their regression fixtures remain part of SymPress Runtime.

Earlier comparison results in [the acceptance record](acceptance.md),
[the parity matrix](parity.md), architecture decisions and `history/` document
work performed before this policy change. They are historical evidence, not
instructions to execute the retired harness or proof of a current CI run.

Provenance notices, pinned source inventories and non-executable recognition
templates remain intact. Their presence does not add an upstream dependency.
