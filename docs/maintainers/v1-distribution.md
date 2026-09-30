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
Consumer acceptance and the final stable distribution check are separate gates
recorded in [V1 readiness](v1-readiness.md).
