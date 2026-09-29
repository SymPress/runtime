# ADR 0009: Independent payload publication and path guards

Status: implementation decision, 2026-09-29; full generated configuration and differential fixtures pending.

The independent parser payload from ADR 0006 is published below `var/runtime/{sha256}`. Its fingerprint includes the builder implementation, environment source, Symfony parser/process source and license notices. A generated configuration references one immutable version. A new build publishes through a sibling temporary directory before any configuration switches to it. Older versions remain available to concurrent requests and are not automatically deleted.

The payload scopes environment and Symfony classes into a fingerprint-specific namespace and provides a local autoloader for that namespace only. It does not require Composer, runner DI or the project's Symfony graph. Symfony Dotenv command substitutions retain the Process implementation, exceptions and platform pipes they need. CLI/Messenger integrations are excluded because the parser does not call them. Both Symfony MIT licenses and the runtime license/NOTICE travel with the payload. Separate-process tests exercise interpolation and a real command substitution without a project autoloader and with a conflicting host Dotenv class.

Existing immutable payloads are checked against their expected bytes. A modified build is reported and preserved, never silently replaced. Staging leftovers from a failed publication are removed only by their creating operation.

Generated targets must remain physically inside the project, including existing ancestors of a missing target. Explicit external environment directories remain supported for environment files/cache; the content and generated-code targets cannot escape through symlinks. `checkpaths` checks readable files rather than accepting a directory named `wp-settings.php`.

When WordPress itself occupies the project/webroot, `index` leaves its existing front controller in place. Generating a wrapper there would make it include itself. This corrects the upstream edge case without removing the root installation mode. The differential case must record the preserved core index explicitly.

`flush-env-cache` removes only the configured runtime cache path. A symlink is unlinked without touching its target. A real directory at that filename is an error and is never removed recursively. Other runtime versions, kernel caches and build-time dumps are unaffected.
