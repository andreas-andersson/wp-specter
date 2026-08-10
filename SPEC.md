# wp-specter — Spec

## 1. Objective

Standalone PHP CLI tool that scans a WordPress project and reports unused PHP functions, unused classes and methods, unmatched hook registrations, unreferenced template files, and orphaned PHP files. Targets solo WordPress developers who maintain multiple sites and need a quick dead-code audit before cleanup or handoff.

**Success criteria:**
- Run from any directory against any WP project path, including Composer-managed projects (e.g. Roots Bedrock and other `composer/installers`-based layouts)
- Auto-detect classic vs. block-based (Gutenberg) vs. plugin project structure, and auto-discover theme/plugin targets in a composer-managed project without a `wp-content`-style path flag
- Report findings as a colored terminal table with file:line references
- Zero false negatives on obvious cases; acceptable false positives clearly flagged with a certainty level (✗ error vs ⚠ warning)
- No runtime WP installation required — static analysis only

---

## 2. Commands

### Primary command

```
wp-specter scan [path] [options]
```

| Argument / Option | Default | Description |
|---|---|---|
| `path` | `.` (cwd) | Root of the WordPress project, or a single plugin/theme directory |
| `--target=<theme\|plugin>` | auto-detected | Declare the target type explicitly instead of relying on auto-detection |
| `--type=<types>` | `all` | Comma-separated: `functions`, `hooks`, `templates`, `files`, `classes` |
| `--stubs=<file>` | none | JSON stubs file of known hooks/prefixes to suppress (see `generate-stubs`); additive on top of any auto-loaded project stubs file |
| `--ignore=<globs>` | none | Comma-separated glob patterns to exclude |
| `--verbose` | off | Show matched references alongside findings |
| `--no-color` | off | Disable ANSI color output (for CI) |

### generate-stubs command

```
wp-specter generate-stubs [path] [--output=<file>]
```

Scans a directory (typically a vendored/third-party plugin outside the main scan) for `do_action`/`apply_filters` calls and writes discovered hook tags — and, where a call's tag is dynamic but has a resolvable literal prefix (e.g. `apply_filters("acf/settings/{$name}", ...)`), the prefix — to a JSON file. That file is then passed to `scan --stubs=` (or auto-loaded via `.wp-specter.stubs.json`) to suppress hooks fired by code outside what's being scanned.

With no `path` argument, falls back to the `stubsFrom` list in `.wp-specter.config.json` if present, scanning every declared source in one shot.

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

- **Collect:** All `function foo(...)` definitions across `.php` files in the project. Class methods are excluded from this check — see 3e.
- **Mark used** if the function name appears as a call `foo(` anywhere in the project (including string callbacks like `'foo'` passed to `add_action`, and array callbacks)
- **Always exclude:** Functions prefixed with `__` (magic), functions whose name matches a known WP core prefix list (`wp_`, `get_`, `the_`, `is_`, `has_`, `do_`, `apply_`)
- Dynamic call sites (variable function names) simply aren't tracked as evidence of use — no special "uncertain" state; a function only ever invoked that way looks unused, a known, accepted trade-off

### 3b. WP Hooks (`--type=hooks`)

- **Collect:** All `add_action($tag, $cb)` and `add_filter($tag, $cb)` registrations
- **Mark matched** if the same literal `$tag` string appears in `do_action($tag)`/`apply_filters($tag)` (or a `wp_schedule_event`/`wp_schedule_single_event` cron hook) within the project, **or** matches a prefix from a dynamically-dispatched hook family (see below)
- WordPress core hooks (~400 known actions/filters) are silently ignored via a built-in stub list. Third-party plugin hooks are matched for real when that plugin's code is part of the scan (composer project mode); otherwise use `generate-stubs`/`stubsFrom` against that plugin's source
- **Dynamic-prefix hooks:** a firing call whose tag is dynamic but has a resolvable literal prefix — an interpolated string (`"acf/settings/{$name}"`) or a `'literal' . $var` concatenation — contributes that prefix as a match for any registration in the same family. This is how a single dynamic dispatcher (ACF's settings system, WP core's `option_{$name}` pattern, etc.) is recognized without a hardcoded per-plugin hook list, both within one scan and via `generate-stubs`-produced `prefixes` entries in a stubs file
- **Skip:** Dynamic tag names with no resolvable prefix at all (a bare variable, a function-call result)
- Report unmatched hooks as **"not fired within project"** — Warning certainty (⚠), not Error (✗)

### 3c. Template Files (`--type=templates`)

- **Collect:** All `.php` files in theme template directories (`templates/`, `template-parts/`, `parts/`) and root-level files matching the WP template hierarchy
- **Mark used** if referenced by `get_template_part()`, `get_header()`, `get_footer()`, `get_sidebar()`, `include`, or `require` across all scanned `.php` files
- **Classic/Hybrid mode:** WP template hierarchy names (`single.php`, `archive.php`, `page.php`, etc.) and their `{hierarchy}-{slug}.php` variants are automatically exempt
- **Block mode:** also scans `block.json` `render` fields as references

