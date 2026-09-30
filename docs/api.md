# Public PHP API

The annotations in `src/` and the checked member inventory below define Runtime's
extension contract. The [compatibility policy](compatibility-policy.md) describes
versioning and the [custom step guide](custom-steps.md) shows how to use it.

## Reading the annotations

- A class or interface marked `@api` is a supported extension type. Only its
  explicitly `@api` public methods are stable. Public constants and properties on
  that type are stable unless individually marked `@internal`.
- An `@internal` class with selected `@api` methods is a **service handle**. Its
  name may be used in type declarations, dependency injection and return types;
  only the annotated methods and constants are public API. This does not expose
  the remaining methods, constructor, inheritance or implementation details.
- An `@internal` method remains internal even on an `@api` type. PHP visibility
  is preserved for source compatibility and is not a stability promise.
- Constructors are internal unless listed. Obtain `Services`, `Config`, lifecycle
  events and service handles from Runtime's dependency injection or callbacks.
  `Paths`, `AsRuntimeStep` and `Question` have public constructors.

This keeps existing legacy code callable without promising all implementation
classes as a permanent API. Legacy compatibility is separately deprecated for
removal in 2.0; see [Compatibility](compatibility.md).

## Stable extension types

`StepInterface` and its five variants define steps and result flags.
`AsRuntimeStep` defines autoconfiguration metadata; `ScriptHaltSignal` controls
script propagation and pre-step halting. `Config` provides append-only settings
and custom validators; `Result` preserves lazy values, strict comparisons and
error/fallback behavior. `Paths` resolves project paths and template overrides.
`PhpTool` describes executable PHP tools. Obtain `phpToolProcessFactory()` from
`Services` and call `create($descriptor, $phpPath = null)` to resolve a tool from
its installed package, local PHAR or verified download and execute it through the
returned `PhpToolProcess`. Factory construction and resolution internals remain
internal.

Lifecycle subscribers receive `PreRunEvent`, `PostRunEvent`, `PreStepEvent` or
`PostStepEvent` through `LifecycleEvent`. Readonly `result`, `subject` and
`services` properties are public. `isPre`, `haltStep`, `isStepHalted`, `reason`
and `failed` are public; construction, `applySignal` and `fail` belong to the
runner. Symfony's inherited `stopPropagation`/`isPropagationStopped` follow the
Symfony event contract rather than adding Runtime-owned methods to this inventory.
The `Event\Lifecycle` dispatcher is internal.

A whole-run subject is the internal `Runner` handle. `name`, `count`, `addStep`
and `removeStep` remain supported for lifecycle callbacks; queue changes are
accepted before a run, ignored during individual steps, and WP-CLI remains last.
Constructing a runner or invoking `run` is internal.

`DbStatus` is the returned immutable status value: `envValid`, `exists`,
`installed` and `reason` are public, with nullable booleans preserving unknown
state. Obtain it through `dbChecker()->status()`.

## Services and narrow handles

| `Services` accessor | Handle and supported operations |
| --- | --- |
| `config`, `paths` | Public `Config` and `Paths` types |
| `io` | `Io`: writing messages, verbosity/interactivity and questions |
| `filesystem` | `Filesystem`: file/directory writes, copies, moves, links and removal |
| `env` | `EnvReader`: reading/writing values and determining environment/setup state |
| `urlDownloader` | `UrlDownloader`: verified fetch/save, artifact verification and error text |
| `systemProcess` | `SystemProcess`: execution, capture and environment overrides |
| `phpToolProcessFactory` | `PhpToolProcessFactory`: create an executable process from a custom `PhpTool` |
| `wpCliProcess` | `PhpToolProcess`: execution and environment overrides |
| `dbChecker` | `DbChecker`: status predicates, status value, check and explicit mysqlcheck |
| `wpConfigSectionEditor` | `WpConfigSectionEditor`: read, append, prepend, replace and delete sections |

The exact methods are listed below. All other `Services` accessors are internal,
including legacy `composer*` aliases. Constructors
on service handles are internal, except `Question`, whose constructor and
`newWithValidator` factory create values for `Io::ask`. Its rendering and answer
filtering methods remain internal.

