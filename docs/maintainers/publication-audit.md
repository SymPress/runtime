# Public distribution audit

On 2026-09-30, Runtime commit `c7326255c23e6fd00149f4ccfd03498e35306ba3`
was reviewed before changing GitHub visibility from private to public.

- Gitleaks 8.30.1 was downloaded from its official release and checked against
  the published SHA-256. An all-reference scan covered 77 reachable commits
  (70 commits with scanned diffs), with no findings and exit status 0.
- Remote branch/tag heads matched locally available history; the repository was
  not shallow. The exported distribution snapshot also had no findings.
- 197 historical documentation blobs were checked for personal absolute paths,
  private addresses and hostnames. Forty pattern candidates were reviewed and
  resolved as environment filenames such as `.env.local`; none required redaction.
- Kernel, Coding Standards and the QA remote mirror were also scanned with no
  findings. This is scoped scanner evidence, not a guarantee about every possible
  disclosure.

GitHub visibility is now public and private vulnerability reporting is enabled.
The GPL license and NOTICE remain intact. Maintainer documentation is excluded
from Composer distribution archives but remains public in Git history.

QA 0.1.0 was published from reviewed PR #4 with all CI checks passing. The tag
is SSH-signed and was verified locally. The release signing key fingerprint is
`SHA256:t63b+AEbAYq0XN04EJw/SAF3zsIdpzXoLyjlgsbD9ow`; only its public key belongs
in the repository. Account-level GitHub verification and Runtime Packagist
registration are separate distribution gates tracked in [V1 readiness](v1-readiness.md).

Rescan new release commits before publication. Do not infer a new commit's scan
result from this earlier audit.
