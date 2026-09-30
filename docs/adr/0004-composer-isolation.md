# ADR 0004: Composer bridge and process isolation

Status: accepted by the project owner on 2026-09-29 with Phase 0 approval.

## Evidence and corrected premise

At asset-compiler `d0a17af62e389ba22e789aee11ec7435faccef5d`, `src/Composer/Plugin.php:28-37` registers install/update at priority **-1000**. Lines 85 and 98 construct and execute the compiler in Composer's process. `src/Application/CompilerFactory.php:17,28` directly uses Symfony Filesystem. Its composer.json allows several Symfony Process major versions. There is no isolated PHP application runner at this boundary. This is not the subprocess model described in the brief.

## Decision

Follow the useful boundary separation (Composer adapter versus application services), but implement the explicitly required subprocess model. Register runtime at priority **0**, before asset-compiler -1000. Integration tests must establish ordering against both the asset compiler and root cleanup scripts; do not infer whole-event order from numeric priorities alone.

Plugin activation and subscribers use only Composer APIs and PHP standard library. Spawn PHP using `proc_open()` with an argument array; pass a versioned JSON context through a protected file or pipe, not interpolated shell text. Context includes root/vendor/bin paths, install/update/standalone mode, changed package descriptors, dev mode, verbosity, decoration and interactivity. Do not serialize live Composer objects or secrets into diagnostic output. Clean up transient context on success, exception and signal.

The child loads the project autoloader and Symfony 8.1. Installed package metadata comes from Composer InstalledVersions and installed.php/installed.json, preserving custom installation paths and dev filtering. Prefer installed metadata over assumptions about vendor paths. First installation must work once post-install/post-update fires; no dependency on a previous activation or generated config.

TTY uses inherited descriptors when interactive; non-TTY execution drains both streams and forwards exit codes/signals. `--no-interaction`, quiet and verbosity settings have identical meaning in plugin and standalone modes. No interactive question may hang CI.

Composer BaseCommand's public API itself uses Symfony Console input/output types. A minimal command adapter necessarily inherits/implements those Composer-provided signatures. This is the narrowly documented exception to the literal “no Symfony” wording: it must not instantiate project Symfony services or load the project's autoloader in the host. All parsing semantics and application work belong to the child.

Legacy PHP scripts can type-hint a live Composer object as their fourth argument, and custom steps can accept it as their second constructor argument. A JSON descriptor is not type-compatible. Configuration compatibility cannot honestly promise arbitrary binary PHP compatibility under strict process isolation. Migration reports these call sites and supplies native RunContext/PackageFinder/Services replacements. Straightforward upstream Config/Result/Env/Step APIs receive run-only adapters; scripts requiring live Composer mutation need explicit rewrites before that script can run. This architectural constraint is presented for Phase 0 approval, not hidden behind a class alias or removed feature.

No existing asset-compiler code changes are needed for Phase 0. Pin its version in the coexistence fixture and test a Composer host with an older Symfony Console/Process than the project.

## Empty native consumer installation

From 1.0.0-beta.2, automatic Composer events defer setup only when no installed
WordPress core, native/legacy configuration, extension opt-in or existing site
marker is present. This native installation convenience allows Composer to install
Runtime before the project is configured. It does not change the `require-wp`
default or explicit command validation. Empty, null, invalid and referenced
configuration sources all retain validation, as do existing generated sites.
The guard uses Composer package metadata and filesystem presence only; it never
loads configuration, environment files or application services into Composer.
`ComposerTest::testBareComposerRequireDefersOnlyAutomaticSetup` exercises require,
repeat install and both explicit entrypoints; configured/legacy failures are
covered by `testConfiguredAndExistingProjectsStillFailForMissingCore`.
