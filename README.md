<h1 align="center">WP Specter - Find unused code and files</h1>

<p align="center">
	<img src="wp-specter.png" alt="WP-Specter" width="200" height="200">
</p>

WP Specter is a static analyser for WordPress code. It understands WordPress conventions (template hierarchy, hooks, action strings) and reports genuinely orphaned code:

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
- [License](#license)

## Requirements

- PHP 8.4 or higher
- Composer

## Installation

wp-specter is available on [Packagist](https://packagist.org/packages/andreas-andersson/wp-specter).

```bash
# As a dev-dependency
composer require --dev andreas-andersson/wp-specter

# Globally
composer global require andreas-andersson/wp-specter
```

## Usage

### Scan a theme or plugin

```bash
wp-specter scan <path> [options]
```

Auto-detects the target type (classic theme, block theme, plugin, etc.) and reports unused code.

```bash
# Scan a theme
wp-specter scan ./themes/my-theme

# Scan a plugin
wp-specter scan ./plugins/my-plugin
```

### Scan a Bedrock/Composer project

Point to the project root to scan all themes and plugins:

```bash
wp-specter scan ./my-bedrock-site
```

### Common options

| Option | Description |
|---|---|
| `--type=functions,hooks,templates,files,classes` | Run only specific checks (default: all) |
| `--ignore=<globs>` | Exclude files by glob pattern |
| `--verbose` | Show more detail |
| `--no-color` | Disable colors |

### Generate stubs for third-party hooks

If your theme uses hooks from plugins outside the scanned directory, generate a stubs file to suppress false positives:

```bash
# Generates .wp-specter.stubs.json by default
wp-specter generate-stubs ./plugins

# Then scan normally
wp-specter scan ./themes/my-theme
```

The stubs file is auto-loaded on your next scan with no additional flags needed.

### Configuration file

Create `.wp-specter.config.json` in your project root:

```json
{
    "targets": ["web/app/themes/sage", "web/app/plugins/my-plugin"],
    "stubsFrom": ["web/app/plugins", "web/app/mu-plugins"],
    "stubs": ".wp-specter.stubs.json",
    "exclude": ["tests"]
}
```

- `targets` — which directories to scan (replaces auto-detection)
- `stubsFrom` — directories to scan for hooks when running `wp-specter generate-stubs` with no path argument
- `stubs` — path to the stubs file (defaults to `.wp-specter.stubs.json`)
- `exclude` — directories to skip

Generate the config automatically:

```bash
wp-specter scan --generate-config
```

### Baseline existing findings

On legacy projects, suppress pre-existing findings to focus on new issues:

```bash
wp-specter scan --generate-baseline
```

This creates a `baseline` section in `.wp-specter.config.json`.

## All Options

### Scan command

```bash
wp-specter scan <path> [options]
```

**Arguments:**
- `path` — Path to a theme, plugin, or project directory (optional, defaults to current directory). Supports glob patterns like `plugins/custom-*`.

**Scan options:**
- `--target=theme|plugin` — Specify the target type (default: auto-detect)
- `--type=<types>` — Comma-separated checks: `functions`, `hooks`, `templates`, `files`, `classes` (default: all)
- `--stubs=<file>` — Path to stubs JSON file to suppress known hooks
- `--ignore=<globs>` — Comma-separated glob patterns to exclude files
- `--verbose` — Show matched references alongside findings
- `--no-color` — Disable ANSI color output
- `--generate-config` — Write resolved scan targets to `.wp-specter.config.json` and exit
- `--generate-baseline` — Save current findings as suppressions in `.wp-specter.config.json` and exit (requires `--generate-config` first)
- `--no-vendor-reflection` — Don't load the project's `vendor/autoload.php` for class-contract reflection (keeps scan strictly static)
- `--no-suppress-unused-class-methods` — Report unused methods even if their class is already reported as unused
- `--no-progressbar` — Hide the progress bar during analysis

### Generate-stubs command

```bash
wp-specter generate-stubs [<path>] [options]
```

**Arguments:**
- `path` — Directory to scan for hooks (optional; if omitted, uses `stubsFrom` from `.wp-specter.config.json`)

**Options:**
- `--output=<file>` — Output path for stubs file (default: `.wp-specter.stubs.json`)

### Other commands

- `wp-specter help` — Show help
- `wp-specter version` — Show version

### Exit codes

- `0` — No unused items found
- `1` — Unused items found
- `2` — Fatal error

## License

MIT — see [LICENSE](LICENSE).