### 3d. Orphaned Files (`--type=files`)

- **Collect:** PHP files outside the template directories/hierarchy covered by 3c — support code in `inc/`, `classes/`, `admin/`, etc.
- **Mark used** if referenced by `include`/`require`, page-template registration (`Template Name:` header), or a `block.json` field
- **Always exclude:** any `index.php` (directory-listing blockers / sibling autoloaders) and files declaring `Template Name:` (WP Page Templates, loaded by slug via URL routing, not a literal reference)

### 3e. Classes and Methods (`--type=classes`)

- **Collect:** All `class Foo {}`, `interface Foo {}`, `trait Foo {}`, and `enum Foo {}` declarations
- **Mark a class/interface/trait/enum used** if referenced anywhere via `new Foo()`, `Foo::method()`/`Foo::CONST`/`Foo::class`, `instanceof Foo`, `extends`/`implements Foo`, or `use Foo;` inside a class/trait/enum body. Purely dynamic instantiation (`$c = 'Foo'; new $c()`) or class names passed only as strings to WP APIs (`register_widget('Foo')`) aren't recognized — syntactic references only
- **Collect:** All class/trait method definitions (magic methods excluded)
- **Mark a method used** if called with a statically-resolvable receiver — `$this->method()`, `self::`/`parent::`/`static::method()`, a literal `Class::method()`, the equivalent array-callback shapes (`[$this, 'method']`, `[Class::class, 'method']`, `['Class', 'method']`, both `[...]` and `array(...)` syntax), or a local variable directly assigned `new ClassName(...)` earlier in the same function/method body — scoped per declaring class, so two unrelated classes sharing a method name don't suppress each other's findings. Anything that can't be resolved to a class this way (an arbitrary-typed variable, a property, a function's return value) falls back to a name-only match across the whole project — the source of this check's Warning (not Error) certainty
- **Trait methods:** a trait method is scoped to the trait's own name (so an intra-trait `$this->` call resolves precisely), but is never "called on the trait" directly — it's marked used when *any* class or trait that `use`s the trait, directly or transitively, calls it through a scoped receiver
- **Contract exemption:** a method required by a contract the *declaring* class directly `implements`/`extends` (not further up the chain) is exempt: PHP SPL interfaces (`ArrayAccess`, `Iterator`, `IteratorAggregate`, `Countable`, `JsonSerializable`, `Serializable`) and WordPress base classes (`WP_Widget`, `WP_REST_Controller`, `Walker`)

---

## 4. Project Layout Auto-Detection

Run at scan start before any analysis. Results logged to output header.

### 4a. Composer-managed projects

`scan <path>` tries direct theme/plugin detection first (unchanged, and always wins if it
succeeds). Only when that fails does `ComposerProjectDetector` walk upward from `<path>` looking
for a `composer.json`. If found, and its `extra.installer-paths` declares `type:wordpress-theme`
/ `wordpress-plugin` / `wordpress-muplugin` rules, every subdirectory under those declared paths
is treated as a scan target — **except** ones `vendor/composer/installed.json` records as
composer-installed (matched by its `install-path` field). That means a wpackagist-downloaded
theme sitting right next to your own custom theme is excluded automatically, with no naming
heuristics involved — this generalizes beyond Bedrock to any `composer/installers`-based layout.

Function/hook/class analysis runs once across every discovered target's files (so a theme
registering a hook or referencing a class that its companion plugin fires/defines isn't a false
"unmatched"); template/file analysis runs per-target since each needs its own root for
hierarchy/root-level detection.

mu-plugins is a special case: WP auto-loads any `.php` file placed directly inside `mu-plugins/`
(not just per-package subdirectories), so loose files there are scanned as their own pseudo-target
with no WP mode (no template hierarchy applies to them).

If a path resolves to neither a theme/plugin nor a composer project, it's scanned as-is with mode
`unknown`.

### 4b. Theme / Plugin Mode

After resolving the scan target(s):

| Signal | Mode |
|---|---|
| `theme.json` present in a theme dir | Block (FSE) |
| `block.json` present anywhere under theme dir | Block |
| `style.css` with `Theme Name:` header, no `theme.json` | Classic theme |
| `*.php` with `Plugin Name:` header | Plugin |
| Both `theme.json` and `functions.php` in same theme | Hybrid (both rule sets apply) |
| None of the above | `unknown` — scanned, but template-hierarchy exemptions don't apply |

### 4c. Project config and stubs files

Two optional, auto-discovered files (walking upward from the scanned path, same convention as `composer.json`):

