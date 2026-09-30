# Downloads and the project lock

Native projects enable `download-lock` by default. Every successful URL dropin,
remote environment example and downloaded WP-CLI PHAR is recorded in
`sympress-runtime.lock` in the project root. The same URL must return the same
SHA-256 digest on subsequent downloads. A mismatch fails before replacing the
artifact. Both legacy profiles retain their unlocked default; set
`"download-lock": true` to opt in.

The lock uses trust on first use. Its first recorded digest establishes continuity
for later downloads, not independent authenticity of the initial response. Use
`download-checksums` to assert an independently verified SHA-256 digest, and
`require-download-checksums` to require explicit assertions. These assertions,
HTTPS policy, redirect validation and `download-max-bytes` remain enforced even
with `--update-lock`.

## Review and update

Commit `sympress-runtime.lock` alongside the project configuration. For a reviewed
source change, select the affected steps explicitly:

```sh
vendor/bin/sympress-runtime -n --update-lock --force dropins envexample
```

Only URLs actually downloaded by those steps are refreshed; untouched entries
remain intact. `--force` permits replacement where the normal overwrite policy
would skip an existing generated file. Step conditions still apply: for example,
the environment example is skipped when the configured environment file exists.
`--update-lock` is a command invocation option, not a persistent project setting.
A changed URL establishes a separate identity, so review configuration changes as
well as lock changes. Local file sources are outside the URL lock.

## Stored data and failures

The versioned JSON file maps `SHA256(full original URL)` to `SHA256(response bytes)`.
WP-CLI additionally uses `SHA256("sympress-runtime:artifact:wp-cli.phar")` as a
stable executable identity across release URLs.
URLs, credentials, query tokens and response contents are never stored in the
lock or included in downloader errors. Redirects retain the original URL identity;
every redirect destination must still satisfy the transport policy. Query changes
create distinct identities even when the visible resource path is unchanged.

The lock is published atomically with mode `0600`, inside the project boundary.
An adjacent private `.sympress-runtime.lock.guard` serializes concurrent updates;
ignore that guard file in version control. Malformed locks, symlink lock paths,
failed HTTP requests, checksum failures and failed artifact verification fail
closed. A failed target write does not create a trust entry.

Services supplied by Runtime inject the project lock into the downloader. Extension
code constructing `UrlDownloader` itself should supply `DownloadLock` as its fourth
constructor argument to participate in the same project policy.

## WP-CLI

WP-CLI's selected PHAR and checksum sidecar participate in the lock. The moving
latest-release discovery response is excluded: it is metadata used to select a
versioned artifact. The stable WP-CLI identity still rejects changed PHAR bytes
when discovery selects a different release URL; accepting them requires
`--update-lock`. Set `wp-cli-version` to keep that selection reproducible, and
`wp-cli-sha256` for an independent PHAR assertion. The release SHA-512 check must
also succeed before the PHAR is trusted or published. See [WP-CLI](wp-cli.md).

A downloaded WP-CLI PHAR is checked against its logical lock digest again before
local reuse, without network access. Existing project-owned PHARs without a logical
pin remain usable for compatibility. A selected `wpcli` invocation with
`--update-lock` explicitly adopts their current bytes; verify the artifact before
adoption. Explicit `wp-cli-sha256` assertions apply before adoption too. To fetch a
new PHAR, move the existing local PHAR aside first: Runtime continues to prefer a
local artifact over downloading, even when a version is configured.
