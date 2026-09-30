# Security

Report vulnerabilities privately to brian.schaeffner@sympress.de. Include the
affected commit or version, a minimal reproduction and the expected trust boundary.
Do not include production credentials in the report.

## Supported revisions

The current supported release is `0.2.0`. Fixes are developed on `main` and shipped
as tagged releases. Pin a reviewed release and its lockfile; update deliberately
when a relevant fix is available. The Composer library is restricted to `~2.10.3`;
new minor lines require recovery-matrix validation before widening that constraint.

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