- **`.wp-specter.config.json`** — `targets` (exact theme/plugin dirs to scan, overrides auto-detection when scanning the project as a whole), `stubsFrom` (dirs `generate-stubs` scans with no path argument), `stubs` (override for where the project stubs file lives), `exclude` (directory names/relative paths pruned from every scan and `generate-stubs` run, on top of the always-on vendor/node_modules/.git defaults — not resolved to absolute paths, so a bare name like `"tests"` matches under any scanned target).
- **`.wp-specter.stubs.json`** — what `generate-stubs` writes (`hooks` + `prefixes`); auto-loaded by `scan` with no flag needed, additive with an explicit `--stubs=`.

---

## 5. Project Structure

```
wp-specter/
├── bin/
│   └── wp-specter                    # Shebang entry point (#!/usr/bin/env php)
├── src/
│   ├── Analyzer/
│   │   ├── ClassAnalyzer.php         # Unused classes + methods
│   │   ├── FileAnalyzer.php          # Orphaned PHP files
│   │   ├── FunctionAnalyzer.php
│   │   ├── HookAnalyzer.php
│   │   └── TemplateAnalyzer.php
│   ├── Composer/
│   │   └── ComposerProjectDetector.php  # Composer-managed project + target discovery
│   ├── Detector/
│   │   └── WpModeDetector.php        # Auto-detect classic/block/plugin/hybrid
│   ├── Enum/
│   │   └── WpMode.php
│   ├── Finding/
│   │   ├── Finding.php
│   │   ├── FindingCertainty.php      # Error vs Warning
│   │   └── FindingType.php
│   ├── Parser/
│   │   ├── PhpTokenParser.php        # Wraps PHP token_get_all()
│   │   └── ...                       # Value objects: FunctionDef, FunctionCall, ClassDef,
│   │                                 #   ScopedMethodCall, HookRegistration, HookInvocation,
│   │                                 #   TemplateRef, ParseResult
│   ├── ProjectConfig/
│   │   ├── ProjectConfig.php
│   │   └── ProjectConfigLoader.php   # .wp-specter.config.json / .wp-specter.stubs.json
│   ├── Reporter/
│   │   └── TerminalReporter.php      # ANSI table output
│   ├── Scan/
│   │   ├── ProjectInfo.php
│   │   └── ScanTarget.php
│   ├── Scanner/
│   │   ├── FileScanner.php           # Recursive file collection + ignore filtering
│   │   └── ScanResult.php
│   ├── Stubs/
│   │   ├── HookStub.php
│   │   ├── StubRegistry.php
│   │   └── WpCoreHooks.php           # Built-in ~400 WP core hooks
│   ├── Support/
│   │   └── PathWalker.php
│   ├── Application.php               # Wires everything together, handles CLI args
│   └── Config.php
├── tests/
│   ├── fixtures/
│   │   ├── classic-theme/            # Minimal classic theme
│   │   ├── block-theme/              # Minimal block theme
│   │   ├── bedrock-project/          # Minimal Bedrock-style layout (web/app/themes|plugins)
│   │   └── standard-wp/              # Traditional wp-content/ layout
│   ├── unit/
│   └── integration/
├── composer.json
├── phpstan.neon.dist
├── .php-cs-fixer.dist.php
├── PARSING-TODO.md                   # Known parsing/detection gaps
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
- Use PHP's built-in `token_get_all()` for PHP parsing — no third-party AST library
- **PHP-CS-Fixer** (`@PER-CS2.0` + `@PER-CS2.0:risky`, strict-types enforced, alphabetically-ordered imports) — config in `.php-cs-fixer.dist.php`; excludes `tests/fixtures/` (deliberately-styled fake WP files, not this project's code)
- **PHPStan level 8**, clean with no baseline — config in `phpstan.neon.dist`; same `tests/fixtures/` exclusion

---

## 7. Testing Strategy

- **Unit tests** (PHPUnit): Each analyzer, the parser, and supporting classes (detectors, stubs, project config) tested in isolation
- **Integration tests**: Run `wp-specter scan`/`generate-stubs` end-to-end against all four `tests/fixtures/` layouts (`classic-theme`, `block-theme`, `standard-wp`, `bedrock-project`) plus dedicated project-config and generate-stubs scenarios, asserting finding names/counts and CLI output/exit codes
- **No mocking of the filesystem** — integration tests use real fixture directories under the OS temp dir
- `composer test` runs PHPUnit; `composer lint` / `lint:fix` run PHP-CS-Fixer; `composer phpstan` runs PHPStan; `composer check` runs all three

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
- Write to any file in the scanned project (`generate-stubs`' own output file, written to a path the user chose, is the one deliberate exception)
- Require a running WP installation

---

## 9. Out of Scope (v1)

- CSS class analysis
- JS/TS dead code
- Translation string audit
- JSON/HTML report formats (terminal only)
- WP-CLI integration
- Multi-project / workspace scanning
- Real type inference (property types, type-hinted parameters, return-type-based inference, control-flow-aware variable tracking) — the parser resolves what it can from local syntax alone, documented trade-offs in `PARSING-TODO.md`