The PHP parameter and return types in source are part of each listed method's
contract. Use argument arrays for processes; the string overload is a trusted
shell API. Downloads retain configured integrity checks and lock enforcement.
Lifecycle callbacks should declare only `(int $result, StepInterface|Runner
$subject, Services $services)`. The fourth `RunContext` argument is still passed
for legacy compatibility, but is internal and not part of the stable extension API.

## Enforcement

`PublicApiTest` checks every source class/interface/trait, explicit method
classification, transitive parameter/return/property types, and this inventory.
It parses all PHP examples, `tests/Extension/`, and the existing executable step,
tool and lifecycle fixtures. The audit follows typed parameters, properties,
local aliases, typed helper-function returns, coalesced/conditional receivers,
return chains, constants, constructors and dynamic method/callable names.
Regression probes verify that internal methods remain rejected even on public
service handles. Keep extension consumer tests in `tests/Extension/`; internal
runner/setup test harnesses stay in `tests/Contract/`.

## Member inventory

This list is checked against reflection, including parameter names, types, defaults,
return types, inherited Runtime methods and constants. A `service handle` entry permits type references, not construction or
unlisted methods. The inventory deliberately contains no environment values.

<!-- api-inventory -->
```text
SymPress\Runtime\Config\Config [public type]
SymPress\Runtime\Config\Config::AUTOLOAD: string = "autoload"
SymPress\Runtime\Config\Config::CACHE_ENV: string = "cache-env"
SymPress\Runtime\Config\Config::CHECK_VCS_IGNORE: string = "check-vcs-ignore"
SymPress\Runtime\Config\Config::COMMAND_STEPS: string = "command-steps"
SymPress\Runtime\Config\Config::COMPOSER_UPDATED_PACKAGES: string = "composer-updated-packages"
SymPress\Runtime\Config\Config::CONTENT_DEV_DIR: string = "content-dev-dir"
SymPress\Runtime\Config\Config::CONTENT_DEV_OPERATION: string = "content-dev-op"
SymPress\Runtime\Config\Config::CREATE_VCS_IGNORE_FILE: string = "create-vcs-ignore-file"
SymPress\Runtime\Config\Config::CUSTOM_STEPS: string = "custom-steps"
SymPress\Runtime\Config\Config::DB_CHECK: string = "db-check"
SymPress\Runtime\Config\Config::DROPINS: string = "dropins"
SymPress\Runtime\Config\Config::DROPINS_OPERATION: string = "dropins-op"
SymPress\Runtime\Config\Config::EARLY_HOOKS_FILE: string = "early-hook-file"
SymPress\Runtime\Config\Config::ENV_BOOTSTRAP_DIR: string = "env-bootstrap-dir"
SymPress\Runtime\Config\Config::ENV_DIR: string = "env-dir"
SymPress\Runtime\Config\Config::ENV_EXAMPLE: string = "env-example"
SymPress\Runtime\Config\Config::ENV_FILE: string = "env-file"
SymPress\Runtime\Config\Config::INSTALL_WP_CLI: string = "install-wp-cli"
SymPress\Runtime\Config\Config::IS_COMPOSER_INSTALL: string = "is-composer-install"
SymPress\Runtime\Config\Config::IS_COMPOSER_UPDATE: string = "is-composer-update"
SymPress\Runtime\Config\Config::IS_WPSTARTER_COMMAND: string = "is-wpstarter-command"
SymPress\Runtime\Config\Config::IS_WPSTARTER_SELECTED_COMMAND: string = "is-wpstarter-selected-command"
SymPress\Runtime\Config\Config::MOVE_CONTENT: string = "move-content"
SymPress\Runtime\Config\Config::PREVENT_OVERWRITE: string = "prevent-overwrite"
SymPress\Runtime\Config\Config::REGISTER_THEME_FOLDER: string = "register-theme-folder"
SymPress\Runtime\Config\Config::REQUIRE_WP: string = "require-wp"
SymPress\Runtime\Config\Config::SCRIPTS: string = "scripts"
SymPress\Runtime\Config\Config::SKIP_DB_CHECK: string = "skip-db-check"
SymPress\Runtime\Config\Config::SKIP_STEPS: string = "skip-steps"
SymPress\Runtime\Config\Config::TEMPLATES_DIR: string = "templates-dir"
SymPress\Runtime\Config\Config::WP_CLI_COMMANDS: string = "wp-cli-commands"
SymPress\Runtime\Config\Config::WP_CLI_FILES: string = "wp-cli-files"
SymPress\Runtime\Config\Config::WP_CONFIG_PATH: string = "wp-config-php-path"
SymPress\Runtime\Config\Config::WP_VERSION: string = "wp-version"
SymPress\Runtime\Config\Config::appendValidator(string $name, callable $callback): SymPress\Runtime\Config\Config
SymPress\Runtime\Config\Config::errors(): array
SymPress\Runtime\Config\Config::offsetExists(mixed $offset): bool
SymPress\Runtime\Config\Config::offsetGet(mixed $offset): SymPress\Runtime\Config\Result
SymPress\Runtime\Config\Config::offsetSet(mixed $offset, mixed $value): void
SymPress\Runtime\Config\Config::offsetUnset(mixed $offset): void
SymPress\Runtime\Config\Result [public type]
SymPress\Runtime\Config\Result::either(mixed $thing, mixed ...$things): bool
SymPress\Runtime\Config\Result::error(?Throwable $error = NULL): SymPress\Runtime\Config\Result [static]
SymPress\Runtime\Config\Result::errored(string $message): SymPress\Runtime\Config\Result [static]
SymPress\Runtime\Config\Result::is(mixed $compare): bool
SymPress\Runtime\Config\Result::none(): SymPress\Runtime\Config\Result [static]
SymPress\Runtime\Config\Result::not(mixed $compare): bool
SymPress\Runtime\Config\Result::notEmpty(): bool
SymPress\Runtime\Config\Result::ok(mixed $value): SymPress\Runtime\Config\Result [static]
SymPress\Runtime\Config\Result::promise(callable $provider): SymPress\Runtime\Config\Result [static]
SymPress\Runtime\Config\Result::unwrap(): mixed
SymPress\Runtime\Config\Result::unwrapOrFallback(mixed $fallback = NULL): mixed
SymPress\Runtime\Console\Io [service handle]
SymPress\Runtime\Console\Io::ask(SymPress\Runtime\Console\Question $question): ?string
SymPress\Runtime\Console\Io::askConfirm(array|string $question, bool $default = true): bool
SymPress\Runtime\Console\Io::comment(string $message): void
SymPress\Runtime\Console\Io::error(string $message): void
SymPress\Runtime\Console\Io::isInteractive(): bool
SymPress\Runtime\Console\Io::isVerbose(): bool
SymPress\Runtime\Console\Io::success(string $message): void
SymPress\Runtime\Console\Io::verbose(string $message): void
SymPress\Runtime\Console\Io::write(string $message): void
SymPress\Runtime\Console\Question [service handle]
SymPress\Runtime\Console\Question::__construct(array $lines, array $answers = [], ?string $default = NULL)
SymPress\Runtime\Console\Question::newWithValidator(array $lines, callable $validator, ?string $default = NULL): SymPress\Runtime\Console\Question [static]
SymPress\Runtime\Database\DbChecker [service handle]
SymPress\Runtime\Database\DbChecker::HEALTH_CHECK: string = "health"
SymPress\Runtime\Database\DbChecker::WPDB_ENV_VALID: string = "WPDB_ENV_VALID"
SymPress\Runtime\Database\DbChecker::WPDB_EXISTS: string = "WPDB_EXISTS"
SymPress\Runtime\Database\DbChecker::WP_INSTALLED: string = "WP_INSTALLED"
SymPress\Runtime\Database\DbChecker::check(): void
SymPress\Runtime\Database\DbChecker::dbExists(): bool
SymPress\Runtime\Database\DbChecker::isEnvValid(): bool
SymPress\Runtime\Database\DbChecker::isInstalled(): bool
SymPress\Runtime\Database\DbChecker::mysqlcheck(bool $quick = false): bool
SymPress\Runtime\Database\DbChecker::status(): SymPress\Runtime\Database\DbStatus
SymPress\Runtime\Database\DbStatus [public type]
SymPress\Runtime\Database\DbStatus::$envValid: bool [readonly]
SymPress\Runtime\Database\DbStatus::$exists: ?bool [readonly]
SymPress\Runtime\Database\DbStatus::$installed: ?bool [readonly]
SymPress\Runtime\Database\DbStatus::$reason: string [readonly]
SymPress\Runtime\Download\UrlDownloader [service handle]
SymPress\Runtime\Download\UrlDownloader::error(): string
SymPress\Runtime\Download\UrlDownloader::fetch(string $url, bool $pin = true): string
SymPress\Runtime\Download\UrlDownloader::save(string $url, string $filename, ?callable $verify = NULL, ?string $artifact = NULL): bool
SymPress\Runtime\Download\UrlDownloader::verifyArtifact(string $artifact, string $filename): bool
SymPress\Runtime\Env\EnvReader [service handle]
SymPress\Runtime\Env\EnvReader::determineEnvType(): string
SymPress\Runtime\Env\EnvReader::has(string $name): bool
SymPress\Runtime\Env\EnvReader::isWpSetup(): bool
SymPress\Runtime\Env\EnvReader::rawValue(string $name): ?string
SymPress\Runtime\Env\EnvReader::read(string $name): string|int|float|bool|null
SymPress\Runtime\Env\EnvReader::readMany(string ...$names): array
SymPress\Runtime\Env\EnvReader::write(string $name, string $value): void
SymPress\Runtime\Event\LifecycleEvent [public type]
SymPress\Runtime\Event\LifecycleEvent::$result: int [readonly]
SymPress\Runtime\Event\LifecycleEvent::$services: SymPress\Runtime\Services [readonly]
SymPress\Runtime\Event\LifecycleEvent::$subject: SymPress\Runtime\Step\Runner|SymPress\Runtime\Step\StepInterface [readonly]
SymPress\Runtime\Event\LifecycleEvent::failed(): bool
SymPress\Runtime\Event\LifecycleEvent::haltStep(string $reason, bool $continuePropagation = false): void
SymPress\Runtime\Event\LifecycleEvent::isPre(): bool
SymPress\Runtime\Event\LifecycleEvent::isStepHalted(): bool
SymPress\Runtime\Event\LifecycleEvent::reason(): string
SymPress\Runtime\Event\PostRunEvent [public type]
SymPress\Runtime\Event\PostRunEvent::$result: int [readonly]
SymPress\Runtime\Event\PostRunEvent::$services: SymPress\Runtime\Services [readonly]
SymPress\Runtime\Event\PostRunEvent::$subject: SymPress\Runtime\Step\Runner|SymPress\Runtime\Step\StepInterface [readonly]
SymPress\Runtime\Event\PostRunEvent::failed(): bool
SymPress\Runtime\Event\PostRunEvent::haltStep(string $reason, bool $continuePropagation = false): void
SymPress\Runtime\Event\PostRunEvent::isPre(): bool
SymPress\Runtime\Event\PostRunEvent::isStepHalted(): bool
SymPress\Runtime\Event\PostRunEvent::reason(): string
SymPress\Runtime\Event\PostStepEvent [public type]
SymPress\Runtime\Event\PostStepEvent::$result: int [readonly]
SymPress\Runtime\Event\PostStepEvent::$services: SymPress\Runtime\Services [readonly]
SymPress\Runtime\Event\PostStepEvent::$subject: SymPress\Runtime\Step\Runner|SymPress\Runtime\Step\StepInterface [readonly]
SymPress\Runtime\Event\PostStepEvent::failed(): bool
SymPress\Runtime\Event\PostStepEvent::haltStep(string $reason, bool $continuePropagation = false): void
SymPress\Runtime\Event\PostStepEvent::isPre(): bool
SymPress\Runtime\Event\PostStepEvent::isStepHalted(): bool
SymPress\Runtime\Event\PostStepEvent::reason(): string
SymPress\Runtime\Event\PreRunEvent [public type]
SymPress\Runtime\Event\PreRunEvent::$result: int [readonly]
SymPress\Runtime\Event\PreRunEvent::$services: SymPress\Runtime\Services [readonly]
SymPress\Runtime\Event\PreRunEvent::$subject: SymPress\Runtime\Step\Runner|SymPress\Runtime\Step\StepInterface [readonly]
SymPress\Runtime\Event\PreRunEvent::failed(): bool
SymPress\Runtime\Event\PreRunEvent::haltStep(string $reason, bool $continuePropagation = false): void
SymPress\Runtime\Event\PreRunEvent::isPre(): bool
SymPress\Runtime\Event\PreRunEvent::isStepHalted(): bool
SymPress\Runtime\Event\PreRunEvent::reason(): string
SymPress\Runtime\Event\PreStepEvent [public type]
SymPress\Runtime\Event\PreStepEvent::$result: int [readonly]
SymPress\Runtime\Event\PreStepEvent::$services: SymPress\Runtime\Services [readonly]
SymPress\Runtime\Event\PreStepEvent::$subject: SymPress\Runtime\Step\Runner|SymPress\Runtime\Step\StepInterface [readonly]
SymPress\Runtime\Event\PreStepEvent::failed(): bool
SymPress\Runtime\Event\PreStepEvent::haltStep(string $reason, bool $continuePropagation = false): void
SymPress\Runtime\Event\PreStepEvent::isPre(): bool
SymPress\Runtime\Event\PreStepEvent::isStepHalted(): bool
SymPress\Runtime\Event\PreStepEvent::reason(): string
SymPress\Runtime\Filesystem\Filesystem [service handle]
SymPress\Runtime\Filesystem\Filesystem::OPERATIONS: array = ["auto","copy","symlink","none"]
SymPress\Runtime\Filesystem\Filesystem::OP_AUTO: string = "auto"
SymPress\Runtime\Filesystem\Filesystem::OP_COPY: string = "copy"
SymPress\Runtime\Filesystem\Filesystem::OP_NONE: string = "none"
SymPress\Runtime\Filesystem\Filesystem::OP_SYMLINK: string = "symlink"
SymPress\Runtime\Filesystem\Filesystem::copyDir(string $sourcePath, string $targetPath): bool
SymPress\Runtime\Filesystem\Filesystem::copyFile(string $sourcePath, string $targetPath): bool
SymPress\Runtime\Filesystem\Filesystem::createDir(string $targetPath): bool
SymPress\Runtime\Filesystem\Filesystem::findShortestPath(string $from, string $to, bool $directories = false, bool $preferRelative = false): string
SymPress\Runtime\Filesystem\Filesystem::isLink(string $path): bool
SymPress\Runtime\Filesystem\Filesystem::moveDir(string $sourcePath, string $targetPath): bool
SymPress\Runtime\Filesystem\Filesystem::moveFile(string $sourcePath, string $targetPath): bool
SymPress\Runtime\Filesystem\Filesystem::removeRealDir(string $directory): bool
SymPress\Runtime\Filesystem\Filesystem::save(string $content, string $targetPath): bool
SymPress\Runtime\Filesystem\Filesystem::symlink(string $targetPath, string $linkPath): bool
SymPress\Runtime\Filesystem\Filesystem::symlinkOrCopy(string $sourcePath, string $targetPath): bool
SymPress\Runtime\Filesystem\Filesystem::symlinkOrCopyOperation(string $source, string $target, string $operation): bool
SymPress\Runtime\Filesystem\Filesystem::unlinkOrRemove(string $path): bool
SymPress\Runtime\Filesystem\Filesystem::writeContent(string $content, string $targetPath): bool
SymPress\Runtime\Filesystem\Paths [public type]
SymPress\Runtime\Filesystem\Paths::BIN: string = "bin"
SymPress\Runtime\Filesystem\Paths::ROOT: string = "root"
SymPress\Runtime\Filesystem\Paths::VENDOR: string = "vendor"
SymPress\Runtime\Filesystem\Paths::WP: string = "wp"
SymPress\Runtime\Filesystem\Paths::WP_CONTENT: string = "wp-content"
SymPress\Runtime\Filesystem\Paths::WP_PARENT: string = "wp-parent"
SymPress\Runtime\Filesystem\Paths::WP_STARTER: string = "wp-starter"
SymPress\Runtime\Filesystem\Paths::__construct(string $root, string $vendor = 'vendor', ?string $bin = NULL, string $wp = 'wordpress', string $content = 'wp-content')
SymPress\Runtime\Filesystem\Paths::absolute(string $pathName, string $to = ''): string
SymPress\Runtime\Filesystem\Paths::bin(string $to = ''): string
SymPress\Runtime\Filesystem\Paths::offsetExists(mixed $offset): bool
SymPress\Runtime\Filesystem\Paths::offsetGet(mixed $offset): string
SymPress\Runtime\Filesystem\Paths::offsetSet(mixed $offset, mixed $value): void
SymPress\Runtime\Filesystem\Paths::offsetUnset(mixed $offset): void
SymPress\Runtime\Filesystem\Paths::relativeToRoot(string $pathName, string $to = ''): string
SymPress\Runtime\Filesystem\Paths::root(string $to = ''): string
SymPress\Runtime\Filesystem\Paths::template(string $filename): string
SymPress\Runtime\Filesystem\Paths::useCustomTemplatesDir(string $directory): void
SymPress\Runtime\Filesystem\Paths::vendor(string $to = ''): string
SymPress\Runtime\Filesystem\Paths::wp(string $to = ''): string
SymPress\Runtime\Filesystem\Paths::wpContent(string $to = ''): string
SymPress\Runtime\Filesystem\Paths::wpParent(string $to = ''): string
SymPress\Runtime\Generation\WpConfigSectionEditor [service handle]
SymPress\Runtime\Generation\WpConfigSectionEditor::append(string $section, string $newContent): void
SymPress\Runtime\Generation\WpConfigSectionEditor::delete(string $section): void
SymPress\Runtime\Generation\WpConfigSectionEditor::prepend(string $section, string $newContent): void
SymPress\Runtime\Generation\WpConfigSectionEditor::replace(string $section, string $newContent): void
SymPress\Runtime\Generation\WpConfigSectionEditor::sectionContent(string $section): string
SymPress\Runtime\Process\PhpTool [public type]
SymPress\Runtime\Process\PhpTool::checkPhar(string $pharPath, SymPress\Runtime\Console\Io $io): bool
SymPress\Runtime\Process\PhpTool::filesystemBootstrap(string $packageVendorPath): string
SymPress\Runtime\Process\PhpTool::minVersion(): string
SymPress\Runtime\Process\PhpTool::niceName(): string
SymPress\Runtime\Process\PhpTool::packageName(): string
SymPress\Runtime\Process\PhpTool::pharTarget(SymPress\Runtime\Filesystem\Paths $paths): string
SymPress\Runtime\Process\PhpTool::pharUrl(): string
SymPress\Runtime\Process\PhpTool::prepareCommand(string $command, SymPress\Runtime\Filesystem\Paths $paths, SymPress\Runtime\Console\Io $io): string
SymPress\Runtime\Process\PhpToolProcess [service handle]
SymPress\Runtime\Process\PhpToolProcess::execute(array|string $command): bool
SymPress\Runtime\Process\PhpToolProcess::executeSilently(array|string $command, ?string $cwd = NULL): bool
SymPress\Runtime\Process\PhpToolProcess::withEnvironment(array $environment): SymPress\Runtime\Process\PhpToolProcess
SymPress\Runtime\Process\PhpToolProcessFactory [service handle]
SymPress\Runtime\Process\PhpToolProcessFactory::create(SymPress\Runtime\Process\PhpTool $tool, ?string $phpPath = NULL): SymPress\Runtime\Process\PhpToolProcess
SymPress\Runtime\Process\SystemProcess [service handle]
SymPress\Runtime\Process\SystemProcess::execute(array|string $command, ?string $cwd = NULL, int $verbosity = 32): bool
SymPress\Runtime\Process\SystemProcess::executeCapturing(array|string $command, ?string $cwd = NULL): array
SymPress\Runtime\Process\SystemProcess::executeSilently(array|string $command, ?string $cwd = NULL): bool
SymPress\Runtime\Process\SystemProcess::withEnvironment(array $environment): SymPress\Runtime\Process\SystemProcess
SymPress\Runtime\Services [public type]
SymPress\Runtime\Services::config(): SymPress\Runtime\Config\Config
SymPress\Runtime\Services::dbChecker(): SymPress\Runtime\Database\DbChecker
SymPress\Runtime\Services::env(): SymPress\Runtime\Env\EnvReader
SymPress\Runtime\Services::filesystem(): SymPress\Runtime\Filesystem\Filesystem
SymPress\Runtime\Services::io(): SymPress\Runtime\Console\Io
SymPress\Runtime\Services::paths(): SymPress\Runtime\Filesystem\Paths
SymPress\Runtime\Services::phpToolProcessFactory(): SymPress\Runtime\Process\PhpToolProcessFactory
SymPress\Runtime\Services::systemProcess(): SymPress\Runtime\Process\SystemProcess
SymPress\Runtime\Services::urlDownloader(): SymPress\Runtime\Download\UrlDownloader
SymPress\Runtime\Services::wpCliProcess(): SymPress\Runtime\Process\PhpToolProcess
SymPress\Runtime\Services::wpConfigSectionEditor(): SymPress\Runtime\Generation\WpConfigSectionEditor
SymPress\Runtime\Step\AsRuntimeStep [public type]
SymPress\Runtime\Step\AsRuntimeStep::$name: string [readonly]
SymPress\Runtime\Step\AsRuntimeStep::$priority: int [readonly]
SymPress\Runtime\Step\AsRuntimeStep::__construct(string $name, int $priority = 0)
SymPress\Runtime\Step\BlockingStepInterface [public type]
SymPress\Runtime\Step\BlockingStepInterface::ERROR: int = 1
SymPress\Runtime\Step\BlockingStepInterface::NONE: int = 4
SymPress\Runtime\Step\BlockingStepInterface::SUCCESS: int = 2
SymPress\Runtime\Step\BlockingStepInterface::allowed(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): bool
SymPress\Runtime\Step\BlockingStepInterface::error(): string
SymPress\Runtime\Step\BlockingStepInterface::name(): string
SymPress\Runtime\Step\BlockingStepInterface::run(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): int
SymPress\Runtime\Step\BlockingStepInterface::success(): string
SymPress\Runtime\Step\ConditionalStepInterface [public type]
SymPress\Runtime\Step\ConditionalStepInterface::ERROR: int = 1
SymPress\Runtime\Step\ConditionalStepInterface::NONE: int = 4
SymPress\Runtime\Step\ConditionalStepInterface::SUCCESS: int = 2
SymPress\Runtime\Step\ConditionalStepInterface::allowed(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): bool
SymPress\Runtime\Step\ConditionalStepInterface::conditionsNotMet(): string
SymPress\Runtime\Step\ConditionalStepInterface::error(): string
SymPress\Runtime\Step\ConditionalStepInterface::name(): string
SymPress\Runtime\Step\ConditionalStepInterface::run(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): int
SymPress\Runtime\Step\ConditionalStepInterface::success(): string
SymPress\Runtime\Step\FileCreationStepInterface [public type]
SymPress\Runtime\Step\FileCreationStepInterface::ERROR: int = 1
SymPress\Runtime\Step\FileCreationStepInterface::NONE: int = 4
SymPress\Runtime\Step\FileCreationStepInterface::SUCCESS: int = 2
SymPress\Runtime\Step\FileCreationStepInterface::allowed(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): bool
SymPress\Runtime\Step\FileCreationStepInterface::error(): string
SymPress\Runtime\Step\FileCreationStepInterface::name(): string
SymPress\Runtime\Step\FileCreationStepInterface::run(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): int
SymPress\Runtime\Step\FileCreationStepInterface::success(): string
SymPress\Runtime\Step\FileCreationStepInterface::targetPath(SymPress\Runtime\Filesystem\Paths $paths): string
SymPress\Runtime\Step\OptionalStepInterface [public type]
SymPress\Runtime\Step\OptionalStepInterface::ASK: string = "ask"
SymPress\Runtime\Step\OptionalStepInterface::ERROR: int = 1
SymPress\Runtime\Step\OptionalStepInterface::NONE: int = 4
SymPress\Runtime\Step\OptionalStepInterface::SUCCESS: int = 2
SymPress\Runtime\Step\OptionalStepInterface::allowed(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): bool
SymPress\Runtime\Step\OptionalStepInterface::askConfirm(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Console\Io $io): bool
SymPress\Runtime\Step\OptionalStepInterface::error(): string
SymPress\Runtime\Step\OptionalStepInterface::name(): string
SymPress\Runtime\Step\OptionalStepInterface::run(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): int
SymPress\Runtime\Step\OptionalStepInterface::skipped(): string
SymPress\Runtime\Step\OptionalStepInterface::success(): string
SymPress\Runtime\Step\PostProcessStepInterface [public type]
SymPress\Runtime\Step\PostProcessStepInterface::ERROR: int = 1
SymPress\Runtime\Step\PostProcessStepInterface::NONE: int = 4
SymPress\Runtime\Step\PostProcessStepInterface::SUCCESS: int = 2
SymPress\Runtime\Step\PostProcessStepInterface::allowed(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): bool
SymPress\Runtime\Step\PostProcessStepInterface::error(): string
SymPress\Runtime\Step\PostProcessStepInterface::name(): string
SymPress\Runtime\Step\PostProcessStepInterface::postProcess(SymPress\Runtime\Console\Io $io): void
SymPress\Runtime\Step\PostProcessStepInterface::run(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): int
SymPress\Runtime\Step\PostProcessStepInterface::success(): string
SymPress\Runtime\Step\Runner [service handle]
SymPress\Runtime\Step\Runner::addStep(SymPress\Runtime\Step\StepInterface $step, SymPress\Runtime\Step\StepInterface ...$steps): SymPress\Runtime\Step\Runner
SymPress\Runtime\Step\Runner::count(): int
SymPress\Runtime\Step\Runner::name(): string
SymPress\Runtime\Step\Runner::removeStep(SymPress\Runtime\Step\StepInterface|string $step, SymPress\Runtime\Step\StepInterface|string ...$steps): SymPress\Runtime\Step\Runner
SymPress\Runtime\Step\ScriptHaltSignal [public type]
SymPress\Runtime\Step\ScriptHaltSignal::haltStep(string $reason): SymPress\Runtime\Step\ScriptHaltSignal [static]
SymPress\Runtime\Step\ScriptHaltSignal::haltStepContinuePropagation(string $reason): SymPress\Runtime\Step\ScriptHaltSignal [static]
SymPress\Runtime\Step\ScriptHaltSignal::isPropagationStopped(): bool
SymPress\Runtime\Step\ScriptHaltSignal::isStepHalted(): bool
SymPress\Runtime\Step\ScriptHaltSignal::reason(): string
SymPress\Runtime\Step\ScriptHaltSignal::stopPropagation(string $reason): SymPress\Runtime\Step\ScriptHaltSignal [static]
SymPress\Runtime\Step\StepInterface [public type]
SymPress\Runtime\Step\StepInterface::ERROR: int = 1
SymPress\Runtime\Step\StepInterface::NONE: int = 4
SymPress\Runtime\Step\StepInterface::SUCCESS: int = 2
SymPress\Runtime\Step\StepInterface::allowed(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): bool
SymPress\Runtime\Step\StepInterface::error(): string
SymPress\Runtime\Step\StepInterface::name(): string
SymPress\Runtime\Step\StepInterface::run(SymPress\Runtime\Config\Config $config, SymPress\Runtime\Filesystem\Paths $paths): int
SymPress\Runtime\Step\StepInterface::success(): string
```
<!-- /api-inventory -->
