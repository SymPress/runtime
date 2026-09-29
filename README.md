# SymPress Runtime

`sympress/runtime` will replace WP Starter across the SymPress ecosystem.

**Status: Phase 1 implementation in progress. Phase 0 was approved on 2026-09-29. The foundation is executable; the complete WP Starter replacement is not ready for consumer migration.**

Requires PHP 8.5 and Symfony 8.1. The Composer plugin runs application code in a separate PHP process, with a standalone binary for installs performed using `--no-plugins`.

```sh
composer install
vendor/bin/sympress-runtime validate
vendor/bin/sympress-runtime --list-steps
composer qa
```

Built-in WordPress steps are implemented in subsequent phases. Listing identifies pending implementations; executing one fails explicitly. Custom test fixtures already exercise first installation, repeated installation, custom vendor/bin paths, Composer commands and standalone execution.

- [Parity acceptance matrix](docs/parity.md)
- [Source evidence and reproducibility](docs/source-evidence.md)
- [Architecture decisions](docs/adr/README.md)
- [Test and delivery plan](docs/test-plan.md)
- [Implementation and verification record](docs/phase1-progress.md)
- [Configuration](docs/configuration.md)
- [CLI](docs/cli.md)
- [Custom steps and services](docs/custom-steps.md)

All inventoried functionality remains in scope, including features not used by the current starter or demo. No `wecodemore/*` package is required. The isolated differential job will install its oracle separately. See [NOTICE](NOTICE) for upstream attribution.
