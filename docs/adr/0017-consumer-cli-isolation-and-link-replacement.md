# ADR 0017: Consumer WP-CLI isolation and explicit link replacement

Status: Implemented; consumer smoke in progress.

Fresh consumer dependency resolution reproduced a hard conflict: `wp-cli/wp-cli-bundle` 2.12 requires `wp-cli/process` 5.9.99, which replaces `symfony/process` and cannot coexist with Runtime's required Symfony 8.1 Process component. The setup child-process boundary cannot solve a Composer package graph conflict.

Consumer migrations therefore use Runtime's existing downloaded WP-CLI phar capability, including mandatory SHA512 verification and separate PHP process execution. They remove the conflicting Composer bundle and run project CLI scripts through `php wp-cli.phar`. DDEV's `wp` command remains available. No WP-CLI command/provider/file capability is removed. Existing compatible Composer-installed WP-CLI packages remain supported by Runtime resolution.

Native content publication previously protected every destination symlink, preventing deliberate retargeting even with force. Explicit `--force` now permits replacing the link leaf after fully preparing a sibling file/directory/link. Parent boundaries remain enforced; the referenced destination is never modified. The old link is temporarily retained for rollback. Directory-copy replacement has a brief rename window, rather than pretending a portable directory-over-link replacement is atomic. Failed preparation leaves the old link intact. Generated configuration and unforced publication retain their existing guards.
