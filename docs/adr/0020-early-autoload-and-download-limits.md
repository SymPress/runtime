# ADR 0020: Preserve early project autoload and bound service downloads

Status: implemented review correction; final suite and CI evidence tracked in [acceptance](https://github.com/SymPress/runtime/blob/main/docs/maintainers/acceptance.md).

## Early application autoload

WP Starter loads WordPress's hook API before requiring the project Composer autoloader. Its `autoload.files` entries can therefore register hooks before environment-specific PHP, the early hook and ordinary plugins run. Unconditionally omitting Composer lost that capability for general projects even though SymPress consumers supplied a later MU/kernel bootstrap.

Add boolean `wp-config-autoload`, false by default in native mode and true in both legacy profiles. An explicit value overrides the profile. Require the configured vendor autoloader once immediately after `wp-includes/plugin.php`, before environment PHP and early hooks. Keep the scoped environment-parser payload independent of Composer. Migration writes the inherited true setting and explains the early hook/class dependency; changing to native is an explicit semantic choice.

The differential cases `release/early-project-autoload` and `dev/early-project-autoload` execute Composer files, an environment PHP file and an early hook against upstream and Runtime. They assert hook API/class availability, order and single execution. The explicit-false/native fixtures continue to prove generated-config independence. Edited `AUTOLOAD` sections and custom templates remain the project owner's responsibility.

## Download memory bound

Add positive integer `download-max-bytes`, default 16777216 (16 MiB), to the URL-download service. Disable response buffering, reject an oversized declared Content-Length and count streamed bytes so absent or misleading headers cannot evade the limit. Verification and size checks complete before publication; failure preserves the existing destination. Projects may explicitly raise the limit for a trusted larger dropin/artifact.

This applies to downloads through `UrlDownloader`, including dropins, extension services and WP-CLI's PHAR, release metadata and checksum sidecars. WP-CLI additionally retains its SHA-512 verification contract. SHA-256 configuration accepts normalized case; regression coverage includes uppercase input. HTTPS/checksum policies remain independently configurable and unchanged by the byte limit.

## Disabled compatibility controls

The final review found that disabling compatibility still honored `WPSTARTER_ENV_LOADED` and `WP_STARTER_ENV_TO_CONST`. Propagate the compatibility flag through runner loading, generated configuration, caches, dumps and doctor. Disabled mode ignores both legacy controls while retaining their native replacements and WordPress environment aliases. Enabled legacy controls emit deduplicated name-only diagnostics. Include the small diagnostic helper in the scoped payload so request-time parsing remains self-contained.

Cache metadata now records compatibility mode; missing metadata denotes the former enabled behavior. Reject mismatches and require a cache flush or dump rebuild, rather than letting a warm cache reintroduce values produced by disabled legacy controls. Cold parsing, generated requests, runner dumps and warm dump loading have regression coverage.
