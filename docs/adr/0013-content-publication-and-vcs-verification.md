# ADR 0013: Content publication and VCS verification

Status: implementation decision, 2026-09-29.

This implements D10, D11, D18 and D20 from ADR 0005. Package dropin sources are retained, including metadata and licenses. File publication prepares a sibling file or relative symlink before replacing the target; a failed copy/download leaves existing bytes intact. Directory publication and core-content moves preflight destination types and overwrite protection before starting. Physical ancestor/descendant overlaps are rejected even for explicit symlink operations. Move-content still transfers the entire core wp-content tree and removes its source on success; theme registration disables it.

The dropin catalog is the twelve-name union from the inventoried WordPress core and both WP Starter baselines. All four multisite filenames are available in every publication route; WordPress itself decides whether to load them. Release-profile unknown names retain false/ask/true handling. Native and dev profiles accept explicitly mapped filenames. Each catalog entry has execution assertions for package, map and development-content sources.

## D02 release publication

The release has no dropins-op option and always copies dropins. The release compatibility profile retains copy; native/dev honor auto/symlink/copy/none/ask. Differential fixtures record all ten upstream-catalog file-to-link differences individually with exact hashes and normalized link targets.

The pinned release's copy operation copies each complete plugins/themes/mu-plugins/languages directory, including hidden and VCS entries. Its symlink operation uses an immediate-child glob, which omits dotfiles but includes non-dot VCS directories. Dev uses Finder's dotfile/VCS filtering for immediate children in both modes. Runtime preserves these discovery differences in the release profile; native follows the dev behavior. The differential fixture records the exact hidden-file hash present only in the release output and verifies that the release compatibility profile restores it. This extends the D02 baseline-default decision with a source-proven discovery difference.

In selected mode, native content-dev ask means auto publication under D21. The dev compatibility profile retains the upstream no-operation result when its optional question was bypassed. Release confirmation defaults to symlink.

## Actual VCS evidence

Git metadata may be a directory or worktree pointer file. Git checks include tracked files, even if an ignore pattern matches; an existing generated marker grants no exemption. Mercurial status and Subversion ignore properties are inspected using their command-line tools. Missing tools or metadata produce an explicit unknown diagnostic rather than a success. A failed or incomplete inspection returns an error. Repositories without commits are still checked.

Only missing Git/Mercurial ignore files are created. Existing user entries and custom templates remain untouched; generated template output is checked immediately. Literal paths are escaped for the relevant ignore syntax. Native rules name the actual targets instead of grouping unrelated public assets under a shared parent. Environment variants, runtime caches and build dumps are included when present. Native also includes wp-cli.yml before it exists, because the required default order creates it in the following step. Subversion properties are read without changing repository properties or creating a fictitious .svnignore.

Verification uses disposable real Git, Mercurial and Subversion repositories, a Git worktree, tracked-sensitive-file failures, custom templates and filenames containing spaces/brackets. Complete differential coverage of every publication failure and ignore-file format remains an acceptance task.
