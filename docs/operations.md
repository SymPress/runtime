# Deployment checks and maintenance

These commands and options are unreleased. They will be available in the next
Runtime release; installing 0.1.0 does not include them.

Build once with the regular Composer installers, verify the generated artifacts,
then deploy the lockfiles, generated configuration and referenced runtime payload.
Run checks as the PHP service identity when possible.

## Check generated files before deployment

A successful native setup records its selected steps, setup inputs and managed
outputs in private `var/runtime/setup-*.json` files. Keep these records with the
release when using drift checks:

```sh
vendor/bin/sympress-runtime index wpconfig muloader wpcliconfig
vendor/bin/sympress-runtime --check index wpconfig muloader wpcliconfig
vendor/bin/sympress-runtime --dry-run index wpconfig muloader wpcliconfig
```

Use the same selection for setup and checking. The check reports changed/missing
artifacts, permission changes and changed setup inputs without running providers,
scripts, database operations or downloads. It prints paths and reasons, never
contents. Exit 0 means the recorded generation is current, 1 means drift, and 2
means no successful record or behavior that cannot be established statically.

Input changes conservatively mark affected managed outputs for regeneration;
this is a drift preview against a successful build, not execution of arbitrary
custom steps in a sandbox. Custom providers, callbacks and commands require their
own read-only checks and are reported as unknown. A fresh project must run setup
before an output comparison exists. `--dry-run` reports drift with exit 0; it
still returns 2 for unknown evidence. It never establishes a new baseline.

## Check production readiness

```sh
vendor/bin/sympress-runtime dump-env production
vendor/bin/sympress-runtime doctor --production --webroot=public --json
```

The production gate checks explicit HTTPS `WP_HOME`, debugging, the deployment
dump, Composer file-update policy, private salts and readable private artifacts.
It never executes `wp-config.php` to inspect settings. Known literal definitions
before the generated sections take precedence over environment values and
defaults. Opaque PHP, definitions between sections, edited sections, early hooks
and application autoloading produce `unknown` for effective settings. Explicit
environment values cannot prove the effect of arbitrary earlier PHP; verify such
custom bootstrap behavior in the deployed PHP runtime before release. Standard
generated sections remain statically verifiable.

The declared webroot must match the real server document root. Place environment
files outside it. Runtime does not certify remote server deny rules from local
configuration. Verify those rules separately with HTTP acceptance tests if your
deployment intentionally stores environment files under the document root.

Use `--php-user=www-data` for POSIX mode checks. Checks across identities can
return unknown because mode bits cannot establish ACL behavior. Running the
command as the real PHP-FPM identity provides stronger evidence; a CLI user
being able to read a file is not proof that another service user can read it.

Doctor fails for missing `WP_HOME` in staging and production even without the
strict production profile. Environment files containing shell substitutions are
not parsed during diagnostics without a dump. The strict profile rejects such
source files; use mounted secrets or deployment-time materialization instead.

## Database maintenance is explicit

Native setup only checks connection/install status. Even `db-check: "health"`
does not run a table scan automatically:

```sh
vendor/bin/sympress-runtime doctor --database-health --quick
```

Omit `--quick` for the full table check. Schedule this as maintenance:
[mysqlcheck can lock tables](https://dev.mysql.com/doc/refman/8.4/en/mysqlcheck.html).
Existing compatibility profiles retain their explicit health-setting behavior.

## Compare required environment names

```sh
vendor/bin/sympress-runtime env:diff --json
```

The report compares `.env.example` with the selected environment file chain
and process values. Only names are shown. Shell substitutions in example or
active files are never executed by this command. Missing names fail the check;
extra names are informational.

For typed deployment requirements, configure:

```json
{
  "required-env": {
    "DB_NAME": "string",
    "SMTP_PORT": "int"
  }
}
```

Validation, doctor and dump creation reject missing, empty or mistyped values
without printing them. Mounted `*_FILE` values work with these requirements.

## Keep disk usage bounded

```sh
vendor/bin/sympress-runtime prune --keep=2 --dry-run --json
vendor/bin/sympress-runtime prune --keep=2 --json
```

Pruning retains every active payload referenced by generated configuration and
the newest N inactive payloads and owned backup trees. It does not follow
symlinks, discard pending recovery state or remove unrecognized directories.
See [Deployment](deployment.md) for journal recovery and backup ownership.
