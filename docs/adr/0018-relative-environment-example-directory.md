# ADR 0018: Bounded oracle failure for relative environment-example paths

Status: Implemented and reproduced against the pinned development oracle.

Difference **D25** concerns WP Starter development commit `059bbc9198ac51dee86c5233b6b006ca4d8008a4`. With `env-dir: "."`, its environment-example step calls `Filesystem::copyFile()` with a relative target. `Filesystem::createDir()` normalizes the parent `.` to an empty string; its ancestor loop at lines 255–257 repeatedly applies `dirname()` to that empty string and never reaches an existing directory.

The differential fixture runs that exact case under a two-second PHP CPU limit. It requires exit 255, the precise execution-time error in the ancestor loop, and absence of `.env.example`. An arbitrary timeout, unrelated exception or changed upstream result does not pass. The candidate must create the example successfully with the original relative configuration.

After recording the failure, the oracle receives an absolute path to the same project root so its generated MU loader, YAML and environment example can still be compared. Only the path spelling changes in this second oracle execution. Runtime resolves relative project paths before filesystem operations and preserves the environment-example capability. The release 3.0.1 oracle does not exhibit this loop.

The bundled example retains the same five active assignments as both baselines: the development environment selector, three empty database values and an empty home URL. Additional constant examples and native options are comments. No default credential is invented by the generated example.
