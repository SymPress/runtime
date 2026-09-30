# Production operations implementation record

Baseline: release 0.1.0 at 779a45a. The review's older branch-pin and Composer-matrix
findings were already resolved before this change. This record maps the additional
requests to implementation and behavioral evidence; it is not a benchmark claim.

| Request | Implementation | Evidence |
| --- | --- | --- |
| S1 / P1 | Same-parent private atomic writer, writable-directory memoization, native auto cache | ProductionEnvironmentTest |
| S2 | 0600/0640 generated PHP/cache/dump files; identity-aware doctor | ProductionWpConfigTest, ProductionDoctorTest; privileged shared-group CI |
| S3 / S4 | Explicit staging/production home; shell-substitution preflight without execution | ProductionDoctorTest |
| S5 / F2 | URL SHA-256 lock, explicit targeted updates, no credential-bearing URLs persisted | DownloadLockTest, DownloadLockStepsTest, WpCliPinnedDownloadTest |
| S6 | Exact PHAR version/hash and existing Composer bundle preference | WpCliPinnedDownloadTest |
| S7 | Stable/upcoming Composer CI retained; durable restartable layout journal | LayoutJournalCrashTest, PackageLayoutTest |
| P2 | Explicit doctor table health command and quick mode; legacy behavior retained | DbCheckerTest, OperationsCommandTest |
| P3 / P4 | Lazy parser/filter and optional bundled owned environment bootstrap | ProductionEnvironmentTest, ProductionWpConfigTest |
| P5 / F8 | Verified inactive payload/owned-backup pruning with active references protected | PrunerTest |
| F1 | Read-only baseline/input/artifact drift gate with explicit unknown outcomes | OperationsCommandTest, SetupDriftCoverageTest |
| F3 | Native metadata-based invalidation including newly appearing overrides | ProductionEnvironmentTest, WpConfigTest |
| F4 | Production doctor with JSON and PHP identity/webroot declarations | ProductionDoctorTest, KernelBridgeTest |
| F5 | Native mounted-secret references without copying resolved bytes into cache/dumps | ProductionEnvironmentTest |
| F6 | Typed required environment validation in validate/doctor/dump | ProductionEnvironmentTest, OperationsCommandTest |
| F7 | Editable Composer-managed defaults, explicit-value precedence, HTTPS admin default | ProductionWpConfigTest |
| F9 | Names-only active environment comparison without substitutions | OperationsCommandTest, ProductionDoctorTest |

## Verification on 2026-09-30

- Isolated comparison: 253 cases, including exact D26 source-cache invalidation
  and D27 managed-update differences; existing legacy contracts retained.
- Final targeted Doctor review: 22 tests / 173 assertions, including executable
  substitutions behind unusual filenames and non-listable environment directories.
- Shared-group access: 1 privileged test / 18 assertions using distinct identities.
- Real consumer QA: Starter 7 tests / 49 assertions; Demo 27 / 213, with clean
  coding standards and static analysis. Candidate package files were temporarily
  installed into clean test worktrees; manifests and release pins stayed unchanged.
- Consumer replay: 645 Starter and 766 Demo artifact hashes/links remained stable
  across repeated Composer, all-plugins-disabled and standalone setup runs.
- Browser smoke: both homepages returned 200; login and dashboard passed without
  JavaScript errors. Final ordinary Doctor checks also passed in both projects.
- Documentation checker: 72 Markdown files, 47 public options and 157 constants.

The CI integration job reruns the complete suite with real fixtures and rejects
skips, then verifies executed evidence for all 407 parity rows. Its artifacts are
the authoritative evidence for the final pull-request commit.

## Operational boundaries

- Drift uses a successful generation baseline and conservative input fingerprints.
  Unknown custom side effects never pass. It does not predict arbitrary provider PHP.
  Runtime-cache flushing is ephemeral, not a persistent generated-output check.
- Production diagnostics cannot prove remote web-server denial or another identity's
  ACL access locally. They fail or report unknown; running as the PHP identity helps.
- Source cache metadata cannot detect same-size/same-mtime replacement; explicit flush
  remains supported. Deployment dumps are intentionally rebuilt explicitly.
- TOFU protects continuity after initial trust; independently configured hashes are
  still appropriate for first acquisition.
- Existing unowned backup directories are retained. Journal corruption or conflicting
  identities fail closed rather than guessing which data to remove.
- Mounted secret files are supported; no Symfony vault dependency is added.
- No measurements here claim a faster complete request or runner. The optional bundled
  path and lazy classes have functional loading assertions.
