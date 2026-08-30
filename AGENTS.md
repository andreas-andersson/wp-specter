# AGENTS.md

Instructions for AI coding agents working in this repository. Read this before making changes.
For what the tool does and how end users run it, see `README.md`; for the full feature spec, see
`SPEC.md`; for the detailed history of every gap found and fixed (or deliberately left alone),
see `TODO.md`.

## What this is

`wp-specter` is a static-analysis CLI for WordPress projects (PHP 8.4+). It finds unused
functions, classes, methods, unmatched hooks, unused templates, and orphaned files in a
theme/plugin. Architecture: `PhpTokenParser` (`src/Parser/PhpTokenParser.php`) is a single-pass,
no-AST tokenizer — it walks PHP's own `token_get_all()` output once and builds a `ParseResult` per
file. Five analyzers (`src/Analyzer/*.php` — `FunctionAnalyzer`, `ClassAnalyzer`, `HookAnalyzer`,
`TemplateAnalyzer`, `FileAnalyzer`) each merge `ParseResult`s across every scanned file and report
findings. There is no PHP execution, no AST library, and no dataflow/control-flow analysis —
everything is pattern-matching over tokens, deliberately.

## The one rule that overrides everything else

**Never fix a false positive/negative by hard-coding one plugin's or theme's specific names,
paths, or internals. Always generalize the parser/analyzer logic so the fix works for any code
following the same pattern.**

A real-world plugin or theme is evidence that a gap exists, and later, proof that a fix works —
it is never the *target* of the fix. If you catch yourself writing `if ($name === 'some_plugin_
specific_function')`, stop: you haven't found the general pattern yet, keep digging.

This does **not** forbid curated name lists — `BASE_CLASS_CONTRACT_METHODS`,
`FULLY_EXEMPT_BASE_CLASSES`, `TEMPLATE_FUNCS`, `WP_OBJECT_CACHE_DROPIN_FUNCS`, `CRON_SCHEDULE_
FUNCS` in this codebase are all curated lists, and that's fine. The test is: **does this list
encode a real WordPress-core, PHP-language, or widely-adopted-framework/library convention that
any project could hit, or does it only work because you hard-coded one specific plugin's own
naming?** `wp_cache_get`/`WP_UnitTestCase`/`as_schedule_recurring_action` are WP-core/PHPUnit/
Action-Scheduler's own reserved names — safe to list by name. `wpforms_render`/`acf_get_view`
would be one plugin's own invented name — not safe to hard-code; if you find yourself tempted to
add one, look for the *shape* instead (e.g. `TEMPLATE_FUNCS` is matched by a `*_get_template_part`
suffix, not a name list, precisely because that shape recurs across independent plugins).

When in doubt, ask: "if I found this same pattern in a plugin nobody has scanned yet, would my fix
already handle it?" If the answer is no, the fix isn't general enough yet.

## Design philosophy: coarse net, not proven causality

This parser doesn't prove a piece of code is used — it looks for cheap, reliable *signals* that
correlate with real usage, and accepts the resulting imprecision as a deliberate trade-off rather
than chasing full soundness. Examples: a `glob()` call plus an `include`/`require` keyword
*anywhere in the same file* is treated as a bulk-include, without proving the glob's result is
what actually gets required. A scoped method call with a literal first argument is treated as a
candidate directory-loader once the callee's own body is found to contain an include keyword,
resolved only after every file's parse is merged — again, correlation, not dataflow proof. Follow
this same spirit for new fixes: a bounded, well-reasoned heuristic beats an attempt at full static
soundness, which is not achievable in a single-pass tokenizer without an AST or type system
anyway.

## Known, permanent architectural limits — do not attempt to "fix" these

These are documented in `TODO.md` as intentionally-unchecked items, not open bugs:
- `new $var()` with a computed/concatenated class name is unresolvable (no type inference).
- Local variable type tracking has no control-flow/branch awareness — `if ($c) { $x = new A(); }
  else { $x = new B(); }` only remembers whichever assignment is last in source order.
- Braced `namespace X { ... }` / bare `namespace { ... }` forms are unsupported (zero real-world
  evidence across the whole corpus justified not building it).
- A third-party dependency's own code under `vendor/` (or an equivalent vendor-prefixed directory)
  is never checked for its own internal dead code — by design, this tool only audits first-party
  project code.

Each would require real dataflow/CFG analysis or an AST — a fundamentally different architecture,
not a bounded fix. If you find a new instance of one of these, it's expected behavior, not a
regression.

