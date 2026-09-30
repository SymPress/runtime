# Generated wp-config and sections

The `wpconfig` step generates `wp-config.php` at the project root. Run it with:

```sh
vendor/bin/sympress-runtime --no-interaction wpconfig
```

Older compatibility profiles can use a different location; see [Compatibility](compatibility.md). A proxy in the core parent is generated when required. Both files are parsed before publication. Native unmarked files require overwrite approval; protected paths, symlinks and directories retain their documented guards. Existing salts and edited managed sections are preserved during regeneration.

## What gets loaded

The configuration loads WordPress's hook API, optionally the project Composer autoloader, and a scoped parser from `var/runtime/<fingerprint>/bootstrap.php`. That payload is required on every request: it is a deployment artifact, not disposable cache. `wp-config-autoload` defaults false natively and true in legacy profiles. With it disabled, the environment parser works without Composer; application hooks that use Composer classes still need their own bootstrap.

## Edit a managed section

Sections use PHP labels and paired comments, for example:

```php
BEFORE_BOOTSTRAP : {
    // Trusted project PHP.
} #@@/BEFORE_BOOTSTRAP
```

The following nineteen section names are stable technical identifiers, including
historical names retained for custom templates:

 `DEBUG_INFO_INIT`, `ABSPATH`, `AUTOLOAD`, `WPS_GETENV_FUNCTION`, `ENV_VARIABLES`, `KEYS`, `DB_SETUP`, `EARLY_HOOKS`, `DEFAULT_ENV`, `SSL_FIX`, `URL_CONSTANTS`, `THEMES_REGISTER`, `ADMIN_COLOR`, `ENV_CACHE`, `DEBUG_INFO`, `GETENV_FILTER`, `BEFORE_BOOTSTRAP`, `CLEAN_UP`, `WP_CLI_HACK`.

Custom steps obtain the editor through `$services->wpConfigSectionEditor()`:

```php
$editor = $services->wpConfigSectionEditor();
$editor->append('BEFORE_BOOTSTRAP', 'define("PROJECT_BOOTSTRAPPED", true);');
```

`sectionContent()` reads the normalized body. `append()` and `prepend()` add content with a call-site/content marker so a repeated invocation from that location is a no-op. `replace()` replaces the body; `delete()` empties it while retaining the section. Names are case-insensitive. Missing sections are a no-op for edits and read as an empty string. Dollar signs/backslashes in inserted PHP stay literal. These semantics intentionally fix the legacy editor's replacement and duplicate edge cases; exact differences are recorded in [ADR 0005, D15](adr/0005-compatibility-and-differences.md).

## Regenerate and deploy safely

Keep section delimiters intact. Regeneration retains edited bodies, including manually maintained early autoload code; changing configuration options does not silently discard those edits. Review preservation diagnostics after template/configuration upgrades. Custom templates take responsibility for their bootstrap and must be tested with real WordPress, WP-CLI and the selected autoload policy.

The generated paths are relative where the layout permits, but unchanged relative paths are only one part of relocation. Environment dumps and compiled kernel/application files can contain absolute paths. Follow [deployment](deployment.md) before moving artifacts to another root.
