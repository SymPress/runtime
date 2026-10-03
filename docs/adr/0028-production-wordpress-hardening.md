# ADR 0028: Composer-managed WordPress security defaults

Status: implemented; candidate verification is recorded in the change review.

## Decision

The generated `COMPOSER_MANAGED` section enables
`SYMPRESS_ENABLE_WORDPRESS_HARDENING`, `DISALLOW_UNFILTERED_HTML` and
`WP_HTTP_BLOCK_EXTERNAL`, and disables `ALLOW_UNFILTERED_UPLOADS`. It uses the
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

`WP_ACCESSIBLE_HOSTS` defaults to `api.wordpress.org,downloads.wordpress.org`.
The API and download hosts are exact approved core hosts, without a wildcard.
An operator-provided constant or environment value replaces the complete list.
Operators must retain required core hosts and append approved Wordfence,
payment, licensing, SMTP API and other integration hosts explicitly. For example:

```dotenv
WP_ACCESSIBLE_HOSTS=api.wordpress.org,downloads.wordpress.org,api.payments.example
```

The WordPress HTTP API permits the local/site host independently. This constant
is an application HTTP API boundary, not a firewall for raw cURL, SMTP or other
network clients. See the official
[WordPress HTTP request policy](https://developer.wordpress.org/reference/classes/wp_http/block_request/).

Strict `doctor --production` checks the effective four boolean values. Explicit
unsafe overrides remain executable for compatibility but fail production
preflight. Modified/opaque generated PHP still returns unknown instead of
assuming the default is active. Operator-specific external host approval and
actual hardening hook registration require application acceptance evidence.

## Verification

`ProductionWpConfigTest` covers native auto/on/off and environment/predefined
constant overrides, an operator HTTP allowlist, and real WordPress cold/warm
production boot. `ProductionDoctorTest` covers generated defaults and explicit
unsafe exceptions. The private `sympress/security` package remains independent;
no Starter or Demo integration follows from this decision.
