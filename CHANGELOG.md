# Changelog

## Unreleased

- Approved source-derived WP Starter parity inventory and architecture decisions.
- Implement isolated Composer and standalone setup, all twelve default steps, configuration validation, extension services/events, legacy API adapters and migration diagnostics.
- Generate WordPress configuration with an independent environment-parser payload, stable salts, managed sections, environment caches and production dumps.
- Add kernel diagnostics, cache maintenance and optional boot ownership.
- Preserve WPackagist/private/path package installation and bounded offline recovery after Composer plugins are disabled.
- Preserve correctly installed root and nested layouts, and replace URL-dropin symlinks only after download verification.
- Restore early Composer autoload through `wp-config-autoload` (enabled by default in legacy profiles), and bound service downloads through `download-max-bytes`.
- Honor disabled compatibility for legacy environment sentinels and constant mappings, including cached and dumped environments.
- Provide deployment and user guides, explicit upstream interface attribution, and a CI suite requiring real database/WordPress/WP-CLI fixtures with no skipped tests.
- Add real Composer fixture tests, pinned differential comparisons and PHP 8.5 / PHPUnit 13 quality gates.

Unreleased work remains subject to the acceptance record; passing a fixture suite does not establish compatibility with every third-party extension or installer layout.
