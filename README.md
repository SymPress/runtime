# SymPress Runtime

`sympress/runtime` will replace WP Starter across the SymPress ecosystem.

**Status: Phases 1-4 are available as stacked draft PRs; Phase 5 implements kernel integration and diagnostics. Phase 0 was approved on 2026-09-29. Full parity acceptance and consumer migration remain open.**

Requires PHP 8.5 and Symfony 8.1. The Composer plugin runs application code in a separate PHP process, with a standalone binary for installs performed using `--no-plugins`.

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

All inventoried functionality remains in scope, including features not used by the current starter or demo. No `wecodemore/*` package is required. The isolated differential job installs its pinned oracles separately. See [NOTICE](NOTICE) for upstream attribution.
