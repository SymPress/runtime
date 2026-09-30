# Security

Report vulnerabilities using [GitHub private vulnerability reporting](https://github.com/SymPress/runtime/security/advisories/new)
when available, or email brian.schaeffner@sympress.de. If GitHub does not offer
private reporting, use email rather than a public issue. Include the affected
commit or version, a minimal reproduction and the expected trust boundary.
Do not include production credentials in the report. Coordinate disclosure
privately until a fix and upgrade guidance are available.

## Supported revisions

The currently published supported stable release is `0.2.0`. The 1.0 line is in
preparation; beta and release-candidate tags are evaluation releases. Once 1.0.0
is published, security fixes target the latest 1.x patch release and 0.x users
must upgrade. Unsupported development snapshots are not a maintenance promise.
Fixes are developed on `main` and shipped as reviewed, signed tags. Pin a reviewed
release and its lockfile; update deliberately when a relevant fix is available.

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

The tag workflow requires GitHub-verified signatures and successful QA, Linux
integration, platform, package-recovery and security gates before creating a
release from the matching changelog section. Packagist must already be registered
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
