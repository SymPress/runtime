# ADR 0024: Restartable package recovery and explicit retention

Status: implemented; operational verification remains subject to release acceptance.

## Decision

Supersede the hard-interruption limitation in ADR 0019 and ADR 0022 with a private
write-ahead journal at `var/runtime/package-layout.pending.json`. Before mutation,
store original Composer metadata, layout state and binary proxy snapshots plus each
planned move's source inode/device or symlink text. Persist an intent cursor before
each move. Flush file contents and synchronize containing directories before acting.

On the next invocation, recover under the maintenance and package-layout locks before
reading installed metadata. Roll back attempted moves in reverse order, recognizing
already-restored objects, then restore snapshots. Persist rollback progress so another
interruption remains recoverable. A durable commit marker distinguishes completed
transactions; committed recovery completes backup ownership receipts and removes the
journal. Replaced trees remain under `var/runtime/package-backups`.

Validate the journal envelope digest, schema, root/vendor identity, original installed
package paths, configured destinations and metadata paths before recovery mutations.
Reject escaping paths, symlink ancestors, unexpected metadata directories, changed
move identities and corrupt records. The digest detects corruption, not a malicious
actor with write access to both project metadata and its journal. Never infer a merge
or delete an unknown package tree to make recovery succeed.

## Retention

Expose explicit `prune --keep=N` with a dry-run plan. Serialize it with generation and
recovery. Preserve active generated configuration references including a custom
configuration path. Keep N newest inactive immutable payloads and N owned backups.
Delete only payloads with verified complete manifests and backups with matching
Runtime ownership receipts. Unknown files, modified artifacts and symlink trees stay.
Pending recovery blocks prune. Cross-release references and in-flight requests remain
deployment concerns; cleanup is never automatic.

## Evidence

`LayoutJournalCrashTest` kills real child PHP processes with SIGKILL at transaction
boundaries, including a second interruption during rollback. The next preparation
preserves package data and completes normally. Existing `PackageLayoutTest` retains
its exception rollback and update/link coverage. `PrunerTest` covers dry-run retention,
active/custom references, unknown files, symlinks, ownership receipts, pending journals
and lock contention. See [Deployment](../deployment.md) for operational use.

Successful fsync calls are the durability boundary. Faulty storage, unsupported
filesystem durability or concurrent writes bypassing Runtime locks are not promised
atomic recovery. On detected ambiguity, preserve the release and fail closed.
