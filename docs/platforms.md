# Supported platforms

| Component | Runtime 1.x contract |
| --- | --- |
| PHP | `^8.5`; 8.4 and older are unsupported |
| Composer host | 2.10.3 or newer within 2.x, plugin API `^2.6` |
| Composer library | `^2.10.3`, including offline layout recovery |
| Symfony components | `^8.1`; the lower supported dependency set has its own CI job |
| Event dispatcher contracts | 3.4.2 or newer within Symfony's allowed range; older implicit-nullable signatures produce PHP 8.5 diagnostics |
| WordPress | Composer-installed core; version discovery and actual bootstrap are tested by integration CI |
| Linux | Full fixtures, differential suite, package installation and platform contracts |
| Windows | Platform contracts for normalized paths, copy fallback, `.bat` proxies and permission behavior |
| macOS | Platform contracts for paths, filesystem case behavior and portable filesystem operations |

The platform workflow runs with Composer 2.10.3 and the latest stable release.
See [live CI results](https://github.com/SymPress/runtime/actions) for the tested
commit and resolved tool versions. A passing platform contract suite does not
claim every Linux integration fixture ran on Windows or macOS. POSIX identity
and permission checks cannot infer Windows ACL access; run deployment diagnostics
as the actual PHP identity.

Package recovery flushes journal and generated metadata files on every platform.
Linux and macOS also synchronize affected directory entries. PHP's Windows file
wrapper does not expose directory handles for `fsync`, so Windows directory-entry
persistence depends on the operating system: recovery after sudden power loss or
an OS crash is not guaranteed. File flush failures still stop the transaction.
See [ADR 0024](adr/0024-layout-journal.md) for the durability boundary.

Runtime does not bundle WordPress or impose a web server. Your chosen core,
plugins, themes, database and PHP extensions must support PHP 8.5 independently.
Read-only deployments use a built environment dump and retained payloads as
described in [Deployment](deployment.md). Root-directory package placement,
overlapping destinations and unknown third-party installers remain outside the
automatic recovery boundary; normal Composer installers are the default path.
