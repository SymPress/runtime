# ADR 0012: WP-CLI arguments and diagnostic boundaries

Status: implementation decision, 2026-09-29.

WP-CLI command strings are tokenized with Symfony Console, and file descriptors remain argument arrays. The process adapter quotes each argument exactly once. Array arguments are never converted back through StringInput: that round trip removes literal backslashes. Quoted paths, whitespace, apostrophes and shell metacharacters reach WP-CLI as values. Trusted shell programs remain available through the separate SystemProcess string API.

Configured `--path=value` and `--path value` options are removed before the `--` terminator; the resolved core path is inserted before that terminator or appended when none exists. Values after `--` remain positional values. This makes the configured core authoritative without corrupting an option-looking argument.

## D24 argument fidelity

Upstream `WpCliFileData::setup` filters arguments with array_filter, dropping both empty strings and the string `"0"`. The runtime keeps this zero filtering in legacy profiles; native mode retains `"0"` as a usable argument. Empty strings are filtered in both. Whitespace inside nonempty arguments is preserved rather than trimmed. Nonstring/null-byte input is rejected under D01. Upstream's unquoted eval-file concatenation can split paths/arguments or perform shell expansion; the capability to pass these values is preserved through literal argument arrays, as required by the work order. Exact argument-vector fixtures cover these changes.

PHP providers receive both local `$services`/`$locator` and a temporarily installed global locator. Any previous global value is restored, including on failure. Evaluation occurs once during selected execution; listing and static validation do not execute the PHP file. Provider errors expose a generic diagnostic, not exception contents or returned values.

Process failure messages report the exit code without repeating the command line, which may contain credentials. Child stdout/stderr continue to stream as command output; explicit capture still returns its error object to the caller. Step progress reports command numbers rather than argv. This changes diagnostic wording while retaining the failure, exit status and command output.

Verification includes captured argument-vector processes, provider success/failure/cleanup, missing files, first-failure termination and a real WP-CLI 2.12.0 fixture invoking eval-file without WordPress and reading WordPress 7.1.1's core version.