## The workflow for every fix

1. **Find or receive a gap** — a concrete false positive or false negative, ideally with real
   evidence from the test corpus (see below), not a hypothetical.
2. **Identify the general pattern** behind it (see "the one rule" above) before writing any code.
3. **Implement the fix** in the parser/analyzer, with an inline comment explaining the real-world
   shape that motivated it (see "code comments" below).
4. **Add test coverage** — a `PhpTokenParserTest` case for parser-level changes, plus an
   `Analyzer`-level end-to-end case (`ClassAnalyzerTest`, `FileAnalyzerTest`, etc.) modeled
   directly on the real evidence. Include a negative case proving the fix doesn't over-match.
5. **Run the full verification suite**: `composer check` (runs `php-cs-fixer` in dry-run mode,
   `phpstan analyse`, and the full PHPUnit suite). All three must be clean — phpstan must report
   zero errors, PHPUnit must be 100% green. Never leave a failing test or a phpstan error "for
   later."
6. **Verify against the real-world corpus** (see below): run the CLI against every affected
   target before and after, confirm the specific finding changed as expected, and confirm no
   *other* target's finding count changed unexpectedly (a silent regression) and nothing crashes.
7. **Document the fix in `TODO.md`** — see the convention below. This is not optional; the whole
   value of this file is a complete, honest history of what was tried, what worked, what was
   reverted, and why.
8. **Report back concisely** and wait for direction before starting unrelated new work. Don't
   bundle unrelated fixes into one change.

## The real-world test corpus

This project has no bundled fixture corpus of real plugins/themes, and none is checked into this
repo — every contributor's machine is different. **If you have a local folder of real-world
WordPress plugins/themes, tell the agent its path at the start of the session** (e.g. "use
`~/wp-plugins/` as the test corpus" or "verify against `/path/to/some-real-plugin`"); if you
don't have one yet, grab a handful of large, actively-maintained plugins/themes (WooCommerce,
Yoast SEO, a popular theme, etc. — variety matters more than count) and point the agent at that
directory. Whatever the path, it's used two ways:
- **Gap-hunting**: run `./bin/wp-specter scan <target> --no-color --type=all` against real
  plugins/themes and manually trace any suspicious finding back to the actual source to confirm
  whether it's a genuine tool bug or a correct finding.
- **Verification**: after any fix, re-scan the specific target(s) affected to confirm the
  finding changed as expected, and do a full sweep across *every* target in the corpus to confirm
  no other finding count changed and nothing crashed (`exit 1` = findings present, expected;
  anything else, or a stack trace, is a real problem).

A single plugin/theme is evidence, not a target — see "the one rule" above. Never hard-code a
path to your own local corpus anywhere in this repo's source, tests, or docs — the corpus is
supplied per-session by whoever is using the agent, not part of the project itself.

## Documenting a fix in TODO.md

Every fixed gap gets a `- [x] **One-sentence summary of the bug.**` entry containing, in prose:
root cause, the real-world evidence (which plugin/theme, file path, the actual code shape),
what the fix does and why it generalizes, and a verification paragraph (new tests added, the
specific finding resolved with before/after numbers, full corpus sanity pass results, final
PHPUnit/phpstan status). If a fix has a known scope limitation, say so explicitly rather than
leaving it to be rediscovered — see the many "**Scope limitation:**" callouts throughout the file
for the expected tone. An item that was investigated and deliberately *not* fixed (evidence too
thin, fix would trade too much precision elsewhere, genuinely intractable) still gets documented
this way — TODO.md's job is to prevent re-litigating settled decisions, not just to log fixes.

## Code comments: explain WHY with real evidence, not WHAT

This codebase comments more heavily than a typical minimal-comment style, and that's deliberate —
match it. A comment should name the real-world plugin/theme and code shape that motivated a piece
of logic, and explain why the chosen approach is correct or safe, not restate what the code
obviously does. Compare the existing comments in `src/Parser/PhpTokenParser.php` and
`src/Analyzer/ClassAnalyzer.php` for the expected density and tone before adding new logic —
docblocks on non-trivial private methods routinely run to a full paragraph explaining the shape
they recognize and why narrower/safer alternatives were rejected.

## Gap-hunting at scale

When asked to hunt for new gaps across the corpus, splitting the work (e.g. one pass over themes,
one over plugins) and cross-referencing each candidate finding against the actual source before
reporting it is far more reliable than skimming CLI output. Always exclude already-fixed patterns
and already-documented accepted limitations from a fresh search — check `TODO.md` first.
