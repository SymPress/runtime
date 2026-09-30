# Changelog

## Unreleased

Runtime is under active development; there is no stable release tag yet.

- Generate WordPress configuration, a front controller, MU-plugin loading and WP-CLI configuration from Composer project settings.
- Run setup after Composer install/update or through the standalone command.
- Configure environments with typed WordPress constants, local overrides, stable salts, runtime caches and private production dumps.
- Preserve edited configuration sections and provide custom steps, services, events and extension packages.
- Add optional kernel diagnostics, cache maintenance and explicit boot ownership.
- Support WPackagist, private and local packages through Composer installers, with bounded offline package placement after plugins are disabled.
- Preserve existing projects through optional compatibility profiles and a migration command.
- Load native MU plugins in path order, support symlinked loaders, emit WordPress loading hooks and list each plugin in the admin screen.
- Provide a complete example project, executable custom-step example, deployment guidance and source-checked option and constant references.

See the [acceptance record](https://github.com/SymPress/runtime/blob/main/docs/maintainers/acceptance.md)
for verification evidence and the boundaries of tested layouts.
