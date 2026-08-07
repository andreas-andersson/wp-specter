# wp-specter — Spec

## 1. Objective

Standalone PHP CLI tool that scans a WordPress project and reports unused PHP functions, unmatched hook registrations, and unreferenced template files. Targets solo WordPress developers who maintain multiple sites and need a quick dead-code audit before cleanup or handoff.

**Success criteria:**
- Run from any directory against any WP project path, including Composer-managed projects (e.g. Roots Bedrock)
- Auto-detect classic vs. block-based (Gutenberg) project structure
- Auto-detect or accept a manual override for the `wp-content` directory location
- Report findings as a colored terminal table with file:line references
- Zero false negatives on obvious cases; acceptable false positives clearly flagged
- No runtime WP installation required — static analysis only

---

## 2. Commands

### Primary command

```
wp-specter scan [path] [options]
```

| Argument / Option | Default | Description |
|---|---|---|
| `path` | `.` (cwd) | Root of the WordPress project or a single plugin/theme directory |
| `--wp-content=<path>` | auto-detected | Explicit path to the `wp-content` (or equivalent) directory |
| `--type=<types>` | `all` | Comma-separated: `functions`, `hooks`, `templates` |
| `--ignore=<glob>` | none | Glob patterns to exclude (e.g. `vendor/**`) |
| `--verbose` | off | Show matched references alongside findings |
| `--no-color` | off | Disable ANSI color output (for CI) |

### Secondary commands

```
wp-specter help          # Usage reference
wp-specter version       # Print version
```

### Exit codes

| Code | Meaning |
|---|---|
| 0 | Scan complete, no unused items found |
| 1 | Unused items found |
| 2 | Fatal error (invalid path, parse failure) |

---

## 3. Detection Rules

### 3a. PHP Functions (`--type=functions`)

- **Collect:** All `function foo(...)` definitions across `.php` files in the project
- **Mark used** if the function name appears as a call `foo(` anywhere in the project (including string callbacks like `'foo'` passed to `add_action`)
- **Always exclude:** Functions prefixed with `__` (magic), functions whose name matches a known WP core prefix list (`wp_`, `get_`, `the_`, `is_`, `has_`, `do_`, `apply_`)
- **Flag as uncertain** (not unused, just unresolvable): Functions registered as dynamic callbacks (`[$this, 'method']`, variable function names)

### 3b. WP Hooks (`--type=hooks`)

- **Collect:** All `add_action($tag, $cb)` and `add_filter($tag, $cb)` calls
- **Mark matched** if the same literal `$tag` string appears in `do_action($tag)` or `apply_filters($tag)` within the project
- **Note:** Most hooks fire from WP core, not the project itself. Report unmatched hooks as **"not fired within project"** — not as errors — with a distinct visual indicator (⚠ vs ✗)
- **Skip:** Dynamic tag names (variables, concatenation) — log as unresolvable

### 3c. Template Files (`--type=templates`)

- **Collect:** All `.php` files in theme template directories (`templates/`, `template-parts/`, `parts/`, root `.php` files matching WP template hierarchy names)
- **Mark used** if filename (without extension) appears in any `get_template_part()`, `get_header()`, `get_footer()`, `get_sidebar()`, `include`, or `require` call across all `.php` files
- **Classic mode:** Also check standard template hierarchy names (`single.php`, `archive.php`, etc.) — these are used by WP core and should be excluded from "unused" list
- **Block mode:** Also scan `block.json` `render` fields and `render.php` references

---

## 4. Project Layout Auto-Detection

Run at scan start before any analysis. Results logged to output header.

### 4a. Composer-managed projects (implemented — supersedes the original plan below)

`scan <path>` tries direct theme/plugin detection first (unchanged, and always wins if it
succeeds). Only when that fails does `ComposerProjectDetector` walk upward from `<path>` looking
for a `composer.json`. If found, and its `extra.installer-paths` declares `type:wordpress-theme`
/ `wordpress-plugin` / `wordpress-muplugin` rules, every subdirectory under those declared paths
is treated as a scan target — **except** ones `vendor/composer/installed.json` records as
composer-installed (matched by its `install-path` field). That means a wpackagist-downloaded
theme sitting right next to your own custom theme is excluded automatically, with no naming
heuristics involved — this generalizes beyond Bedrock to any `composer/installers`-based layout.

Function/hook analysis runs once across every discovered target's files (so a theme registering
a hook that its companion plugin fires isn't a false "unmatched"); template/file analysis runs
per-target since each needs its own root for hierarchy/root-level detection.

