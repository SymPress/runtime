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

Install the tagged release `0.1.0` and commit the resolved lockfile. The repository
is private, so use an authorized GitHub account or a repository-scoped read credential.
Version 0.1.0 is the initial release accepted for the documented SymPress layouts;
review release notes before changing the pinned version.

## Start here

For a new site, follow [Getting started](docs/getting-started.md). It includes a
complete Composer example, environment setup and the expected directory layout.
For an existing site, start with [Adopting Runtime](docs/migration.md).

Once Runtime is installed, run these commands from your project root:

The examples use the upcoming executable name `runtime`. With the tagged 0.1.0
release, use `sympress-runtime` as the executable name instead; see the
[CLI upgrade note](docs/cli.md).

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
| Prepare a production release | [Deployment](docs/deployment.md) |
| Diagnose a problem | [Troubleshooting](docs/troubleshooting.md) |

## Alternatives and existing projects

[WP Starter](https://github.com/wecodemore/wpstarter) is an alternative for
Composer-managed WordPress setup. Runtime retains an optional compatibility layer
for existing projects; its names, profiles and migration behavior are documented
separately in [Compatibility](docs/compatibility.md).

## Contributing and security

Read [CONTRIBUTING](CONTRIBUTING.md) for local development and verification, and
[SECURITY](SECURITY.md) to report a vulnerability privately. Internal evidence and
design decisions live in the [maintainer documentation](https://github.com/SymPress/runtime/blob/main/docs/maintainers/README.md).

Licensed under [GPL-2.0-or-later](LICENSE). Third-party attribution is in [NOTICE](NOTICE).
