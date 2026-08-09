# Parsing / Class & Method Detection — Open Issues

Known gaps in `PhpTokenParser` and the class/method-unused analysis built on top of it
(`ClassAnalyzer`). Nothing here is a correctness bug in what's shipped — each is a documented
scope limit that trades recall or precision for staying a single-pass, no-dependency tokenizer
(no AST, no type inference). Recorded here so they don't get re-discovered from scratch.

## Class detection

- [x] **Interfaces/traits/enums are never tracked as a `ClassDef`.** Fixed: `T_INTERFACE`/
  `T_TRAIT`/`T_ENUM` now get their own `parseClassDef` call (kind param: `'class'|'interface'|
  'trait'|'enum'`, stored on `ClassDef::$kind`), so `ClassAnalyzer::findUnusedClasses` — which
  already iterated `classDefsByName` generically — now flags unused interfaces/traits/enums too
  (with a `note` on the `Finding` distinguishing which kind). Deliberately does NOT set
  `$pendingClassName`/`$pendingClassParent` for these bodies — a trait's methods are called on
  whatever class `use`s the trait, not the trait itself, so they must stay on the unscoped
  fallback pool exactly as before (attributing them to the trait's own name would create false
  positives). Also fixed the bundled gap: `use TraitName;` directly inside a class/trait/enum
  body (guarded by brace-depth so it's not confused with a top-level namespace `use` import or a
  closure's `function() use (...)`) is now captured as a `classReference`.

- [ ] **Class names passed as bare strings to WP APIs aren't references.** `register_widget('My_Widget')`,
  `is_a($x, 'My_Class')`, `class_exists('My_Class')` — none of these produce a `classReferences`
  entry, since only syntactic references (`new`, `instanceof`, `extends`/`implements`, `::`) are
  tracked. A class ONLY ever reached this way looks unused.
  - Fix shape: same heuristic already used for function string-callbacks
    (`looksLikeCallback` + a curated list of WP functions that take a class name string) could
    feed `classReferences` too — but risks the same class of false-negative suppression the
    project already accepts for function detection, just now on the class side.

- [ ] **Dynamic instantiation is unresolvable.** `$class = 'My_Class'; new $class();` — `captureClassNameAfter`
  requires a literal identifier token right after `new`; a variable never resolves. Same for
  `new $this->widgetClass()`. This is inherent to a token-based parser without type inference —
  not realistically fixable without a much bigger rewrite. Documented here so it's not
  re-investigated as a "bug."

## Method detection

- [x] **Contract-method exemption (`ClassAnalyzer::isContractMethod`) only checks the declaring
  class's own `extends`/`implements`, not the full inheritance chain.** Fixed: `isContractMethod`
  now walks `$classDefsByName` via `$def->extends[0]` (bounded by `MAX_INHERITANCE_DEPTH = 50` to
  survive a cycle/bad input), checking `implements` at every level visited too — so a class that
  extends `My_Base_Widget`, which itself extends `WP_Widget`, still gets the
  `widget()`/`form()`/`update()` exemption, and an interface attached higher up the chain rather
  than redeclared on every subclass is still honored.

- [ ] **Property types aren't tracked.** `$this->service = new My_Service(); $this->service->render();`
  — local variable tracking (`$varTypesStack`) only covers local variables, not object
  properties. `$this->service->render()` falls back to the unscoped/name-only pool.
  - Fix shape: would need a per-class (not per-function) property-type map, populated from
    `$this->prop = new ClassName()` assignments seen anywhere in the class body, and consulted
    for `$this->prop->method()` — meaningfully bigger than local-variable tracking since it's
    class-scoped rather than function-scoped and has to survive being set in one method and read
    in another.

- [x] **Type-hinted parameters don't seed variable tracking or count as class references.**
  Fixed: `parseParamTypeHints`/`collectParamTypeHint` in `PhpTokenParser` walk the parameter
  list (including constructor-promoted properties), push every class-like hint into
  `classReferences`, and seed the new function scope's `$varTypesStack` for an unambiguous
  single type (`self`/`parent`/`static` resolved against the owner class). Union/intersection
  types (`A|B`, `A&B`) are still recorded as references but deliberately don't seed tracking —
  same "don't guess" stance as the rest of the parser's variable tracking.

- [ ] **Return-type-based inference isn't attempted.** `$x = SomeFactory::make();` where `make()`
  has a declared `: My_Class` return type — not tracked. Would require correlating a call's
  target function/method definition with its return type, which means either a second pass
  (defs must be fully collected first) or forward-declaration-order dependence. Bigger lift than
  anything above; not started.

- [ ] **Local variable tracking has no control-flow awareness** (documented in-code at the
  `$var = new ClassName()` assignment branch in `PhpTokenParser::parse`, not a bug — a
  deliberate trade-off). `if ($cond) { $x = new A(); } else { $x = new B(); } $x->method();`
  only tracks whichever assignment is last in source order, not "could be either." Fixing this
  properly means real branch-aware dataflow analysis, out of scope for a token-based parser.

- [ ] **Namespaced static calls aren't scoped.** `Some\Namespace\Foo::method()` — the receiver-token
  branches that resolve `self::`/`parent::`/`Foo::` (in the `T_STRING` branch of the main loop)
  only match `T_STRING`, never `T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED`. Falls back to the
  unscoped pool. Same limitation already existed for `classReferences` before any of the
  class-scoping work (namespaced `extends`/`implements`/`new` targets were never a problem there
  since `captureClassNameAfter`/`captureClassNameList` already use `CLASS_NAME_TOKENS`, which
  does include the qualified-name tokens — it's specifically the call-scoping lookahead
  (`findScopedCallTarget`'s receiver-side checks) that's T_STRING-only). Low priority: WP
  plugin/theme code is overwhelmingly written in the global namespace.

## Suggested priority if picked back up

Items 1-3 below are done (see checked items above). Remaining, in rough priority order:

1. Property types (`$this->service = new My_Service(); $this->service->render();`) — biggest
   remaining precision gap for typical WP OOP code (service/collaborator properties set in the
   constructor, used elsewhere in the class).
2. Class names passed as bare strings to WP APIs (`register_widget('My_Widget')`,
   `class_exists('My_Class')`) and dynamic instantiation (`new $class()`) — both false-negative
   sources on the class-unused check specifically.
3. Everything else — return-type inference, control-flow awareness, namespaced calls — is
   progressively more work for progressively rarer real-world patterns in typical WordPress code.

# File Detection — Open Issues

Known gaps in `FileAnalyzer` (the `files` check). Same spirit as above: documented scope limits,
not shipped bugs.

- [x] **Composer PSR-4/classmap-autoloaded files are never counted as referenced.** Fixed:
  `FileAnalyzer::loadComposerAutoloadPaths` reads the scanned target's own `composer.json`
  (`$rootDir/composer.json`) `autoload`/`autoload-dev` blocks — `psr-4`/`psr-0` dirs (including
  the multiple-dirs-per-prefix array form), `classmap` dirs/files, `files` — and exempts every
  file under those mappings from candidacy entirely (`isComposerAutoloaded()`, same shape as the
  existing `TEMPLATE_DIRS`/`index.php` exemptions in `isCandidate()`), rather than trying to
  prove real per-class usage. A missing or malformed `composer.json` just leaves the exemption
  list empty — most themes/plugins don't declare their own, and this analyzer already runs once
  per scan target, so it's a no-op for them, not an error.

- [x] **Dynamic bulk-include loops are a blind spot.** Fixed: `PhpTokenParser` now recognizes
  `glob(...)` calls (`parseGlobDirRef`, sharing a `findTrailingStringLiteral` helper refactored
  out of `parseIncludeRef` — the same "take the trailing literal segment of a possibly-dynamic
  expression" logic applies to both) and records which directory each one scans
  (`ParseResult::$globIncludeDirs`), plus whether the file contains an include/require keyword
  at all (`$hasIncludeStatement`). `FileAnalyzer::loadGlobExemptDirs` only trusts a glob'd
  directory as reachable when *both* signals are present in the same file — ruling out the
  cheap false-exemption case of a `glob()` used for something unrelated to code-loading (image
  galleries, asset lists) — and resolves the directory relative to the calling file's own
  location (`__DIR__`/`dirname(__FILE__)` is how this pattern is written in practice), correctly
  covering the "bootstrap file globs its own sibling directory" case too (empty/`.` directory
  component ⇒ the caller's own directory). Deliberately a *separate*, strict prefix-only match
  (`isUnderGlobExemptDir`) rather than folding into the existing loose `$referenced`/
  `isReferencedByPartialMatch` index — that index's dash-suffix heuristic (for
  `get_template_part('slug', $dynamic)`) would otherwise wrongly match an unrelated
  similarly-prefixed directory (`inc` exempting `inc-legacy/`). Still coarse in one way the TODO
  called out up front: it doesn't prove the `glob()` result is actually what gets `require`'d,
  just that both appear somewhere in the same file.

- [ ] **Legacy `spl_autoload_register()` class-map callbacks aren't recognized.** Pre-Composer (or
  hybrid) WP plugins sometimes register their own autoloader mapping class name → file path
  in code, rather than declaring `composer.json` autoload rules. Not currently detected at all.
  Lower priority than the Composer case — much rarer in current WP code, and the callback body can
  be arbitrary PHP (string manipulation, `str_replace` on the class name, etc.), so there's no
  single fixed shape to pattern-match against the way `composer.json`'s declarative `autoload` key
  offers.
