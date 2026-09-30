# ADR 0011: Preserve the default environment directory capability

Status: implementation decision based on a reproduced pinned-source defect, 2026-09-29.

## Evidence

At dev commit `059bbc9198ac51dee86c5233b6b006ca4d8008a4`, Config defaults `env-dir` to null. `Step/WpConfigStep.php:120` unwraps that option without a fallback; `Config/Validator.php:566` rejects null with `Folder name must be in a string.`. The blocking step reports failure and leaves the index absent, but ComposerPlugin does not propagate the step result to its command exit status. The observed exit status is zero. The release baseline resolves the omitted directory through its root fallback instead.

The isolated `dev/default-env-dir` fixture executes the unmodified upstream plugin with the option omitted. It requires the exact diagnostic, exit status and absent index. A matching candidate fixture must generate a working index/config and boot once with database settings from the root environment file. Ordinary generated-runtime comparisons set `env-dir: "."` identically on both sides so the dev oracle can execute; the failure fixture is not waived.

## D23 decision

An omitted environment directory resolves to the project root in every runtime profile. This preserves the required zero-config feature and the release behavior. Reproducing the dev failure would remove that capability. Explicit directory validation and configured paths remain available. Failure exit propagation remains governed separately by D12.

This adds the exact default-directory difference to PAR-OPT-015; it does not allow arbitrary directory or config-validation mismatches. The source checkout and installed oracle remain unmodified.
