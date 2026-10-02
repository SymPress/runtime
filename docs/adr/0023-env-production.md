# ADR 0023: Production environment files and defaults

Status: implemented, 2026-09-30.

## Decision

Native runtime caching defaults to auto, checks parent writability before shutdown
registration, and invalidates on size/mtime changes to all attempted chain paths.
Missing paths are recorded so newly deployed overrides invalidate existing caches.
Interpolated process values use parser-produced substitution templates and salted
dependency fingerprints. Runtime cache hits validate those dependencies and
expand templates without Dotenv parsing or persisted process secret bytes.
Missing and empty dependencies remain distinct; changes invalidate the cache.
Dotenv retains control of quoting, escaping, defaults and file-chain resolution.
Deployment dumps and command substitutions involving process dependencies retain
their conservative refusal to persist derived process values.
Process-derived environment selectors decline runtime caching too, since the
selected name and attempted source paths appear in persistent metadata.
Deployment dumps remain explicit immutable artifacts. Legacy runtime cache
semantics remain unchanged.

Generated native bootstrap also checks ordinary cache producer identity against
its fingerprinted Runtime payload class. A normal setup/update therefore reloads
configured sources after a payload change, including caches created before
reader-local database observations were introduced. No status value is guessed
or selectively erased from old cache data. Explicit source flags are loaded
again. Format-1 readers remain compatible when no producer is requested, and
immutable deployment dumps retain their existing acceptance contract.

Cache, dump and generated PHP configuration writes share `SecureFileWriter`.
It checks writable and canonical parent paths, verifies the temporary file remains
in that parent before writing content, and remembers unwritable parents for the
process. Only mode 0600 or 0640 is accepted. This prevents secret content reaching
PHP's documented temporary-directory fallback:
[PHP tempnam documentation](https://www.php.net/manual/en/function.tempnam.php).

Native `_FILE` resolution preserves explicit base values, reads regular readable
files, strips one final LF and excludes resolved secret bytes from persistent
payloads. References survive dumps; reads observe rotation. Constants remain
request-local immutable PHP constants. Error messages never include secret paths
or values. Legacy profiles require migration before this feature applies.

`EnvRequirements` provides a shared value-free diagnostic contract for required
string, integer, boolean and finite float values. Empty and missing values fail;
zero and false are valid. CLI validation and deployment dump creation use it.

Composer-managed defaults apply automatically in native staging/production and
are separately editable in generated configuration. Existing constants always
win. Native HTTPS production enables admin SSL when no explicit value exists.
Compatibility profiles opt out by default.

Parser and filter objects are lazy. Cached typed values are reused only while the
raw value matches, preserving changing real-environment precedence. Optional
`bundle-bootstrap` embeds owned environment classes in bootstrap.php; third-party
parser dependencies retain lazy autoloading and independent source files.

## Limits and verification

Size/mtime tracking cannot detect edits preserving both attributes: deployment
must flush caches in that case. Group ownership is a deployment responsibility.
`ProductionEnvironmentTest` covers an actual unprivileged read-only directory,
permissions, new/changed/deleted overrides, immutable dumps, live file secrets,
required value validation and lazy parsing through the bundled bootstrap.
