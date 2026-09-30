# ADR 0008: Environment and database service boundaries

Status: implementation decision, 2026-09-29; generated runtime and differential fixtures pending.

This implements D06, D09 and D16 without removing the environment, cache or database APIs.

- Native secret constants preserve their raw bytes. The release profile retains escaped string behavior and the dev profile retains `addslashes`; these transformations are explicit profile behavior. The generated native configuration must use PHP literals safely rather than pre-escaping credentials.
- The CLI environment service never consumes a runtime cache. It loads current files lazily. Runtime cache payloads have a version and environment identity, are validated before hydration, and use atomic replacement with mode 0600. Unmarked files, directories and symlink targets are preserved. Legacy serialized cache formats require rebuilding during migration; accepting legacy configuration does not mean trusting obsolete generated artifacts.
- Native environment reads prioritize actual process values. HTTP request headers are excluded, and native dotenv writes avoid `putenv`. The independent bootstrap must retain this behavior and all Symfony Dotenv parsing capabilities.
- Database probes represent unavailable/unauthorized connections as unknown, not a confirmed absent database. The additional `status()` API exposes nullable existence/installation results and a fixed diagnostic reason. The legacy boolean accessors still exist. Unknown results do not publish false existence flags into the environment.
- Supplied database flags must be complete, boolean and consistent. Upstream skips its probe if any flag exists, which can silently treat incomplete state as authoritative. Native validation fails explicitly. Database/user string `0` is accepted as nonempty; upstream's truthiness check rejects it.
- Host parsing supports explicit TCP ports, bracketed IPv6 and Unix sockets. `mysqlcheck` receives credentials only through a private temporary option file, removed on success and failure. It receives a literal database argument after `--`; no password is interpolated into argv or a shell command.

Evidence: `EnvironmentTest`, `EnvironmentFiltersTest`, `ConstantCatalogTest`, `DbCheckerTest` and `DatabaseTest`. Exact differential cases must preserve the source's false/unknown distinction and incomplete-flag behavior rather than normalizing them away.
