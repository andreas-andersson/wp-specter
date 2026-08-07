# Implementation Plan: wp-specter

## Overview

Standalone PHP 8.1+ CLI tool that scans WordPress projects (classic, block/FSE, Bedrock, standard) and reports unused PHP functions, unmatched hook registrations, and unreferenced template files. Static analysis only — no WP runtime required.

## Architecture Decisions

- **`token_get_all()` for parsing** — no third-party AST lib; keeps the tool a single `composer install` away with minimal dependencies
- **Detectors run first, analyzers second** — content dir and WP mode are resolved once, then passed as immutable config to all analyzers
- **Analyzers are stateless** — each receives a list of files and returns findings; no shared mutable state
- **Two-pass analysis** — pass 1 collects all definitions/registrations, pass 2 checks references; avoids ordering issues

## Dependency Graph

```
composer.json + bin/wp-specter (Task 1)
        │
        ├── Application.php / CLI parsing (Task 2)
        │
        ├── FileScanner (Task 3)
        │       │
        │       └── ContentDirDetector (Task 3)
        │               │
        │               └── WpModeDetector (Task 4)
        │
        └── PhpTokenParser (Task 5)
                │
                ├── FunctionAnalyzer (Task 6)
                ├── HookAnalyzer (Task 7)
                └── TemplateAnalyzer (Task 8) ← also needs WpModeDetector
                        │
                        └── TerminalReporter (Task 9)
                                │
                                └── Wire Application.php (Task 10)
                                        │
                                        ├── Test fixtures (Task 11)
                                        └── Integration tests (Task 12)
```

---

## Task List

### Phase 1: Foundation

- [ ] **Task 1** — Project scaffold
- [ ] **Task 2** — CLI argument parsing

### Checkpoint 1
- [ ] `composer install` works
- [ ] `bin/wp-specter help` and `bin/wp-specter version` print output
- [ ] `bin/wp-specter scan --help` shows all flags

### Phase 2: Detection Infrastructure

- [ ] **Task 3** — FileScanner + ContentDirDetector
- [ ] **Task 4** — WpModeDetector

### Checkpoint 2
- [ ] Scanner correctly resolves content dir for standard-wp and Bedrock layouts
- [ ] Mode detector identifies classic/block/plugin/hybrid correctly

### Phase 3: Parsing + Analyzers

- [ ] **Task 5** — PhpTokenParser
- [ ] **Task 6** — FunctionAnalyzer
- [ ] **Task 7** — HookAnalyzer
- [ ] **Task 8** — TemplateAnalyzer

### Checkpoint 3
- [ ] All analyzer unit tests pass
- [ ] Each analyzer returns correct findings against hand-crafted fixture strings

### Phase 4: Reporter + Wiring

- [ ] **Task 9** — TerminalReporter
- [ ] **Task 10** — Wire Application.php end-to-end

### Checkpoint 4
- [ ] `bin/wp-specter scan tests/fixtures/classic-theme` produces colored table output
- [ ] Exit codes correct (0 = clean, 1 = findings, 2 = error)

### Phase 5: Tests

- [ ] **Task 11** — Test fixtures (4 layouts)
- [ ] **Task 12** — Integration tests

### Checkpoint 5 (Done)
- [ ] `composer test` passes
- [ ] All four fixture layouts produce correct finding counts
- [ ] `--no-color` disables ANSI codes
- [ ] `--wp-content` override respected

---

## Task Details

### Task 1: Project scaffold

