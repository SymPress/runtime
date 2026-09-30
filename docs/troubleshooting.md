# Troubleshooting

Start with `vendor/bin/runtime validate`, then
`vendor/bin/runtime doctor --json`. Run them from the project root with
the same environment and PHP version as your application. Share redacted results,
not your `.env`, dump, authentication files or generated salts.

## Composer cannot fetch Runtime

The repository is private. The identity running Composer needs read access.
A developer's SSH agent is not automatically available in CI. Supply a
repository-scoped deploy key and pinned host keys, or private Composer GitHub
authentication. Do not put the credential in the manifest or lockfile.

## Composer installed packages but setup did not run

Check `config.allow-plugins` and whether installation used `--no-plugins`.
The standalone binary can run explicitly:

```sh
vendor/bin/runtime --no-interaction
```

For standard WordPress installer layouts it restores package placement after a
plugins-disabled install. Root or overlapping package destinations and arbitrary
third-party installers still need their normal Composer installers. See
[Deployment](deployment.md#build-with-composer-plugins-disabled).

## Doctor exits with 2

Exit 2 means a check is unknown without a known failure. For example, disabling
database preflight does not prove connectivity. Read each check's status, supply
the required service or credentials, and rerun. Exit 1 means a known failure.

## The site still uses an old URL or environment value

Check actual process variables first; they override files. Then check whether
a dump is deployed. `flush-env-cache` only removes the runtime cache.

```sh
vendor/bin/runtime flush-env-cache
```

Rebuild or deliberately retire a stale dump. Confirm the public URL and routes;
the physical core subdirectory does not imply a URL suffix.

## Login or admin returns 404

Confirm that the web document root and root WordPress endpoints are routed to
the actual core files. Runtime does not change Nginx or Apache. Preserve the
project's working route configuration and compare it with its chosen
`WP_HOME` and `WP_SITEURL`.

## A release fails after clearing var/

The parser under `var/runtime/<fingerprint>/` is required PHP code. Restore the
matching configuration and payload from the same release. Keep this directory out
of generic cache-cleanup commands. See [Deployment](deployment.md).

## A generated file will not be overwritten

Read the diagnostic and compare the target with the intended generated file.
`prevent-overwrite`, unmarked configuration, directories and symlinks have distinct
guards. `--force` is an explicit overwrite choice; it does not authorize crossing
unsafe paths or replacing arbitrary directories.

## A custom step is missing

Confirm the class is autoloadable and its registered name matches `name()`.
Check `--list-steps`. Command-only steps require explicit selection. Validate
the file paths relative to the project root, even if the settings live in a subdirectory.

## A download fails before setup completes

Check HTTPS, the expected digest and `download-max-bytes` (16 MiB by default).
Only raise the limit for a trusted artifact of a known size. Existing destinations
are preserved when verification fails.
