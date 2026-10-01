# Public distribution acceptance

## Release candidate

On 2026-09-30 the signed `v1.0.0-rc.1` tag resolved to
`b532fdc06c3e46b1f7b10a99557814b283d5018d`. Its tree matches the reviewed
PR #18 head `2988c5a`, where all 16 checks passed. The integration artifact
records 407/407 proven parity rows and 1,277 executed tests.

The owner registered the package on Packagist. The existing active GitHub push
webhook accepted the tag delivery with HTTP 202. Composer's public P2 metadata
then supplied the exact RC source revision without a manual update request.

An empty project declared only its name, license, an empty `require` object and
`config.allow-plugins.sympress/runtime: true`. It had no custom repositories.
The following commands ran with an isolated Composer home, no Composer/GitHub
authentication environment variables and no global Git configuration:

```sh
composer require 'sympress/runtime:^1.0@RC' --no-interaction
composer install --no-interaction
vendor/bin/runtime --version
vendor/bin/runtime --no-interaction
```

The first command installed 45 packages from public archives, including Runtime
RC 1 at the exact tag revision, and exited 0. Composer's advisory check reported
no known vulnerabilities. The repeated install changed no package and exited 0.
The version command reported `SymPress Runtime 1.0.0-rc.1`.

Both automatic Composer runs deferred site setup because the empty project had
neither WordPress Core nor Runtime configuration. Explicit setup correctly exited
1 with the requirement for exactly one installed WordPress Core package. This
checks package installation, not a working WordPress deployment.

The existing public dependency download cache was reused; no private package
repository, Runtime VCS override or source checkout override was used. The RC
archive itself was downloaded from its public distribution URL.

Release workflow:
[36761888716](https://github.com/SymPress/runtime/actions/runs/36761888716).
All technical jobs passed and the GitHub prerelease was created. Its final
Packagist check failed because the ordinary package metadata URL still returned
a pre-RC response with `s-maxage=43200` (twelve hours). Composer's public P2
metadata, the actual installation and a release-specific metadata query all
verified the RC revision. The 1.0 workflow adds a unique public query per attempt;
the tag was not rewritten to alter the historical run.
Consumer acceptance and the final stable distribution check are separate gates
recorded in [V1 readiness](v1-readiness.md).

## Stable 1.0

The signed `v1.0.0` tag resolves to
`56b89a0958d24d6ae62944c0dca9e205d9fb63d5`. Its tree matches the final
PR #19 head `8498986`, where all 16 checks passed. The `1.x` maintenance branch
was created from this same commit.

A separate empty project repeated the public installation procedure above with
`composer require 'sympress/runtime:^1.0' --no-interaction`. It used no custom
repository, Runtime source override or authentication. Composer installed the
stable archive at exactly that revision, reported no known dependency advisories
and exited 0. Repeating `composer install --no-interaction` changed no package
and exited 0. The installed executable reported `SymPress Runtime 1.0.0`.

The GitHub webhook populated Composer's public P2 metadata automatically. The
stable release workflow is
[36766001204](https://github.com/SymPress/runtime/actions/runs/36766001204);
all its jobs passed, including the corrected metadata verification. It published
the stable GitHub release and confirmed Packagist's exact source revision. Its
integration artifact records **1,277 tests, 9,396 assertions, no failures or
skips, and 407/407 proven parity rows**.