**Description:** Create `composer.json` with PSR-4 autoload under `WpSpecter\`, PHPUnit dev dependency, PHP-CS-Fixer dev dependency, and `bin/wp-specter` shebang entry point. Establish full directory structure with empty placeholder files.

**Acceptance criteria:**
- [ ] `composer install` succeeds
- [ ] `vendor/autoload.php` correctly resolves `WpSpecter\` namespace to `src/`
- [ ] `bin/wp-specter` is executable and runs without errors
- [ ] `composer test` and `composer lint` scripts defined

**Verification:** `composer install && php bin/wp-specter`

**Dependencies:** None

**Files:**
- `composer.json`
- `bin/wp-specter`
- `src/Application.php` (stub)

**Scope:** S

---

### Task 2: CLI argument parsing

**Description:** Implement `Application.php` to parse `argv`: subcommand (`scan`, `help`, `version`), positional `path` arg, and all flags (`--wp-content`, `--type`, `--ignore`, `--verbose`, `--no-color`). Return correct exit codes. `help` and `version` subcommands fully functional.

**Acceptance criteria:**
- [ ] `wp-specter version` prints version string, exits 0
- [ ] `wp-specter help` prints usage text with all flags documented, exits 0
- [ ] Unknown subcommand prints error, exits 2
- [ ] `--type=functions,hooks` parsed into array correctly
- [ ] `--ignore` accepts comma-separated globs
- [ ] `path` defaults to cwd when omitted

**Verification:** Manual invocation of each subcommand

**Dependencies:** Task 1

**Files:**
- `src/Application.php`
- `src/Config.php` (value object holding parsed args)

**Scope:** S

---

### Task 3: FileScanner + ContentDirDetector

**Description:** `ContentDirDetector` resolves the wp-content directory using the detection chain from the spec (flag → Bedrock → installer-paths → wp-content/ → app/ → warn). `FileScanner` recursively collects `.php` files under the resolved content dir, respecting ignore globs and always excluding `vendor/`, `node_modules/`, `.git/`, `web/wp/`.

**Acceptance criteria:**
- [ ] Bedrock layout (`web/app/`) detected from `composer.json` with `roots/bedrock`
- [ ] Standard `wp-content/` detected
- [ ] `--wp-content` override takes precedence
- [ ] `vendor/` always excluded even if not in `--ignore`
- [ ] `mu-plugins/` included in file collection
- [ ] Returns `[]` (with warning) when no layout detected

**Verification:** Unit tests against fixture directory trees

**Dependencies:** Task 2

**Files:**
- `src/Detector/ContentDirDetector.php`
- `src/Scanner/FileScanner.php`
- `tests/unit/Detector/ContentDirDetectorTest.php`
- `tests/unit/Scanner/FileScannerTest.php`

**Scope:** M

---

### Task 4: WpModeDetector

**Description:** Given a resolved theme directory, detect mode: Block (FSE), Classic, Plugin, or Hybrid. Used by TemplateAnalyzer to choose which template hierarchy names to auto-exclude.

**Acceptance criteria:**
- [ ] `theme.json` → Block
- [ ] `block.json` anywhere under dir → Block
- [ ] `style.css` with `Theme Name:` + no `theme.json` → Classic
- [ ] `Plugin Name:` in any `.php` header → Plugin
- [ ] `theme.json` + `functions.php` → Hybrid
- [ ] Unknown → returns null (no crash)

**Verification:** Unit tests

**Dependencies:** Task 3

**Files:**
- `src/Detector/WpModeDetector.php`
- `src/Enum/WpMode.php`
- `tests/unit/Detector/WpModeDetectorTest.php`

**Scope:** S

---

### Task 5: PhpTokenParser

**Description:** Thin wrapper around `token_get_all()` that extracts structured data from a PHP file: function definitions (name + line), function calls (name + line), `add_action`/`add_filter` calls (tag literal + callback + line), `do_action`/`apply_filters` calls (tag literal + line), and `get_template_part`/`get_header`/`get_footer`/`get_sidebar`/`include`/`require` references (path literal + line). Returns arrays of typed value objects.

**Acceptance criteria:**
- [ ] Extracts function defs from a file with 3 functions
- [ ] Extracts function calls including string callbacks in `add_action`
- [ ] Extracts literal hook tags; skips variable tags
- [ ] Extracts `get_template_part('parts/hero')` path literal
- [ ] Reports correct line numbers for all extractions
- [ ] Handles parse errors gracefully (returns empty result + error message)

**Verification:** Unit tests with inline PHP strings

**Dependencies:** Task 1

**Files:**
- `src/Parser/PhpTokenParser.php`
- `src/Parser/ParseResult.php` (value object)
- `tests/unit/Parser/PhpTokenParserTest.php`

**Scope:** M

---

### Task 6: FunctionAnalyzer

**Description:** Two-pass analyzer. Pass 1: collect all function definitions across all scanned files. Pass 2: collect all calls (direct calls + string callbacks). Return functions defined but never called. Exclude magic functions and WP-prefixed names per spec.

**Acceptance criteria:**
- [ ] Unused function reported with file:line
- [ ] Called function not reported
- [ ] String callback `'my_func'` in `add_action` counts as a call
- [ ] `wp_*`, `get_*`, `the_*`, `is_*`, `has_*`, `do_*`, `apply_*` prefixes excluded
- [ ] `__construct`, `__toString` etc. excluded
- [ ] Dynamic callbacks flagged as uncertain, not unused

**Verification:** Unit tests

**Dependencies:** Task 5

**Files:**
- `src/Analyzer/FunctionAnalyzer.php`
- `src/Finding/Finding.php` (value object: type, name, file, line, certainty)
- `tests/unit/Analyzer/FunctionAnalyzerTest.php`

**Scope:** M

---

### Task 7: HookAnalyzer

**Description:** Collects all `add_action`/`add_filter` registrations (literal tags only). Checks if each tag also appears in a `do_action`/`apply_filters` call within the project. Unmatched → Finding with certainty=`warning` (⚠, not ✗). Skips dynamic tags with a log note.

**Acceptance criteria:**
- [ ] Hook registered and fired within project → not reported
- [ ] Hook registered but not fired within project → reported as warning (⚠)
- [ ] Dynamic tag (variable) → skipped, logged as unresolvable
- [ ] `add_filter` handled same as `add_action`
- [ ] `apply_filters` handled same as `do_action`

**Verification:** Unit tests

**Dependencies:** Task 5

**Files:**
- `src/Analyzer/HookAnalyzer.php`
- `tests/unit/Analyzer/HookAnalyzerTest.php`

**Scope:** S

---

### Task 8: TemplateAnalyzer

**Description:** Collects template `.php` files from `templates/`, `template-parts/`, `parts/` subdirs and root-level WP hierarchy names. Checks each against `get_template_part`, `get_header`, `get_footer`, `get_sidebar`, `include`, `require` references across all scanned files. Classic mode: exclude standard hierarchy names. Block mode: also check `block.json` render fields.

**Acceptance criteria:**
- [ ] Unreferenced template part reported with file:line
- [ ] Template referenced via `get_template_part` not reported
- [ ] `single.php` in classic theme not reported (WP hierarchy)
- [ ] Block mode reads `block.json` render fields as references
- [ ] `functions.php` never flagged as a template

**Verification:** Unit tests

**Dependencies:** Task 4, Task 5

**Files:**
- `src/Analyzer/TemplateAnalyzer.php`
- `tests/unit/Analyzer/TemplateAnalyzerTest.php`

**Scope:** M

---

### Task 9: TerminalReporter

**Description:** Takes a list of `Finding` objects and renders a colored terminal table grouped by type (Functions / Hooks / Templates). Each row: icon (✗ or ⚠), name, file:line. Footer: summary counts. Respects `--no-color`. Renders scan header: project path, detected layout, WP mode, file count.

**Acceptance criteria:**
- [ ] Groups findings by type with section headers
- [ ] ✗ for unused (error), ⚠ for unmatched hooks (warning)
- [ ] file:line clickable-style format
- [ ] `--no-color` strips all ANSI codes
- [ ] Empty section not rendered if no findings of that type
- [ ] Summary line: "X unused functions, Y unmatched hooks, Z unused templates"

**Verification:** Snapshot test comparing output string (with `--no-color`)

**Dependencies:** Task 6, Task 7, Task 8

**Files:**
- `src/Reporter/TerminalReporter.php`
- `tests/unit/Reporter/TerminalReporterTest.php`

**Scope:** S

---

### Task 10: Wire Application.php end-to-end

**Description:** Connect all components in `Application::run()`: parse args → detect content dir → detect mode → scan files → run requested analyzers → render report → exit with correct code.

**Acceptance criteria:**
- [ ] `scan` with no findings exits 0
- [ ] `scan` with findings exits 1
- [ ] `scan` on invalid path exits 2 with error message
- [ ] `--type=functions` only runs FunctionAnalyzer
- [ ] Header shows: path, layout (standard/bedrock/custom), mode, file count
- [ ] `--verbose` shows reference locations alongside findings

**Verification:** Manual smoke test on a real WP theme dir

**Dependencies:** Task 2, Task 3, Task 4, Task 9

**Files:**
- `src/Application.php`

**Scope:** S

---

### Task 11: Test fixtures

**Description:** Create minimal but realistic fixture directories for each layout type. Each fixture must contain at least one unused function, one unmatched hook, and one unreferenced template so integration tests can assert findings.

**Layouts:**
- `tests/fixtures/classic-theme/` — classic WP theme with `style.css`, `functions.php`, `template-parts/`
- `tests/fixtures/block-theme/` — FSE theme with `theme.json`, `block.json`, `parts/`
- `tests/fixtures/standard-wp/` — full `wp-content/` with `themes/`, `plugins/`, `mu-plugins/`
- `tests/fixtures/bedrock-project/` — `composer.json` with `roots/bedrock`, `web/app/themes|plugins|mu-plugins/`

**Acceptance criteria:**
- [ ] Each fixture has ≥1 intentionally unused function
- [ ] Each fixture has ≥1 intentionally unmatched hook
- [ ] Each fixture has ≥1 intentionally unreferenced template part
- [ ] Fixture also contains ≥1 used function, ≥1 matched hook, ≥1 referenced template (to prove no false positives)

**Dependencies:** Task 1

**Files:** All under `tests/fixtures/`

**Scope:** M

---

### Task 12: Integration tests

**Description:** PHPUnit integration tests that invoke `Application::run()` against each fixture directory and assert exact finding names, files, and counts.

**Acceptance criteria:**
- [ ] Classic-theme fixture: expected findings match exactly
- [ ] Block-theme fixture: expected findings match exactly
- [ ] Standard-wp fixture: expected findings match exactly
- [ ] Bedrock fixture: expected findings match exactly
- [ ] `--type=functions` returns only function findings
- [ ] `--no-color` output contains no ANSI escape codes
- [ ] `--wp-content` override correctly redirects scanning

**Verification:** `composer test` passes

**Dependencies:** Task 10, Task 11

**Files:**
- `tests/integration/ClassicThemeTest.php`
- `tests/integration/BlockThemeTest.php`
- `tests/integration/StandardWpTest.php`
- `tests/integration/BedrockProjectTest.php`

**Scope:** M

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| `token_get_all()` misses edge cases (heredocs, nowdocs, complex strings) | Med | Explicit unit tests for edge cases; skip/warn on unresolvable tokens |
| False positives on WP-prefixed functions defined in project | Med | Prefix exclusion list in spec; make it configurable in v2 |
| Hook tags that are only fired by WP core flood the output | High | ⚠ (warning) vs ✗ (error) distinction; user can filter with `--type` |
| Bedrock `composer.json` detection too narrow | Low | Fallback chain covers `app/` layout; `--wp-content` always available |
| Template references via PHP variables not detected | Med | Flag as known limitation; only literal string args resolved |

## Open Questions

- None — spec is complete and approved
