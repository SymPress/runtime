# V1 source consumer smoke

On 2026-09-30 the isolated Demo consumer was tested against a frozen Runtime
source override based on `743dc99` plus the implementation changes then present.
Its source file-map SHA-256 was
`aeeea043944de555bed1f3b0fe4b0eb2be8e820373125c8e5042ab20dffafcec`.
This was a source integration gate, not installation of a published RC.

- Runtime setup generated a working WordPress payload including `EnvCacheFormat`.
  WordPress loaded that class from fingerprint
  `9140fcfac553fbdebbbca503c5058316064cdef9e585cab9ba49a075ffce731a`.
- Consumer QA passed: 27 tests / 213 assertions, coding standards, PHPStan,
  Composer audit and real REST/block/render/ORM checks.
- The homepage returned 200. Root login and admin routes remained unchanged;
  authenticated dashboard browser checks passed with no JavaScript errors.
- `doctor --json` and plain `validate` returned identical output/status through
  the binary and native `wp runtime`, both at the project root and below it.
  All successful variants returned 0. Unsupported `validate --json` was rejected
  consistently with status 1.
- The comparison used identical PHP error-reporting settings on both paths.
  Upstream WP-CLI PHP 8.5 deprecations remain visible in the full QA logs; they
  were not presented as Runtime JSON output.
- The consumer's committed files remained unchanged; the source override and
  prior vendor copy were retained only in its isolated local environment.

The final tagged RC and stable consumer upgrades require their own CI and smoke
checks. This record does not replace them or imply a production rollout.
