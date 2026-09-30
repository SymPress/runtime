# ADR 0022: Tagged releases and Composer recovery compatibility

Status: accepted for 0.1.0.

## Decision

Use normal Composer installation with the standard installers for routine builds.
Offline placement is a bounded recovery feature, with the same documented behavior
for supported layouts; it is not a substitute for dependency resolution.

The production Composer library is constrained to `~2.10.3`. This intentionally
excludes unreviewed minor versions. Older available minor releases are affected by
[Composer security advisories](https://github.com/composer/composer/security/advisories);
security blocking remains enabled. The [2.10.3 changelog](https://getcomposer.org/changelog/2.10.3)
records the relevant security fixes.

The blocking CI matrix exercises:
- Composer CLI 2.10.3 with library 2.10.3.
- The Composer snapshot CLI with library `dev-main` pinned to
  `25612018356ea810bce1b86fba84acd4634b5a96` (the upcoming 2.11 line).

Only the isolated forward-compatibility fixture aliases that development library to
`2.10.99` to satisfy the production constraint. Consumers never receive this alias.
Reports record the actual CLI version, installed library version and source hash,
and assert that the requested library was actually used. Both rows run real
WPackagist normal/offline installs and repeated randomized recovery contract tests.
Moving the production constraint to a new minor requires this evidence and review.

## Release and acceptance

Publish versioned tags after the matrix, full fixture suite, differential comparisons
and consumer acceptance pass. Starter and demo pin `0.1.0` exactly, including the
resolved source reference in their locks. Future updates go through normal review.

The 407-row matrix verifies the listed contracts and explicit ADR differences, not
arbitrary third-party installer behavior or every possible branch of every extension.
Regular root/nested layouts remain usable. Offline recovery rejects unsupported
root/overlapping layouts and unknown conflicts without destructive merging.

Package-backup retention and hard-interruption recovery are operational responsibilities
documented in [Deployment](../deployment.md). Exception rollback is tested; there is
no claim of atomic recovery from SIGKILL or power loss.

## Warning investigation

The reported transient PackageLayoutTest warning had no accompanying message.
The current baseline passed 30 randomized runs of its 15 recovery tests and a full
randomized 1,063-test run with all fixtures, without warnings or deprecations.
The new forward-compatibility matrix then reproduced deterministic warnings from
Composer's `Silencer::call('stat', ...)` when its atomic writer probes a not-yet-existing
file. Native PHP honors Composer's temporary reporting mask; PHPUnit's handler
reported that intentionally suppressed probe.

Recovery tests now execute Composer preparation and binary generation in a PHP
subprocess, matching the production isolation boundary. They still fail on a
nonzero exit or any unsuppressed PHP stderr and verify the resulting metadata,
autoload, backups, rollback and executable proxies. No global warning exclusion
or vendor patch is introduced. CI retains fail-on-warning, prints all issue details,
and always preserves available version reports. This fixes the reproduced matrix
warning; without the original review's warning text, it cannot prove the original
one-off report had the identical cause.
