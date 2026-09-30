# SymPress Runtime

**Set up a Composer-managed WordPress site and configure it for each environment.**

Runtime generates `wp-config.php`, the front controller, the MU-plugin loader and
WP-CLI configuration from your project's settings. It reads environment variables,
publishes project plugins and themes, and runs your setup steps after Composer
install or update. The same setup is available as a standalone command.

[![QA](https://github.com/SymPress/runtime/actions/workflows/qa.yml/badge.svg)](https://github.com/SymPress/runtime/actions/workflows/qa.yml)

## Why use it?

- Keep WordPress core, plugins, themes and PHP libraries in one Composer project.
- Use different database credentials and settings in development, staging and production.
- Regenerate configuration without losing salts or your managed PHP sections.
- Add project-specific setup with dependency-injected steps and lifecycle events.
- Build private environment dumps and deploy a runtime that can operate on a read-only filesystem.
- Use WPackagist, private packages and local packages with their normal Composer installers.

Runtime does not require the SymPress kernel. If your application uses it, the
[kernel integration](docs/kernel-integration.md) adds diagnostics, environment dumps
and explicit cache maintenance to `wp console`.

## Requirements

PHP **8.5 or newer**, Composer **2.10.3 or newer** with plugin API **2.6 or newer**,
and a Composer-managed WordPress installation. Composer resolves Runtime's Symfony
8.1 dependencies. WordPress and your application may require additional PHP extensions.

PHP 8.5 is the intentional baseline for the 1.x contract. PHP 8.4 and older are
not supported. Install a tagged release and commit the resolved lockfile. The
package is available from [Packagist](https://packagist.org/packages/sympress/runtime);
no Runtime-specific repository or read credentials are required.
Use `sympress/runtime:^1.0` for the stable 1.x line. Review the
[1.0 upgrade notes](docs/releases/1.0.0.md) before updating an existing site.

The [compatibility policy](docs/compatibility-policy.md) defines the effective 1.x
contract for the public PHP API, configuration, commands and persisted formats.
Breaking a supported interface requires 2.0. See the [platform table](docs/platforms.md)
for requirements and verification scope. The owner waived the production and
independent migration trials; no completed production soak is claimed.

## Start here

For a new site, follow [Getting started](docs/getting-started.md). It includes a
complete Composer example, environment setup and the expected directory layout.
For an existing site, start with [Adopting Runtime](docs/migration.md).

Once Runtime is installed, run these commands from your project root:

The executable is `runtime`, as introduced in 0.2.0. When upgrading from 0.1.0,
update shell and CI scripts to use this name; see [Command line](docs/cli.md).

```sh
vendor/bin/runtime validate
vendor/bin/runtime --list-steps
vendor/bin/runtime --no-interaction
vendor/bin/runtime doctor
```

Composer install and update already run setup when the plugin is enabled. The
standalone command is useful for a deliberate rerun or a build with plugins disabled.

## Documentation

| I want to… | Read |
| --- | --- |
| Understand how the pieces fit together | [Overview](docs/README.md) |
| Create a site | [Getting started](docs/getting-started.md) |
| Change a setting | [Configuration](docs/configuration.md) · [Settings cheat sheet](docs/settings-cheat-sheet.md) |
| Configure WordPress for an environment | [Environment](docs/environment.md) · [Constants reference](docs/constants.md) |
| Customize generated PHP | [wp-config and sections](docs/wp-config.md) |
| Choose what runs during setup | [Steps](docs/steps.md) · [Command line](docs/cli.md) |
| Add project automation | [Custom steps and services](docs/custom-steps.md) · [WP-CLI](docs/wp-cli.md) |
| Build a supported extension | [Public API](docs/api.md) · [Compatibility policy](docs/compatibility-policy.md) |
| Prepare a production release | [Deployment](docs/deployment.md) |
| Diagnose a problem | [Troubleshooting](docs/troubleshooting.md) |

## Alternatives and existing projects

[WP Starter](https://github.com/wecodemore/wpstarter) is an alternative for
Composer-managed WordPress setup. Runtime retains an optional compatibility layer
for existing projects; its names, profiles and migration behavior are documented
separately in [Compatibility](docs/compatibility.md). The layer is deprecated,
supported throughout 1.x and scheduled for removal in 2.0. The
[parity matrix](https://github.com/SymPress/runtime/blob/main/docs/maintainers/parity.md) records the compatibility comparison.

## Contributing and security

Read [CONTRIBUTING](CONTRIBUTING.md) for local development and verification, and
[SECURITY](SECURITY.md) to report a vulnerability privately. Internal evidence and
design decisions live in the [maintainer documentation](https://github.com/SymPress/runtime/blob/main/docs/maintainers/README.md).

Licensed under [GPL-2.0-or-later](LICENSE). Third-party attribution is in [NOTICE](NOTICE).
