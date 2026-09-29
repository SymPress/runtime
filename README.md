# SymPress Runtime

`sympress/runtime` will replace WP Starter across the SymPress ecosystem.

**Status: Phases 1-5 are available as stacked draft PRs. Phase 6 implements migration and compatibility diagnostics; full parity acceptance and consumer migration remain open. Phase 0 was approved on 2026-09-29.**

Requires PHP 8.5 and Symfony 8.1. The Composer plugin runs application code in a separate PHP process. The standalone binary also recovers standard WordPress package paths after `composer install --no-plugins`, before loading project code. WPackagist, private repositories and local Composer packages keep the regular installer workflow; dependency resolution and downloads stay with Composer. See [the package-layout boundaries](docs/adr/0019-standalone-wordpress-package-layout.md) and [the acceptance record](docs/acceptance.md).

```sh
composer install
vendor/bin/sympress-runtime validate
vendor/bin/sympress-runtime --list-steps
composer qa
```

All twelve inventoried setup steps are implemented, together with scripts/events, extension autoloading, legacy step adapters, environment dumps and cache flushing. Kernel maintenance, optional boot ownership and doctor/check support standalone, Composer and kernel-console workflows. Tests cover isolated Composer execution, independent generated configuration, real WordPress/WP-CLI/VCS fixtures and an actual compiled kernel on a read-only filesystem.

- [Parity acceptance matrix](docs/parity.md)
- [Source evidence and reproducibility](docs/source-evidence.md)
- [Architecture decisions](docs/adr/README.md)
- [Test and delivery plan](docs/test-plan.md)
- [Implementation and verification record](docs/phase1-progress.md)
- [Phase 1 review](docs/phase1-review.md)
- [Phase 2 progress](docs/phase2-progress.md)
- [Phase 3 review](docs/phase3-review.md)
- [Phase 4 review](docs/phase4-review.md)
- [Kernel integration](docs/kernel-integration.md)
- [Configuration](docs/configuration.md)
- [CLI](docs/cli.md)
- [Custom steps and services](docs/custom-steps.md)
- [Migration](docs/migration.md)
- [Phase 6 progress](docs/phase6-progress.md)

All inventoried functionality remains in scope, including features not used by the current starter or demo. No `wecodemore/*` package is required. The isolated differential job installs its pinned oracles separately. See [NOTICE](NOTICE) for upstream attribution.
