# ADR 0028: Composer-managed WordPress security defaults

Status: implemented; outbound HTTP decision corrected for 1.x compatibility on
2026-10-05. Candidate verification is recorded in the change review.

## Decision

The generated `COMPOSER_MANAGED` section enables
`SYMPRESS_ENABLE_WORDPRESS_HARDENING` and `DISALLOW_UNFILTERED_HTML`, and disables
`ALLOW_UNFILTERED_UPLOADS`. It uses the
same native staging/production `auto` selection as the existing file update
defaults. Explicit `composer-managed=true` also applies these defaults locally;
`false`, local/development `auto`, and legacy profile defaults preserve their
existing opt-in behavior. Existing constants and explicitly typed environment
values remain authoritative.

`SYMPRESS_ENABLE_WORDPRESS_HARDENING` is a typed boolean Runtime environment
value and a generated default constant. The switch activates only a consumer
that implements it; Runtime does not import or install a Security package or
register its WordPress hooks. Bootstrap acceptance must verify the consumer's
actual behavior separately.

Outbound WordPress HTTP blocking remains explicit opt-in throughout Runtime 1.x.
The automatic block introduced in 1.2.0–1.2.2 breaks existing payment, licensing
and API integrations on an otherwise ordinary production/staging update.
`composer-managed` and the hardening switch alone therefore do not enable it.
Existing explicit `WP_HTTP_BLOCK_EXTERNAL` environment values and constants
remain authoritative. Stock managed configuration must be regenerated when
upgrading; preserved customized sections require operator review.

When blocking is explicitly enabled, `WP_ACCESSIBLE_HOSTS` in a generated
Composer-managed section defaults to `api.wordpress.org,downloads.wordpress.org`.
The API and download hosts are exact approved core hosts, without a wildcard.
An operator-provided constant or environment value replaces the complete list.
Operators must retain required core hosts and append approved Wordfence,
payment, licensing, SMTP API and other integration hosts explicitly. For example:

```dotenv
WP_HTTP_BLOCK_EXTERNAL=true
WP_ACCESSIBLE_HOSTS=api.wordpress.org,downloads.wordpress.org,api.payments.example
```

The WordPress HTTP API permits the local/site host independently. This constant
is an application HTTP API boundary, not a firewall for raw cURL, SMTP or other
network clients. See the official
[WordPress HTTP request policy](https://developer.wordpress.org/reference/classes/wp_http/block_request/).

Strict `doctor --production` checks the effective hardening switch and the two
unfiltered-content/upload boolean values. Its `wordpress-hardening-switch` result
establishes only configuration. A separate `wordpress-hardening-activation`
result is `unverified`: Runtime neither installs the private Security package
nor evaluates consumer hook activation. This advisory result does not fail a
Runtime-owned preflight, and exit zero is not application security acceptance.

The outbound policy check accepts the compatibility-preserving disabled state.
When explicitly enabled, `external-http-hosts` rejects empty host lists, empty
entries and a global `*` entry. Disabled blocking makes the host restriction
`not-applicable`. Opaque generated PHP returns `unknown`. Operator-specific host
approval and actual hook registration require application acceptance evidence.

## Verification

`ProductionWpConfigTest` covers native auto/on/off and environment/predefined
constant overrides, an operator HTTP allowlist, and real WordPress cold/warm
production boot, and the real WordPress HTTP policy for production and staging.
`ProductionDoctorTest` covers generated defaults, explicit unsafe exceptions,
empty/global host lists and the unverified hook activation result.
The private `sympress/security` package remains independent;
no Starter or Demo integration follows from this decision.
