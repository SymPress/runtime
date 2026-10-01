# ADR 0022: Stable extension contract and Runtime command surfaces

Status: accepted for the 1.0 candidate.

Runtime 1.x supports its documented configuration, generated-file and extension
contracts. PHP visibility alone is too broad: services currently return concrete
implementation classes with construction and orchestration methods that extensions
should not call. We retain these classes for source compatibility and mark only
the supported members `@api`. Other members remain `@internal`. Reflection-based
signature inventory and executable extension examples enforce the boundary.

Configuration schema/default snapshots include all 56 existing keys and all
profiles. Internal invocation keys remain runtime-provided state. The optional
compatibility layer is deprecated before 1.0, supported throughout 1.x and planned
for removal in 2.0. See the [compatibility policy](../compatibility-policy.md).

`runtime` is the command name for the binary, WP-CLI and Composer alias. Native
WP-CLI configuration registers a small standard-library-only bridge before
WordPress loads. It launches the existing binary using an argument array, inherits
the environment and standard streams, uses the configured project root, and
returns the child's exit status. It never loads project Symfony classes into
WP-CLI's process. The bridge runs noninteractively and normalizes WP-CLI's JSON
option rewrite. WP-CLI retains its global flags and startup diagnostics.

Legacy-profile YAML and existing user-owned YAML retain their behavior. They can
opt in through the documented registration entry point. Composer's original
command names remain available alongside aliases.

The generated native YAML now contains a registration statement. Existing setup
baselines must be regenerated after reviewing this change. Real WP-CLI integration
tests compare command output and exit status with direct binary execution,
including invocation from a project subdirectory and a missing WordPress install.

The public package uses stable Kernel/QA versions and no local path repository.
Composer's library constraint is `^2.10.3`, covered by minimum/latest integration
checks. Platform contracts distinguish portable behavior from Linux-only fixtures.
The lowest-dependency run exposed PHP 8.5 deprecations in older event-dispatcher
contracts, contaminating command/JSON output. We set the supported contracts
floor to 3.4.2, whose interface uses explicit nullable types, instead of suppressing
those diagnostics. Lowest-version testing holds QA tools at current stable
versions while lowering production dependencies and Symfony components.
