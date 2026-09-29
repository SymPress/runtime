# Phase 2 review: independent generated WordPress runtime

Generated WordPress configuration now boots through an immutable scoped parser payload without loading Composer. The wpconfig, checkpaths, index and flushenvcache steps execute through the isolated runner; database preflight respects selection/profile rules. Runtime caching and explicit build dumps preserve actual environment precedence, with dumps supporting read-only deployments.

All 19 configuration sections remain editable. Regeneration preserves existing salts and edited section bodies, rejects dynamic salts outside retained KEYS sections, validates PHP before publication and protects secondary proxy files. Legacy getter/filter behavior and native hooks have isolated execution tests.

Validation on 2026-09-29:

- Full composer qa: 880 tests, 6,149 assertions, coding standards and PHPStan max pass.
- Real WordPress 7.1.1 SHORTINIT connects to isolated MariaDB and runs SELECT 1 without Composer. This is not a full consumer installation/admin/kernel smoke.
- Separate differential suite: 183 passing fixtures against both pinned WP Starter commits, covering all 157 constant types/definitions, environment mappings, generated runtime reports and cache behavior.
- Exact differences D06/D09/D13 are checked; new D23 captures the upstream dev null env-dir failure and the working runtime default. No upstream source patch or blanket file exclusion is used.

The PR is stacked on Phase 1. Remote CI needs the QA PHPUnit 13 companion on main. Complete file snapshots, every generated edge-case combination, later default steps and extension/migration behavior remain pending. The parity matrix remains partial rather than declaring final drop-in acceptance.