mu-plugins is a special case: WP auto-loads any `.php` file placed directly inside `mu-plugins/`
(not just per-package subdirectories), so loose files there are scanned as their own pseudo-target
with no WP mode (no template hierarchy applies to them).

If a path resolves to neither a theme/plugin nor a composer project, it's scanned as-is with mode
`unknown` (original fallback behavior, unchanged).

**Superseded plan (kept for history — never implemented as written):** a `ContentDirDetector`
that resolved a single wp-content-equivalent path via `--wp-content` flag → `"roots/bedrock"` in
`composer.json` → `extra.wordpress-install-dir` → `wp-content/` → `app/` → warn, then scanned
everything under `themes/`/`plugins/`/`mu-plugins/` unconditionally. That design doesn't
distinguish your code from a composer-installed theme/plugin sitting in the same directory — the
real Bedrock projects this was tested against always have at least one (e.g. `wpackagist-theme/*`
in `require`), so the naive version reported hundreds of false positives from vendor code.

### 4b. Theme / Plugin Mode

After resolving the content dir:

| Signal | Mode |
|---|---|
| `theme.json` present in a theme dir | Block (FSE) |
| `block.json` present anywhere under theme dir | Block |
| `style.css` with `Theme Name:` header, no `theme.json` | Classic theme |
| `*.php` with `Plugin Name:` header | Plugin |
| Both `theme.json` and `functions.php` in same theme | Hybrid (both rule sets apply) |

---

## 5. Project Structure

```
wp-specter/
├── bin/
│   └── wp-specter              # Shebang entry point (#!/usr/bin/env php)
├── src/
│   ├── Detector/
│   │   ├── WpModeDetector.php      # Auto-detect classic/block/plugin
│   │   └── ContentDirDetector.php  # Resolve wp-content path (standard/Bedrock/custom)
│   ├── Scanner/
│   │   └── FileScanner.php     # Recursive file collection + ignore filtering
│   ├── Analyzer/
│   │   ├── FunctionAnalyzer.php
│   │   ├── HookAnalyzer.php
│   │   └── TemplateAnalyzer.php
│   ├── Parser/
│   │   └── PhpTokenParser.php  # Wraps PHP token_get_all()
│   ├── Reporter/
│   │   └── TerminalReporter.php # ANSI table output
│   └── Application.php         # Wires everything together, handles CLI args
├── tests/
│   ├── fixtures/
│   │   ├── classic-theme/      # Minimal classic theme for integration tests
│   │   ├── block-theme/        # Minimal block theme for integration tests
│   │   ├── bedrock-project/    # Minimal Bedrock-style layout (web/app/themes|plugins)
│   │   └── standard-wp/        # Traditional wp-content/ layout
│   ├── unit/
│   └── integration/
├── composer.json
└── SPEC.md
```

---

## 6. Code Style

- **PHP 8.1+** minimum (named args, enums, readonly properties)
- **No framework** — stdlib + Composer autoload only
- **No global state** — pass config/context explicitly
- **Strict types** — `declare(strict_types=1)` in every file
- **PSR-4** autoloading under namespace `WpSpecter\`
- **No magic** — no dynamic property access, no `eval`, no variable variables
- Use PHP's built-in `token_get_all()` for PHP parsing — no third-party AST library in v1

---

## 7. Testing Strategy

- **Unit tests** (PHPUnit): Each analyzer in isolation against hand-crafted token streams
- **Integration tests**: Run `wp-specter scan` against `tests/fixtures/classic-theme/` and `tests/fixtures/block-theme/` and assert exact finding counts and file:line references
- **No mocking of the filesystem** — integration tests use real fixture directories
- **Coverage target:** 80%+ on analyzer classes; reporter and CLI wiring can be lower
- CI: `composer test` runs PHPUnit; `composer lint` runs PHP-CS-Fixer (PSR-12)

---

## 8. Boundaries

### Always do
- Skip `vendor/`, `node_modules/`, `.git/` by default (no flag required)
- Print a header showing detected WP mode and scanned file count before results
- Show `file:line` for every finding
- Exit 1 when findings exist (CI-friendly)

### Ask before doing (future versions)
- Auto-deleting or modifying any source files
- Generating a fix/patch file
- Scanning a live database for hook usage

### Never do
- Execute or `eval` any PHP from the scanned project
- Make network requests
- Write to any file in the scanned project
- Require a running WP installation

---

## 9. Out of Scope (v1)

- CSS class analysis
- JS/TS dead code
- Translation string audit
- JSON/HTML report formats (terminal only)
- WP-CLI integration
- Multi-project / workspace scanning
- Other Composer WP frameworks beyond Bedrock (Timber-only setups, WP Starter, etc.) — detectable via `--wp-content` flag in the meantime
