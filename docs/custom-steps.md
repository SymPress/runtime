# Custom steps and services

A custom step is useful when setup must do project-specific work: check a build
artifact, prepare a directory or register a service. It runs inside Runtime's
setup process and can use dependency injection.

## Write a small step

Save this class as `build-scripts/WriteBuildMarker.php` in your project. The
[complete example](../examples/WriteBuildMarker.php) is also included in this repository.

```php
<?php

declare(strict_types=1);

namespace Example\Build;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Filesystem\Filesystem;
use SymPress\Runtime\Filesystem\Paths;
use SymPress\Runtime\Step\FileCreationStepInterface;

final class WriteBuildMarker implements FileCreationStepInterface
{
    public function __construct(private readonly Filesystem $files) {}
    public function name(): string { return 'buildmarker'; }
    public function success(): string { return 'Build marker written.'; }
    public function error(): string { return 'Build marker could not be written.'; }
    public function allowed(Config $config, Paths $paths): bool { return true; }
    public function targetPath(Paths $paths): string { return $paths->root('var/build-marker.txt'); }

    public function run(Config $config, Paths $paths): int
    {
        return $this->files->writeContent("ready\n", $this->targetPath($paths))
            ? self::SUCCESS
            : self::ERROR;
    }
}
```

Merge these entries into your project manifest:

```json
{
  "autoload": {"psr-4": {"Example\\Build\\": "build-scripts/"}},
  "extra": {
    "sympress-runtime": {
      "command-steps": {"buildmarker": "Example\\Build\\WriteBuildMarker"}
    }
  }
}
```

Then regenerate autoloading and select the step:

```sh
composer dump-autoload
vendor/bin/runtime --list-steps
vendor/bin/runtime --no-interaction buildmarker
```

This writes `var/build-marker.txt`. Using `FileCreationStepInterface` lets the
runner apply overwrite protection to the declared target.

## Automatic or explicit-only?

`custom-steps` contributes work to a full setup run. `command-steps` registers
work that runs only when explicitly selected. Both accept class lists or
name-to-class maps. A class's `name()` must match its registration.

Every step implements `StepInterface`: `name()`, `success()`, `error()`,
`allowed(Config, Paths)` and `run(Config, Paths)`. Return flags are `ERROR=1`,
`SUCCESS=2` and `NONE=4`. A blocking step stops later work on pure ERROR;
combined SUCCESS|ERROR continues but still fails the command.

Optional interfaces support file targets, confirmation, conditional skip messages,
blocking and postprocessing. Eligibility is checked in this order: `allowed`,
overwrite policy, optional confirmation. Native explicit selection bypasses
optional confirmation. Postprocessors run once after selected work.

## Configure dependency injection

Project Composer autoloading or `sympress-runtime-autoload.php` makes classes
available during setup. The latter is a run-only file; static validation does
not execute it. It may return a container configurator:

```php
<?php

use Example\Build\WriteBuildMarker;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $container): void {
    $container->register(WriteBuildMarker::class)
        ->setAutowired(true)
        ->addTag('sympress.runtime.step', [
            'name' => 'buildmarker',
            'priority' => 20,
            'command-only' => true,
        ]);
};
```

Choose one registration method for a step; duplicate names fail. An autoconfigured
service can instead use `#[AsRuntimeStep(name: 'buildmarker', priority: 20)]`.
Higher priorities run earlier, ties preserve registration order, and explicit CLI
selection follows the requested order with WP-CLI last.

The setup container is separate from your application's kernel container. Live
Composer objects cannot cross that process boundary.

## Services available to steps

Prefer constructor injection for the services you use. `SymPress\Runtime\Services`
also exposes the shared services through these accessors:

| Purpose | Accessors |
| --- | --- |
| Settings and paths | `config`, `paths` |
| Console | `io` |
| Files and configuration | `filesystem`, `wpConfigSectionEditor` |
| Environment and database | `env`, `dbChecker` |
| Downloads | `urlDownloader` |
| Processes | `systemProcess`, `wpCliProcess`, `phpToolProcessFactory` |

