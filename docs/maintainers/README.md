# Maintainer documentation

The [user guide](../README.md) describes how to install and use Runtime. This
directory holds engineering evidence used to maintain it.

- [Acceptance record](acceptance.md): tested revisions and remaining release gates.
- [Behavior matrix](parity.md): source inventory mapped to executable tests.
- [Test plan](test-plan.md) and [comparison harness](differential.md).
- [Source evidence](source-evidence.md) and [pinned inventory](upstream-inventory.json).
- [Alternative-project PR review](upstream-review.md).
- [Architecture decisions](../adr/README.md).

The [historical phase reports](history/phase0-review.md) record implementation-time
decisions. Their open PRs, counts and pending actions can be outdated; do not use
them as current installation or release instructions. They are retained in Git
and excluded from the Composer distribution.
