# Environment request cost

Run `php tools/benchmark-env-requests.php` to measure the real generated bundled
bootstrap through PHP's built-in HTTP server. The isolated fixture has 1,000 safe
file-owned values and one process-derived expression. Its generated parser copy
is instrumented to count parse calls and input bytes; installed dependencies are
left untouched. Each phase measures 20 requests after one initial request.
No database or customer application is used, and the fixture/server are removed.

PHP 8.5.9 on 2026-10-02 produced these results before and after selective refresh:

| Measurement | Previous whole-cache invalidation | Selective expression refresh |
| --- | ---: | ---: |
| Unchanged process cache hits | 20/20 | 20/20 |
| Unchanged process parser calls / input bytes | 0 / 0 | 0 / 0 |
| Safe deployment dump created | No | Yes |
| Rotated process cache hits | 0/20 | 20/20 |
| Rotated process parser calls / input bytes | 1 / 77,956 | 1 / 32 |
| Rotated environment p50 / p95, ms | 19.843 / 20.177 | 10.790 / 11.695 |
| Rotated HTTP p50 / p95, ms | 20.954 / 21.750 | 12.083 / 13.182 |

These are local fixture timings, not production latency guarantees. Unrelated
keys remain cached; affected process expressions are resolved from non-secret
templates or reparsed when their dependency fingerprint changes. Command results
and transitive results run live and never enter the persisted value map. Dynamic
environment selectors decline persistence because they can change the whole file
chain. Original file-owned expressions may contain file-owned secrets and need
the same private location and permissions as all environment artifacts.

`ProductionEnvironmentTest` covers secret-byte exclusion, partial immutable dumps,
process rotation/removal, missing defaults, commands, transitive command results,
source order and multiline quotes. `ProductionDoctorTest` covers forwarded scheme
trust, explicit cache disablement and read-only command diagnostics.
