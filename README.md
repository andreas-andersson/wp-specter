# WP Specter

**Has your WordPress project grown over the years? Or did you inherit one — thousands
of lines of PHP, and nobody left who can tell you what's still actually used?**

Deleting code from a WordPress project is scary, because so little of it is called
the way normal PHP is called. A template file isn't `include`d anywhere — WordPress
picks it up from the template hierarchy. A function isn't called by name — it's
hooked to an action by string. So generic dead-code tools either flag half your theme
as unused, or shrug and tell you nothing.

WP Specter is a static analyser that knows those conventions. Point it at a theme,
a plugin, or a whole Bedrock project and it reports what's genuinely orphaned:

- **Unused functions and classes** — defined, never referenced
- **Unused methods** — resolved per class, not just by name
- **Unmatched hooks** — `add_action` / `add_filter` for a tag nothing ever fires
- **Unused templates** — template parts nothing loads, with the template hierarchy
  and `block.json` render fields accounted for
- **Orphaned files** — PHP that is never included, required or referenced

**Example output:**

```
wp-specter — WordPress unused code scanner

  Path:   /home/user/dev/my-site/wp-content/themes/mytheme/
  Mode:   Classic theme
  Files:  247 PHP files scanned

Unused Functions

  ✗  user_is_temporarly_banned
     /home/user/dev/my-site/wp-content/themes/mytheme/functions.php:524

  ✗  custom_loginpage_with_return_url
     /home/user/dev/my-site/wp-content/themes/mytheme/functions.php:532

  ✗  acf_location_rules_match_user
      /home/user/dev/my-site/wp-content/themes/mytheme/functions.php:647

Unmatched Hooks

  ⚠  wsl_hook_process_login_before_wp_safe_redirect  // not fired within scanned directory
     /home/user/dev/my-site/wp-content/themes/mytheme/functions.php:2006

Unused Files

  ⚠  acf-blocks/featured-pages  // not included, required, or referenced anywhere in scanned directory
     /home/user/dev/my-site/wp-content/themes/mytheme/acf-blocks/featured-pages.php:1

  ⚠  acf-blocks/featured-posts  // not included, required, or referenced anywhere in scanned directory
     /home/user/dev/my-site/wp-content/themes/mytheme/acf-blocks/featured-posts.php:1

  ⚠  page-templates/sections/parts/feedback-form  // not included, required, or referenced anywhere in scanned directory
     /home/user/dev/my-site/wp-content/themes/mytheme/page-templates/sections/parts/feedback-form.php:1

Unused Classes

  ✗  WP_Page_List_Navwalker
     /home/user/dev/my-site/wp-content/themes/mytheme/includes/wp_page_list_navwalker.php:3

Unused Methods

  ⚠  start_lvl
     /home/user/dev/my-site/wp-content/themes/mytheme/includes/wp_page_list_navwalker.php:5

  ⚠  start_el
     /home/user/dev/my-site/wp-content/themes/mytheme/includes/wp_page_list_navwalker.php:10

Found: 3 unused function(s), 1 unmatched hook(s), 3 unused file(s), 1 unused class(es), 2 unused method(s)
```

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Scan a theme or plugin](#scan-a-theme-or-plugin)
  - [Scan a composer-managed project (Bedrock and similar)](#scan-a-composer-managed-project-bedrock-and-similar)
  - [generate-stubs](#generate-stubs)
  - [Project config: `.wp-specter.config.json`](#project-config-wp-specterconfigjson)
  - [Project stubs: `.wp-specter.stubs.json`](#project-stubs-wp-specterstubsjson)
- [What it checks](#what-it-checks)
- [Supported theme types](#supported-theme-types)
- [Project structure](#project-structure)
- [Running tests](#running-tests)
- [License](#license)

## Requirements

- PHP 8.4 or higher
- Composer

## Installation

wp-specter is published on [Packagist](https://packagist.org/packages/andreas-andersson/wp-specter).

```bash
# As a project dev-dependency
composer require --dev andreas-andersson/wp-specter

# Globally
composer global require andreas-andersson/wp-specter

```

Make sure Composer's global bin dir is on your `PATH` (`composer global config bin-dir --absolute` prints it — usually `~/.config/composer/vendor/bin` or `~/.composer/vendor/bin`).

### From source (contributing to wp-specter itself)

```bash
git clone https://github.com/andreas-andersson/wp-specter.git
cd wp-specter
composer install
php bin/wp-specter scan ...
```

## Usage

### Scan a theme or plugin

```bash
wp-specter scan <path> [options]
```

Point it at a theme or plugin directory. The tool auto-detects whether it is a classic theme, block (FSE) theme, hybrid theme, or plugin based on the files present (`style.css`, `theme.json`, `Plugin Name:` header, etc.).

```bash
# Auto-detect target type
wp-specter scan ./themes/my-theme

# Explicitly declare what you are scanning
wp-specter scan ./themes/my-theme --target=theme
wp-specter scan ./plugins/my-plugin --target=plugin
```

### Scan a composer-managed project (Bedrock and similar)

Point `scan` at the project root (or any ancestor of it) instead of a single theme/plugin, and it
auto-detects the rest — same command, no extra flags needed:

```bash
wp-specter scan ./my-bedrock-site
```

If the given path isn't itself a theme or plugin, wp-specter looks for a `composer.json` above
it. When found, it reads `extra.installer-paths` to discover every theme/plugin/mu-plugin
directory the project declares, then cross-checks `vendor/composer/installed.json` to exclude
whatever composer actually installed there (a wpackagist theme, a purchased plugin pulled in via
a private repo, etc.) — only your own custom code gets scanned. Hooks and functions are matched
across all discovered targets together, so a theme registering a hook that its companion plugin
fires isn't reported as unmatched.

#### Scan options

| Option | Description |
|---|---|
| `--target=theme\|plugin` | Declare the target type (default: auto-detect) |
| `--type=<types>` | Comma-separated list of checks to run: `functions`, `hooks`, `templates`, `files`, `classes` (default: all) |
| `--stubs=<file>` | JSON stubs file of known hooks to suppress (see [generate-stubs](#generate-stubs)) |
| `--ignore=<globs>` | Comma-separated glob patterns to exclude from scanning |
| `--verbose` | Show extra detail alongside findings |
| `--no-color` | Disable ANSI colour output |
| `--generate-config` | Write resolved scan targets to `.wp-specter.config.json` and exit (see [Project config](#project-config-wp-specterconfigjson)) |
| `--generate-baseline` | Save the current findings as suppressions in `.wp-specter.config.json` and exit (see [Baselining existing findings](#baselining-existing-findings)) |
| `--no-vendor-reflection` | Don't load the scanned project's `vendor/autoload.php` for the class-contract reflection fallback (see [Unused classes](#unused-classes)) |

#### Run only specific checks

```bash
# Functions only
wp-specter scan ./themes/my-theme --type=functions

# Hooks and templates, no functions
wp-specter scan ./themes/my-theme --type=hooks,templates
```

The `files` check flags PHP files that are never `include`/`require`'d and never referenced via `get_template_part()`, page-template registration, or `block.json`. It covers support directories (`inc/`, `classes/`, `admin/`, etc.) — root-level template-hierarchy files and files under `templates/`, `template-parts/`, or `parts/` are covered by the `templates` check instead, so they're skipped here to avoid duplicate findings. Files matching `Template Name:` (WP Page Templates) and any `index.php` (directory-listing blockers / sibling autoloaders) are always exempt.

#### Exit codes

| Code | Meaning |
|---|---|
| `0` | No issues found |
| `1` | One or more findings |
| `2` | Fatal error (bad path, unreadable file, etc.) |

This makes it straightforward to integrate into CI pipelines.

---

### generate-stubs

Scans a directory for all `do_action` / `apply_filters` calls and writes the discovered hook names to a JSON file. Use this to suppress false positives when hooks are fired by plugins outside your scanned theme.

```bash
wp-specter generate-stubs <path> [--output=<file>]
```

```bash
# Scan your plugins folder and write a stubs file
wp-specter generate-stubs ./plugins

# ..or set a custom output file
wp-specter generate-stubs ./plugins --output=whatever.json

# The stubs file will be auto-loaded on your next scan if using the default filename
wp-specter scan ./themes/my-theme

# ..if using a custom stub-filename you need to pass it with
wp-specter scan ./themes/my-theme --stubs=whatever.json
```

The generated file looks like:

```json
{
    "generated": "2026-08-07",
    "source": "web/app/plugins",
    "hooks": [
        "my_plugin_action",
        "my_plugin_filter"
    ],
    "prefixes": [
        "acf/settings/"
    ]
}
```

Some plugins fire a whole family of hooks through one dynamic call site instead of a literal `do_action`/`apply_filters` per hook — ACF is a good example: every `acf/settings/*` filter (`save_json`, `load_json`, `enable_datastore`, ...) routes through a single `apply_filters( "acf/settings/{$name}", $value )`. No individual tag ever appears as a literal string anywhere in ACF's source, so it can never land in `hooks`, no matter how thoroughly that source is scanned. `generate-stubs` detects this shape — an interpolated string or a `'literal' . $var` concatenation — and records the resolvable leading segment under `prefixes` instead. `scan` treats a `prefixes` match the same as an exact `hooks` match: `add_filter('acf/settings/save_json', ...)` is suppressed because `acf/settings/` was seen, not because `acf/settings/save_json` itself was. The same dynamic-prefix matching also applies within a single scan directly — a project firing its own `apply_filters("myplugin/{$type}", ...)` suppresses matching registrations without needing a stubs file at all.

You can commit this file alongside your project and update it whenever plugins change.

---

### Project config: `.wp-specter.config.json`

For projects where auto-detection isn't enough — or you just don't want to type the same paths
every time — drop a `.wp-specter.config.json` anywhere at or above the path you scan from
(discovered by walking upward, same as `composer.json`):

```json
{
    "targets": ["web/app/themes/sage", "web/app/plugins/my-plugin"],
    "stubsFrom": ["web/app/plugins", "web/app/mu-plugins"],
    "stubs": ".wp-specter.stubs.json",
    "exclude": ["tests", "vendor"]
}
```

You can write this file by hand, or let wp-specter generate the `targets` list for you by running
`--generate-config` from your project root — auto-detection runs as normal (composer discovery
included) and the resolved targets are written out instead of scanned:

```bash
# From the project root, not from inside the scanned theme/plugin directory
wp-specter scan --generate-config
```

All paths are resolved relative to the config file's own directory.

- **`targets`** — exactly which theme/plugin directories to scan. When present, this replaces
  auto-detection (including composer discovery) entirely: `wp-specter scan` (or `wp-specter scan
  <project-root>`) scans exactly this list, nothing more, nothing less. Pointing `scan` directly
  at one specific directory still scans just that one directory, config or not — `targets` only
  takes over when you scan the project as a whole.
- **`stubsFrom`** — directories `wp-specter generate-stubs` scans when run with **no path
  argument**. Handy when your hook-firing code lives in more than one place (e.g. both `plugins/`
  and a separately-managed `extras/plugins/`) — one `wp-specter generate-stubs` picks up all of
  them instead of one invocation per directory.
- **`stubs`** — overrides where the project stubs file lives (see below). Defaults to
  `.wp-specter.stubs.json` next to the config file if omitted.
- **`exclude`** — directory names (or relative paths, e.g. `"tests/fixtures"`) pruned from every
  scan and every `generate-stubs` run, on top of the always-on `vendor`/`node_modules`/`.git`
  defaults. Unlike `targets`/`stubsFrom`, entries aren't resolved to absolute paths — a bare name
  like `"tests"` matches a directory with that name anywhere under any scanned target, not just
  one anchored at the config file's own location. Useful for a plugin project scanned from its own
  root, where the plugin's `tests/` directory would otherwise be scanned for unused code alongside
  the plugin itself.
- **`baseline`** — findings to suppress on every future scan, written by `--generate-baseline`
  (see below) rather than edited by hand. Each entry's `type` is one of `unused_function`,
  `unmatched_hook`, `unused_template`, `unused_file`, `unused_class`, `unused_method`.

#### Baselining existing findings

Adopting wp-specter on an established project can turn up a pile of pre-existing findings you
don't want to fix right now. `--generate-baseline` snapshots the current findings into
`.wp-specter.config.json` so they're suppressed on every future scan, leaving only new findings to
fail CI. It requires `--generate-config` to have been run first — `--generate-baseline` writes
into the same `.wp-specter.config.json`, it doesn't create one:

```bash
# From the project root, once .wp-specter.config.json exists (see above)
wp-specter scan --generate-config    # first time only
wp-specter scan --generate-baseline
```

Each suppressed finding is recorded under `baseline` as its type, name, and file path (relative to
the config file, no line number — an unrelated edit above the flagged spot shouldn't silently
break the match):

```json
{
    "baseline": [
        { "type": "unused_function", "name": "old_helper", "file": "inc/helpers.php" }
    ]
}
```

Commit this alongside your project. As you clean up baselined findings, re-run
`--generate-baseline` to shrink the list — there's no way to remove individual entries other than
fixing the finding and regenerating.

#### Full example

Every option together — `targets` and `baseline` are normally machine-written (by
`--generate-config` and `--generate-baseline` respectively) rather than typed by hand, but this is
everything `.wp-specter.config.json` supports:

```json
{
    "targets": ["web/app/themes/sage", "web/app/plugins/my-plugin"],
    "stubsFrom": ["web/app/plugins", "web/app/mu-plugins"],
    "stubs": ".wp-specter.stubs.json",
    "exclude": ["tests", "vendor"],
    "baseline": [
        { "type": "unused_function", "name": "old_helper", "file": "inc/helpers.php" },
        { "type": "unmatched_hook", "name": "legacy/deprecated_hook", "file": "inc/hooks.php" }
    ]
}
```

### Project stubs: `.wp-specter.stubs.json`

Same file `generate-stubs` writes, but placed at (or above) the path you scan from, `scan` loads
it automatically — no `--stubs=` flag needed. An explicit `--stubs=<file>` still works and is
additive on top of the auto-loaded one (both only ever *suppress* findings, so loading both is
always safe).

```bash
# Once, from the project root (or anywhere with stubsFrom configured):
wp-specter generate-stubs
# → writes .wp-specter.stubs.json

# Every scan after that picks it up with no extra flags:
wp-specter scan web/app/themes/sage
```

---

## What it checks

### Unused functions

PHP functions defined anywhere in the scanned directory that are never called within the same directory. Class methods are excluded — only standalone functions are checked.

Functions with common WordPress prefixes (`wp_`, `get_`, `the_`, `is_`, `has_`, `do_`, `apply_`) and magic methods (`__construct`, `__get`, etc.) are automatically skipped.

### Unmatched hooks

`add_action` / `add_filter` registrations whose hook tag is never fired by `do_action` / `apply_filters` within the scanned directory.

WordPress core hooks (~400 known actions and filters) are silently ignored via a built-in stub list — core is never part of what you scan, so there's no other way to know about them.

Third-party plugin hooks (ACF, ElasticPress, WooCommerce, etc.) aren't hardcoded — if wp-specter can scan the plugin's actual code (composer project mode already does this for anything under your project's `plugins/` directory), it sees the `do_action`/`apply_filters` calls directly and matches them for real. For plugins outside the scan (bundled/vendored elsewhere, or you're scanning just a theme), use `generate-stubs` against their source, or a `stubsFrom` entry in [`.wp-specter.config.json`](#project-config-wp-specterconfigjson) to keep it current automatically.

Dynamic hook tags with no resolvable literal prefix (a bare variable, a function call result) are skipped entirely. A dynamic tag that does have a resolvable prefix — `"acf/settings/{$name}"`, `'acf/settings/' . $name` — still contributes that prefix as a match for any registration in the same family; see [generate-stubs](#generate-stubs).

### Unused templates

Template files (in `template-parts/`, `templates/`, `parts/`) that are never referenced by `get_template_part()`, `get_header()`, `get_footer()`, `get_sidebar()`, or an `include`/`require` call within the scanned directory.

WordPress template hierarchy files (`single.php`, `archive.php`, `page.php`, etc.) and their custom variants (`single-{post-type}.php`, `page-{slug}.php`, `archive-{cpt}.php`) are automatically exempt since WordPress loads them directly based on URL routing.

In block/FSE themes, templates declared in `block.json` `render` fields are also treated as referenced.

### Unused classes

Classes defined anywhere in the scanned directory that are never referenced — no `new Foo()`, `Foo::method()`/`Foo::CONST`/`Foo::class`, `instanceof Foo`, or `extends`/`implements Foo` anywhere in the scanned files.

A class only ever instantiated dynamically (`$class = 'Foo'; new $class()`) or referenced purely as a string (`register_widget('Foo')`) won't be recognized as used — this check only sees syntactic references, not string literals.

### Unused methods

Class methods that are never called anywhere in the scanned directory.

Calls with a statically-known receiver are matched *per class*, not just by name: `$this->method()`, `self::method()`, `parent::method()`, `static::method()`, and `Class::method()` with a literal class name all resolve to a specific declaring class. So does the array-callback shape `add_action`/`add_filter` registrations usually take, as long as the receiver is one of those same resolvable forms — `[$this, 'method']`, `[Class::class, 'method']`, `['Class', 'method']`, in both `[...]` and legacy `array(...)` syntax. Two unrelated classes that happen to share a method name (e.g. `render()`) no longer suppress each other's findings just because one of them is called this way.

`$obj->method()` on a plain variable also resolves, as long as that variable was directly assigned `new ClassName(...)` earlier in the same function/method — `$service = new My_Service(); $service->render();` scopes to `My_Service::render` for the rest of that function's body. Reassigning the variable to anything else (`$service = some_factory();`) invalidates the tracked type rather than leaving it stale, and the tracking never leaks across function/method boundaries — a variable of the same name in a different function starts unknown. It's also not control-flow aware: `if ($cond) { $x = new A(); } else { $x = new B(); } $x->method();` only tracks whichever assignment appears last in source order, not "could be either."

A variable whose type can't be determined this way — a constructor parameter, a property, a loop variable, the result of a function call — still falls back to a name-only match across the whole scanned directory. That's the remaining source of imprecision, and why findings are reported at `Warning` certainty (not `Error`): a method that looks unused might still be reachable through one of these unscoped paths.

Magic methods (`__construct`, `__toString`, etc.) are always excluded. Methods required by a handful of common contracts are excluded too, but only when the *declaring* class itself directly `implements`/`extends` that contract — not further up an inheritance chain:

- **PHP SPL interfaces**: `ArrayAccess`, `Iterator`, `IteratorAggregate`, `Countable`, `JsonSerializable`, `Serializable`
- **WordPress base classes**: `WP_Widget` (`widget`/`form`/`update`), `WP_REST_Controller` (`register_routes`), `Walker` (`start_lvl`/`end_lvl`/`start_el`/`end_el`)

---

## Supported theme types

| Mode | Detection |
|---|---|
| Classic theme | `style.css` with `Theme Name:` header |
| Block (FSE) theme | `theme.json` present, no `functions.php` |
| Hybrid theme | Both `theme.json` and `functions.php` present |
| Plugin | PHP file with `Plugin Name:` header |

---

## Project structure

```
src/
  Analyzer/       FunctionAnalyzer, HookAnalyzer, TemplateAnalyzer
  Detector/       WpModeDetector
  Enum/           WpMode
  Finding/        Finding, FindingType, FindingCertainty
  Parser/         PhpTokenParser and value objects
  Reporter/       TerminalReporter
  Scanner/        FileScanner, ScanResult
  Stubs/          WpCoreHooks, StubRegistry
  Application.php
  Config.php
bin/
  wp-specter
tests/
  unit/
  integration/
  fixtures/
```

## Running tests

```bash
composer test
```

## License

MIT — see [LICENSE](LICENSE).