Only the methods listed in the [public PHP API](api.md) are stable. Concrete
service handles retain internal methods for Runtime and legacy compatibility;
constructor injection does not expose those methods as extension API. The other
`Services` accessors, including the `composer*` aliases, are internal.
Environment parsing and database checks are lazy. `dbChecker()->status()`
distinguishes unknown state from a confirmed missing database; a boolean accessor
is true only for a confirmed result.

`Config` is append-only ArrayAccess whose values are `Result` objects.
`unwrap()` throws a stored error; `unwrapOrFallback()` falls back for null/error.
False, zero, empty strings and empty arrays still count as present for `notEmpty()`.
Comparisons are strict. Only new, null or still-default settings can be replaced.
Use `appendValidator()` for your extension's new keys; built-in validators cannot
be replaced.

Use argv arrays for process arguments. The explicit string overload is a trusted
shell API: do not construct it from request or remote input. Capturing process
execution returns `[stdout, stderr, success, throwable]`.

For a custom executable PHP tool, implement the public `PhpTool` interface and
pass its descriptor to `$services->phpToolProcessFactory()->create($tool)`.
The returned process supports `execute`, `executeSilently` and `withEnvironment`.
The factory prefers an installed Composer package, then a readable local PHAR,
then a verified permitted download. Its optional second argument selects the PHP
executable. Inject or obtain the factory; constructing its internal dependencies
is not part of the extension contract.

## Callbacks and events

Configure `pre-<step>` / `post-<step>` or
`pre-sympress-runtime` / `post-sympress-runtime` in `scripts`. A value may be a
function/static-method string or a list of callbacks, including nested
`["ClassName", "method"]` pairs. Programmatic lists can include closures or
callable objects.

Callbacks should declare `(int $result, StepInterface|Runner $subject, Services $services)`.
A fourth internal `RunContext` argument is still passed for legacy compatibility;
new extensions should not depend on it. Pre callbacks receive NONE, post callbacks receive the
actual result. Exceptions fail the command without exposing exception contents;
later callbacks still run.

Return `ScriptHaltSignal::stopPropagation($reason)`, `haltStep($reason)` or
`haltStepContinuePropagation($reason)` to control pre callbacks. A whole-run pre
callback may add/remove steps; per-step queue changes are ignored. Skipped steps
emit no step callbacks. Post-run results aggregate failures.

Symfony subscribers can listen to `PreRunEvent`, `PostRunEvent`,
`PreStepEvent` and `PostStepEvent` in `SymPress\Runtime\Event`.
Register subscribers through autoconfiguration or the runner's
`EventDispatcherInterface`. Script subscribers have priority 0.

## Publish an extension package

Use package type `sympress-runtime-extension`. Ordinary Composer-autoloadable
classes can be contributed through `extra.sympress-runtime.steps`. For classes
needed only during setup, supply `extra.sympress-runtime-autoload` with
`psr-4` and `files` entries. Paths are relative to the installed package.
Root setup autoload runs before package files; a file may return a container
configurator. Missing files are skipped, and malformed native metadata fails validation.

Setup-only classes do not become part of the application's normal autoloader.
Older extension types and API aliases are covered in [Compatibility](compatibility.md).

## Override a template

Set `templates-dir` or call `Paths::useCustomTemplatesDir()`. The first directory
containing a requested template wins; bundled templates are the fallback.
Triple-brace placeholders ignore case and surrounding whitespace. Unknown or
nonscalar placeholders remain unchanged. Literal dollars and backslashes are retained;
empty or unreadable templates fail.

Use the [section editor](wp-config.md) when you only need to change a small part of
generated configuration. A custom template owns its bootstrap behavior and needs
your own WordPress and WP-CLI verification.
