# Security

Report vulnerabilities using [GitHub private vulnerability reporting](https://github.com/SymPress/runtime/security/advisories/new)
when available, or email brian.schaeffner@sympress.de. If GitHub does not offer
private reporting, use email rather than a public issue. Include the affected
commit or version, a minimal reproduction and the expected trust boundary.
Do not include production credentials in the report. Coordinate disclosure
privately until a fix and upgrade guidance are available.

## Supported revisions

Security fixes target the latest stable 1.x release. Users of 0.x, beta and
release-candidate versions must upgrade; those versions and development snapshots
are not maintained security lines. Fixes ship as reviewed, signed tags. Use
`sympress/runtime:^1.0`, commit the resolved lockfile, and update to the latest
1.x patch when a relevant fix is available.

The `1.x` branch receives stable maintenance and security fixes; applicable fixes
are also ported to `main`. The `main` branch is for 1.1 development and has the
Composer alias `1.1.x-dev`. Development builds are not supported stable releases;
use reviewed 1.x tags and the documented `^1.0` constraint for installations.

The [compatibility policy](docs/compatibility-policy.md) applies to 1.x. Security
fixes may reject unsafe inputs outside the supported contract, with a release
note and a documented safe migration path.

The supported Composer library begins at 2.10.3. The minimum and latest stable
Composer CLI/library combinations are tested for package recovery, including a
scheduled run which opens or updates an issue when compatibility fails. Review
those results before adopting a newer minor. Dependency security blocking must
remain enabled.

## Automated security checks

CI retains PHPStan at the project's maximum level, Composer dependency advisory
checks, and a separate PHP security scan using the maintained
[Semgrep PHP rules](https://github.com/semgrep/semgrep-rules/tree/develop/php/lang/security).
The workflow pins both the scanner version and rule revision. Its selected rules
cover injection, request-derived SSRF, unsafe deserialization and disabled TLS
verification. Findings and parser failures block the security job; no baseline
suppression is used. This is static analysis, not an independent penetration test.
Dependabot checks Composer packages and GitHub Actions weekly.

The tag workflow verifies SSH signatures against the repository's pinned release
signers and requires successful QA, Linux integration, platform, package-recovery
and security gates before creating a release from the matching changelog section.
GitHub's account verification badge is optional; the cryptographic signature check
is mandatory. Packagist must already be registered
with its GitHub update webhook. Optional `PACKAGIST_USERNAME` and
`PACKAGIST_API_TOKEN` repository secrets enable an explicit API refresh using an
Authorization header. Both paths verify the version's exact source revision on
Packagist; an unverified update fails the workflow. Never put those credentials
in the repository or a query-string URL.

## Trust boundaries

Project configuration, PHP providers, extensions, custom templates and steps are
trusted executable inputs. Composer isolation prevents dependency collisions; it
is not a sandbox for project code.

Static validation does not execute PHP providers or the setup autoload file.
Downloads use HTTPS by default and have a size limit. Configured checksums are
verified before replacing a destination. File operations reject unsafe destination
types and path traversal.

Environment files, caches, dumps and generated salts are private. Keep them outside
web access and logs. The [deployment guide](docs/deployment.md) explains ownership,
read-only operation and the required runtime payload.
