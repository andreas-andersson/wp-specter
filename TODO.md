# File Scanning — Open Issues

Known gaps in `FileScanner` (which files ever reach any analyzer, before any check-specific logic
runs at all).

- [x] **A literal `vendor` directory name isn't the only real shape third-party dependencies show
  up under — php-scoper/Mozart/Strauss-style dependency-prefixing relocates a package's code into
  the host project's own tree under an author-chosen directory name, always with a "vendor"
  segment somewhere in it by convention, but never excluded by the old fixed 3-item
  `DEFAULT_EXCLUDES` list.** Found by a fresh gap-hunting pass over the 7-plugin test corpus:
  confirmed **three different real spellings**, none excluded — `vendor_prefixed` (Elementor,
  wp-smushit), `vendor-prefixed` (WooCommerce), `jetpack_vendor` (Jetpack's own separately-
  published Automattic packages). Impact was severe for two of the seven: **100% of Elementor's
  95 `--type=functions` findings** and **100% of wp-smushit's 7** were bundled Twig/Symfony-
  polyfill/Guzzle code — neither plugin had a single genuine finding in that check, entirely
  masked by noise. This wasn't specific to the functions/hooks checks — it's upstream of every
  analyzer, since `FileScanner` decides what files exist at all before any check-specific logic
  runs.

  Fixed: `FileScanner::isVendorPrefixedDirName()` additionally excludes any directory whose
  basename contains "vendor" as a whole `_`/`-`-delimited segment (not a bare substring — a real
  project directory named "vendors" (plural) or "my-vendors-page" isn't swept up by accident, only
  an exact segment match). Applied unconditionally alongside the existing literal-name exclusion
  list, the same "always-on default" treatment `vendor`/`node_modules`/`.git` already get.
  Verified: Elementor and wp-smushit's `--type=functions` now both come back `✓ All clear` (their
  entire prior output was this noise), zero remaining `vendor_prefixed`/`vendor-prefixed`/
  `jetpack_vendor` hits anywhere in the corpus, and a full `--type=all` sanity pass across all 7
  plugins shows no crashes and no regressions.

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
  `$pendingClassName`/`$pendingClassParent` for interface/enum bodies — untouched, same as
  before. Also fixed the bundled gap: `use TraitName;` directly inside a class/trait/enum body
  (guarded by brace-depth so it's not confused with a top-level namespace `use` import or a
  closure's `function() use (...)`) is now captured as a `classReference`.

- [x] **A trait's own method looked unused whenever it was only ever called through the
  consuming class** (`$this->method()` inside the class that `use`s the trait, or a tracked
  variable of that class's type) — the trait method's `FunctionDef` had `ownerClass = null` (by
  the design above), but the call itself was correctly scoped to the *consuming* class
  (`scopedCalled['Person']['greet']`, not `scopedCalled[null]`), so the two never matched and a
  perfectly-used trait method reported `UnusedMethod`. Fixed in two parts: (1) `T_TRAIT` now DOES
  set `$pendingClassName` to the trait's own name, so a trait method's `FunctionDef::$ownerClass`
  is the trait, and an intra-trait `$this->otherMethod()` call resolves precisely instead of
  leaking into the unscoped fallback pool; (2) `PhpTokenParser` records every `use TraitName;`
  as a `TraitUsage` (consuming class/trait ⇒ trait name), and `ClassAnalyzer::analyze()`
  aggregates these into a trait ⇒ consumers map, walked (bounded, cycle-guarded, same shape as
  `isContractMethod`'s extends-chain walk — `isUsedByTraitConsumer`) so a trait method counts as
  used when *any* class that `use`s the trait, directly or transitively (trait-uses-trait), calls
  it through a scoped receiver. A trait method that's never called by any consumer, anywhere, is
  still correctly flagged.

- [x] **A bare `'Class::method'` callback string (no array, no receiver) resolved its trailing
  method segment but dropped the leading class segment entirely.** Real-world finding (Astra
  theme): `'render_callback' => 'Astra_Customizer_Partials::render_partial_site_title'` (the WP
  customizer/REST-controller dynamic-partial shape) — the method segment was already extracted
  via the existing `\`/`::` trailing-literal stripping (added for `__CLASS__ . '::method'`
  concatenation), but nothing fed the leading class segment into `classReferences`, so the class
  itself looked permanently unused despite being reached exactly this way. Fixed: when the
  string's last separator is `::`, the segment before it is also resolved (`shortClassName`) and
  recorded as both a `classReference` and the scoped call's receiver — skipped for
  `self`/`parent`/`static`, which aren't resolvable from a bare string literal. Narrower than the
  open item below: still only covers a literal `'Class::method'` string, not a bare class name on
  its own.

- [x] **Class names passed as bare strings to WP APIs, general case — turned out to already be
  covered, not actually open.** Re-investigated to implement a fix and found the existing
  `findUnusedClasses` fallback (trusting any string literal already in the generic
  `$functionCalls` pool as a possible class reference — originally added for the
  `register_panel_type()`/filter-return shapes above) is function-name-*agnostic*: it doesn't
  care which call a string was an argument to, so `register_widget('My_Widget')`,
  `is_a($x, 'My_Class')`, `is_subclass_of($x, 'My_Base')`, and a class name used as a plain
  associative-array *value* (`['class' => 'My_Custom_Control']`, not just the special
  `[Foo::class, 'method']` callback shape) were all already rescued, with zero code changes
  needed — confirmed with a small test battery, then locked in with dedicated regression tests
  (`testDoesNotReportClassPassedAsBareStringToRegisterWidget`,
  `testDoesNotReportClassPassedAsBareStringToIsAOrIsSubclassOf`,
  `testDoesNotReportClassNamePassedAsAPlainConfigArrayValue`) since previously this coverage was
  only an accidental side effect of a fix motivated by different, narrower examples, not
  something itself under test. Bonus found the same way: the common `$class = 'My_Class'; new
  $class();` shape (a literal string assigned to a variable, then instantiated dynamically) is
  *also* already rescued — the literal `'My_Class'` on its own right-hand side flows into the
  same pool regardless of what later happens to the variable it's assigned to. `class_exists('My_Class')` remains
  the one deliberate exclusion (see `testReportsClassWrappedInOwnClassExistsGuard` above) — its
  dominant real-world shape is a redeclaration guard around the class's own definition, not a
  genuine usage signal. What's still genuinely unresolvable is a class name that's never a clean
  string literal anywhere at all (built via concatenation, `sprintf`, or sourced from external
  data) — that's the actual remaining scope of the "Dynamic instantiation" item below, which is
  narrower than its own text currently suggests.

- [x] **A class name passed as WP-CLI's `add_command()` second argument dispatches by
  reflection across every public method of that class — no fixed method name exists to check.**
  Real-world finding (Astra theme): `WP_CLI::add_command('astra abilities',
  'Astra_Abilities_CLI')`; `enable()` (a WP-CLI subcommand method) looked unused since nothing
  calls it by name anywhere in project code — WP-CLI matches whichever public method name
  matches the subcommand typed at the CLI. The class itself wasn't a problem (already rescued by
  the existing string-literal-in-the-generic-`$functionCalls`-pool fallback in
  `findUnusedClasses`) — only its *methods* had no equivalent whole-class exemption. Fixed:
  `PhpTokenParser` detects `WP_CLI::add_command($hook, 'ClassName')` (new
  `secondArgStringLiteral` helper, same "only trust a plain literal" stance as
  `firstStringArgIndex`) and records the class name on
  `ParseResult::$reflectionDispatchedClassNames`; `ClassAnalyzer::findUnusedMethods` exempts
  every method whose owner class is in that set — the same whole-class effect as
  `FULLY_EXEMPT_BASE_CLASSES`, just triggered by a call site instead of an extends/implements
  clause, since there's no fixed base class here at all (`Astra_Abilities_CLI` extends nothing).

- [ ] **Dynamic instantiation via `new $var()` is unresolvable at the `new` expression itself.**
  `captureClassNameAfter` requires a literal identifier token right after `new`; a variable never
  resolves there. Narrower in practice than it sounds, though: `$class = 'My_Class'; new
  $class();` is *already* covered end-to-end — not by resolving `new $class()`, but because the
  literal `'My_Class'` on the assignment's own right-hand side independently flows into the
  generic string-literal pool (see the bare-string-class-reference item above), which is enough
  to keep `My_Class` off the unused-class list regardless of what happens to `$class` afterward.
  What's genuinely still unresolvable is a class name that's never a clean string literal
  *anywhere* in the file — built via concatenation/`sprintf` (`'My_' . $suffix`), read from a
  property (`new $this->widgetClass()`), or sourced from external data (DB option, JSON config).
  Inherent to a token-based parser without type inference or dataflow analysis — not realistically
  fixable without a much bigger rewrite. Documented here so it's not re-investigated as a "bug."

- [x] **Every class-keyed structure was flat and keyed only by short class name, project-wide —
  two unrelated classes sharing a short name across different namespaces collided.** `PhpTokenParser`
  had zero `T_NAMESPACE` handling at all, and `ClassDef`/every scoped-call/property-type map in
  `ClassAnalyzer` (`$classDefsByName`, `$scopedCalled`, `$propertyAssignedClasses`,
  `$descendantsOf`, ~15 structures total) discarded namespace entirely. `$classDefsByName[$def->name]
  = $def` silently **overwrote** one class's `ClassDef` with another's the instant two classes
  anywhere in the project shared a short name — not just a collision risk for the colliding pair,
  but corruption of the extends/implements chain-walk (`isContractMethod`,
  `isUsedByPolymorphicCall`, `isFullyExemptClass`) for every other class that happened to also
  need that (now wrong) `ClassDef`. Real-world case (Elementor): two unrelated classes both named
  `Base_Route`, in different namespaces (`Elementor\App\Modules\ImportExportCustomization\Data\
  Routes` vs `Elementor\Data\V2\Base`), each declaring their own `register_route()` method (a
  third unrelated class, `Elementor\Data\Base\Endpoint`, declares one too) — `$scopedCalled
  ['Base_Route']['register_route']` was one shared bucket regardless of which one a real call
  site resolved to.

  Fixed with a genuinely namespace-aware resolution pass, not a workaround:
  1. **`PhpTokenParser`** now tracks `namespace X;` (the bare, non-braced form only — see scope
     limitation below) and resolves every extends/implements/trait-use/`new`/`instanceof`/static-
     call-receiver/type-hint/return-type class-name reference to its real FQCN, per PHP's own
     resolution rules (leading `\` ⇒ already fully qualified; first segment matches a `use` import
     ⇒ substitute that import's FQCN; otherwise ⇒ prefixed with the current namespace, reducing to
     exactly the bare name for the ~38% of real-world files with no `namespace` at all — zero
     behavior change there). New `src/Parser/ClassRef.php` value object carries *both* the short
     name (for matching WP-core/vendor curated tables — `BASE_CLASS_CONTRACT_METHODS` and
     friends, always keyed by bare short name since WP core is always global-namespace — and
     `VendorClassReflector`, unaffected) and the resolved FQCN (for matching another project
     `ClassDef` precisely) side by side, so nothing downstream had to choose one over the other.
     `ClassDef` gained an `$fqcn` field; `extends`/`implements` changed from `list<string>` to
     `list<ClassRef>`.
  2. **`ClassAnalyzer`** re-keyed `$classDefsByName` (and everything downstream of it —
     `$scopedCalled`, `$descendantsOf`, the `isContractMethod`/`isUsedByPolymorphicCall`/
     `isFullyExemptClass` chain-walks) by FQCN instead of short name. A useful side effect:
     `ClassAnalyzer`'s own *global* merge of every file's `use` imports into one flat map (a
     second, independent source of the same collision risk — two files importing different FQCNs
     under the same alias would silently pick whichever was merged last) became unnecessary and
     was deleted outright, since each `ClassRef` already carries its own correctly-resolved,
     per-file FQCN. `isFullyExemptClass`'s "no signal either way ⇒ trust the bare short-name
     match" leniency (for `FULLY_EXEMPT_BASE_CLASSES`, e.g. Acorn's `Composer`) is preserved
     exactly, just re-expressed as `$ref->fqcn === $ref->short` — and this closes a related,
     previously-undetectable gap as a side effect: a namespaced file's own unrelated `Composer`
     class extended with no `use` import at all previously had *no way* to be told apart from a
     real `Roots\Acorn\View\Composer` subclass (both were an identical bare short-name match);
     merely being namespaced now makes `fqcn !== short`, correctly ruling it out.
  3. **Bundled, separate fix** (needed for the Elementor example to resolve end-to-end — the
     namespace work alone doesn't touch it): the real call site is
     `( new Export() )->register_route(...)`, an inline `new` immediately chained with `->method()`,
     which `PhpTokenParser` didn't scope to any receiver at all before (only `$this->`,
     `self::`/`parent::`/`static::`, a literal `Class::method()`, and the two-statement
     `$var = new X(); $var->method();` were recognized). Extended the *existing*
     `assignedNewClassName()`/`findScopedCallTarget()` machinery to also recognize this shape
     (gated on `new` being immediately preceded by `(`, the wrapping parens PHP requires for it)
     rather than inventing a separate mechanism.

  `classReferences` (the flat pool feeding `findUnusedClasses`' own "is this class referenced at
  all" check, and `FileAnalyzer`'s unrelated PSR-4-mapped-file usage-proof) was deliberately left
  untouched, still short-name-only — the actual reported bug was a contract-method/polymorphic-
  dispatch failure, not an unused-class-detection one, and touching it would have pulled
  `FileAnalyzer.php` into a change that didn't need it.

  Verified against the full real-plugin corpus (before/after `git stash` comparison, `--type=classes`):
  akismet, contact-form-7, and wordfence unchanged (no namespace short-name collisions present).
  wp-smushit and jetpack showed small method-count increases (7 and 46 respectively) — real,
  previously-hidden dead code now correctly surfaced, no longer masked by an unrelated same-named
  method elsewhere. Elementor and WooCommerce showed large *net decreases* (729→636 and
  1360→1246 methods respectively) — the dominant effect in both: the silent-`ClassDef`-overwrite
  bug had been corrupting the extends/implements chain-walk for a much wider set of classes than
  just the colliding pair (very common in both codebases, which reuse generic short names like
  `Module`/`Controller`/`Base` across dozens of unrelated namespaced files), so fixing it let
  `isContractMethod`/`isUsedByPolymorphicCall` correctly credit far more genuinely-used methods
  that were previously walking the *wrong* (overwritten) class's inheritance chain. Elementor's
  own `Base_Route`/`register_route` collision confirmed resolved directly: the genuinely-called
  one (`ImportExportCustomization\...\Export`, via the newly-recognized inline-chain call) is no
  longer flagged; the unrelated `Data\V2\Base\Base_Route` one is (correctly) still evaluated on
  its own merits. No crashes on any of the 7 plugins.

  **Explicit scope limitations, not silent gaps:**
  - **Braced `namespace X { ... }` / bare `namespace { ... }` forms are unsupported.** Zero
    occurrences across the entire 7-plugin, 8,651-file real-world fixture corpus justified not
    tracking brace-scoped namespace resets — a file using this form has subsequent code resolve
    as if still in the previous/global namespace.
  - **`FunctionAnalyzer`'s identical-looking flat short-name collision for namespaced top-level
    functions was deliberately NOT touched here** — see the "Function detection" section below
    for the follow-up that fixed it properly, modeling PHP's real fallback-to-global-namespace
    call-resolution rule instead of just reapplying the class-name scheme.

## Method detection

- [x] **Contract-method exemption (`ClassAnalyzer::isContractMethod`) only checks the declaring
  class's own `extends`/`implements`, not the full inheritance chain.** Fixed: `isContractMethod`
  now walks `$classDefsByName` via `$def->extends[0]` (bounded by `MAX_INHERITANCE_DEPTH = 50` to
  survive a cycle/bad input), checking `implements` at every level visited too — so a class that
  extends `My_Base_Widget`, which itself extends `WP_Widget`, still gets the
  `widget()`/`form()`/`update()` exemption, and an interface attached higher up the chain rather
  than redeclared on every subclass is still honored.

- [x] **A shared, concrete (non-abstract) method declared once on a base class looked unused
  whenever it was only ever called through a concrete descendant's own receiver.** Real-world
  finding (Astra theme): `Astra_Abstract_Ability::register()`/`build_output_schema()`/
  `get_description()`/`get_category()` are declared on the abstract base, then called from ~70
  concrete subclasses as `Subclass::register()`, `$this->build_output_schema()` (from inside the
  subclass), `$instance->get_description()` — every one of those resolves the scoped call's
  receiver to the *subclass*, never the base class the method is actually declared on, so
  `scopedCalled[ownerClass]`'s exact-match check never fired. Fixed: `ClassAnalyzer` now builds
  `$descendantsOf` (base class name => every class whose own extends chain passes through it, any
  depth), and `isUsedByDescendantReceiver()` credits the method when *any* known descendant has a
  scoped call recorded against it — the mirror direction of the existing
  `isUsedByPolymorphicCall()` (which walks concrete class → the interface/ancestor a call was
  resolved to; this walks base class → any concrete descendant a call was actually resolved to).

- [x] **A callback built via string concatenation inside an array-callback with a resolvable
  receiver was recorded as an exact (and wrong) truncated name instead of the real method.**
  Real-world finding (Astra theme): `add_action('astra_footer_html_' . $index, array($this,
  'footer_html_' . $index))` inside a `for` loop wiring N numbered component slots
  (footer/header builder) — `footer_html_1`..`footer_html_4` are only ever reached through a
  runtime loop counter, never a literal exact name, so they looked unused; the literal
  `'footer_html_'` alone was being recorded as if it *were* the whole method name, which matched
  nothing real either. Fixed: `ScopedMethodCallPrefix` (`ParseResult::$scopedMethodCallPrefixes`)
  records the literal prefix against the array-callback's resolved receiver when the
  method-name string is followed by concatenation; `ClassAnalyzer` checks it with
  `str_starts_with()` instead of an exact key lookup. Deliberately requires a *resolved
  array-callback receiver* — an earlier version also tried an unscoped fallback pool for the
  no-receiver case, but auditing it against the real Astra codebase turned up dangerous
  short/generic prefixes (`'_'`, `'h'`, `'menu'`, `'astra_'`) coming from ordinary string-building
  entirely unrelated to any callback (option keys, CSS classes, hook *tag* names), which silently
  dropped 11 genuine unused-function findings project-wide. Reverted that half — an unscoped
  prefix match is categorically riskier than an unscoped *exact* match, since a short incidental
  prefix collides via `str_starts_with()` with huge swaths of unrelated real names, where an
  exact full-identifier collision is rare by comparison.

- [x] **Property types weren't tracked**, unlike local variables. `$this->service = new
  My_Service(); ... $this->service->render();` (service/collaborator set in the constructor,
  used elsewhere in the class) fell all the way back to the unscoped/name-only pool — the single
  most common real-world WP OOP shape this parser was still missing. Fixed:
  - `PhpTokenParser`'s `$this->` handling (T_VARIABLE branch) gained a new `propertyAccessTarget()`
    check (same shape as `findScopedCallTarget` but without requiring `(` right after the name,
    since the caller needs to branch on what comes next) — `$this->prop = new ClassName()`
    records the assignment on `ParseResult::$propertyAssignedClasses` (class => prop => class,
    flat/file-wide, last-write-wins, deliberately *not* scoped to function-body depth the way
    `$varTypesStack` is, since surviving across methods is the whole point); `$this->prop-
    >method()` records an unresolved `PropertyMethodCall` instead of trying to resolve it inline.
  - Resolution is deferred to `ClassAnalyzer`, which merges every file's
    `$propertyAssignedClasses` first, then resolves every `PropertyMethodCall` against the
    complete map, feeding a match straight into the existing `$scopedCalled` pool — a resolved
    property-typed call needed no new matching logic of its own; every existing exemption
    mechanism (contract methods, `isUsedByDescendantReceiver`, trait consumers, ...) already
    applies to it for free. This also sidesteps the token-based parser's usual single-pass
    ordering limitation: whether the property is *set* in a method declared before or after the
    method that *reads* it no longer matters, since resolution only happens once the whole scan
    is merged.
  - Constructor-promoted properties (`public function __construct(private My_Service $svc) {}`)
    auto-assign `$this->svc` — `collectParamTypeHint` now also checks for a
    `T_PUBLIC`/`T_PROTECTED`/`T_PRIVATE` modifier on the parameter (the promotion marker;
    `readonly` alone doesn't promote) and records the same implicit assignment.
  - Bug caught while writing this: `propertyAccessTarget()`'s first draft compared the token
    after `$this` directly against the bare string `'->'` — but `->` tokenizes as
    `T_OBJECT_OPERATOR` (an array token), not a plain string, the same way `::`/`->` are already
    unwrapped via `is_string($sepToken) ? $sepToken : $sepToken[1]` in `findScopedCallTarget`.
    The bare-string comparison silently never matched anything (return null every time), caught
    by a manual debug trace after property tracking produced zero results end-to-end on an
    otherwise-correct test fixture.

- [x] **Property-type tracking above only recognized `$this->prop = new ClassName()` — a
  property assigned from a type-hinted parameter that isn't `new`'d directly or
  constructor-promoted was still invisible.** Found by a fresh gap-hunting pass while chasing an
  unexplained finding: real-world case (Elementor's `Data\V2\Base\Base_Route`/`Controller`) —
  `protected function __construct( Controller $controller, $route ) { $this->controller =
  $controller; }`, a manual constructor-injection assignment (not `new Controller()`, no
  visibility modifier on the parameter so not promotion either). Every override of
  `get_permission_callback()` anywhere in the `Controller` hierarchy looked unused, since
  `$this->controller->get_permission_callback()` (called from a *different* method, `Base_Route`'s
  own `register_route()`/`register_item_route()`) could never resolve — `assignedNewClassName()`
  only recognizes a `new ClassName(...)` right-hand side, so the assignment was silently dropped
  from `$propertyAssignedClasses` entirely. Not a regression from the namespace-aware
  rearchitecture above (confirmed it would have produced the same false positive before that work
  too) — just newly surfaced while verifying that refactor's real-corpus impact.

  Fixed: new `PhpTokenParser::assignedVariableClassName()` — given a `$this->prop = <RHS>;`
  assignment, if the RHS isn't a `new` expression, checks whether it's *exactly* a bare variable
  (`$var;`, nothing more — a method call, chained access, or any other expression bails rather
  than guessing) whose class is already known via the current scope's `$varTypesStack` (already
  seeded for a type-hinted parameter by `collectParamTypeHint`, see the entry below). Falls back
  to this only when `assignedNewClassName()` itself returns null, so the existing `new
  ClassName()`/constructor-promotion paths are unaffected. Verified against the real corpus:
  `get_permission_callback` findings gone entirely from Elementor's `--type=classes` output, and
  the same pattern being common in Elementor's own dependency-injection-style architecture
  dropped the method-finding count further (526, down from 636 after the namespace-aware fix
  alone). Full 7-plugin sanity pass shows no crashes.

- [x] **Type-hinted parameters don't seed variable tracking or count as class references.**
  Fixed: `parseParamTypeHints`/`collectParamTypeHint` in `PhpTokenParser` walk the parameter
  list (including constructor-promoted properties), push every class-like hint into
  `classReferences`, and seed the new function scope's `$varTypesStack` for an unambiguous
  single type (`self`/`parent`/`static` resolved against the owner class). Union/intersection
  types (`A|B`, `A&B`) are still recorded as references but deliberately don't seed tracking —
  same "don't guess" stance as the rest of the parser's variable tracking.

- [x] **Return-type-based inference wasn't attempted.** `$x = SomeFactory::make();` where `make()`
  has a declared `: My_Class` return type fell back to the unscoped pool the same way an
  unresolvable assignment always does. Fixed, following the same deferred-resolution shape as the
  property-type fix (the "second pass" this item called for, rather than forward-declaration-
  order dependence):
  - **`FunctionDef::$returnType`**: new `PhpTokenParser::parseReturnTypeHint()` resolves a
    declared `: ReturnType` the same "only trust a single unambiguous type" way `collectParamTypeHint`
    already does for parameters (`self`/`static` resolved against the owner class, nullable's `?`
    not counted as a second segment, any union/intersection — counting `array`/`callable` toward
    ambiguity too, even though they can never resolve to a class themselves — left unresolved
    rather than guessed at).
  - **`PendingReturnTypedCall`**: `$x = <call>;` recognized as the *entire* RHS — `Foo::method()`,
    `self::`/`parent::`/`static::method()`, `$this->method()`, or a bare `helper_fn()` (new
    `scopedOrBareCallRhs`, mutually exclusive with the existing `new ClassName()` tracking on the
    same variable) — records the call's own target instead of resolving inline, since `make()`'s
    declaration is routinely in a different file's parse than this call site.
  - `ClassAnalyzer::findUnusedMethods` merges every file's method/function return types first,
    then resolves each pending call against it, feeding a match straight into `$scopedCalled` —
    every existing exemption mechanism applies to it for free, same as the property-type fix.
  - **Precision-preserving fallback, caught by running the existing test suite, not by guessing
    up front**: when a pending call's source doesn't resolve (unknown function, no declared
    return type, a union type, ...), it now degrades to the same unscoped `$called` pool the call
    would have landed in before this feature existed — this had to be added explicitly in both
    `ClassAnalyzer` *and* `FunctionAnalyzer` (which builds its own, separate `$called` map and
    doesn't care about classes at all), since without it, moving this shape out of the parser's
    generic `$functionCalls` output entirely would have silently made a same-named real function
    or method newly (and wrongly) look unused — a real regression the existing
    `testReassignedLocalVariableInvalidatesTrackedType` test caught immediately.
  - Ran against the full theme test corpus: zero finding-count changes (declared return types do
    exist there — e.g. Hello Elementor's `includes/module-base.php` — just not combined with the
    exact `$x = Class::method(); $x->method();` chain in this particular sample); same
    prospective-value situation as the generated contract-methods stub.

- [ ] **Local variable tracking has no control-flow awareness** (documented in-code at the
  `$var = new ClassName()` assignment branch in `PhpTokenParser::parse`, not a bug — a
  deliberate trade-off). `if ($cond) { $x = new A(); } else { $x = new B(); } $x->method();`
  only tracks whichever assignment is last in source order, not "could be either." Fixing this
  properly means real branch-aware dataflow analysis, out of scope for a token-based parser.

- [x] **`BASE_CLASS_CONTRACT_METHODS` (`ClassAnalyzer`) was hand-curated only, not generated from
  WP core** the way `WpCoreHooks` is from `tools/generate-wp-hooks-stub.php` — every WP core base
  class designed for subclassing had to be discovered and added by hand as a real-world false
  positive turned it up (found while adding `Walker_Nav_Menu` to the list). Fixed additively,
  never replacing the hand list:
  - New **`tools/generate-wp-contract-methods-stub.php`**, using exactly the fix shape this item
    originally sketched: scans real WP core (`wp-admin`/`wp-includes`, minus bundled third-party
    libraries — Requests, PHPMailer, SimplePie, ID3, IXR, a Text_Diff renderer, sodium_compat,
    the vendored AI-client SDK, and `class-avif-info.php`'s own generically-named internal `Box`/
    `Parser` classes specifically, since nothing else got caught by the directory-level
    exclusions) with the project's own `PhpTokenParser`/`ScopedMethodCall` machinery, for exactly
    the signature described: a `public` method WP core declares on a class *and* calls via
    `$this->method()` from elsewhere in that same class's own body — the actual "dispatches on
    self, possibly a subclass" mechanism `WP_Widget`/`Walker`/`WP_Customize_Control` (and ~200
    others) all share.
  - Also filters out a WP-core naming *convention* this only surfaced once real output was
    inspected: a single leading underscore (`_get_display_callback()`, `_register_one()`, ...) is
    WP core's own long-standing "internal, don't touch, even though PHP visibility says public"
    signal — real methods, self-dispatched exactly the way this tool looks for, but never a real
    override point. Caught by spot-checking `WP_Widget`'s own generated entry against the
    already-known-correct hand list, exactly the "would need spot-checking before trusting it
    unattended" step this item called for up front.
  - New **`ContractMethodStub`/`WpCoreContractMethods`** (mirrors `HookStub`/`WpCoreHooks`'
    shape) — `ClassAnalyzer::isContractMethod()` now checks both
    `BASE_CLASS_CONTRACT_METHODS` (still the fast, 100%-vetted path — unchanged, untouched) *and*
    the generated stub at every level of the extends-chain walk, taking either match. Deliberately
    additive rather than a replacement: the over-broad risk this item flagged up front (an
    internal helper that merely happens to be self-dispatched, not really meant for override)
    only ever costs a missed "unused method" warning on a class the hand list doesn't already
    cover — it can never suppress a finding the hand-curated list would otherwise have caught,
    since a match on *either* list already short-circuits to "used."
  - Confirmed complementary value, not just duplication: `Walker`'s generated entry only finds 6
    of the hand list's 9 known contract methods (missing `walk`/`paged_walk`/
    `get_number_of_root_elements` — those are called by WP core from *outside* the class, e.g.
    `wp_nav_menu()` calling `$walker->walk(...)`, never self-dispatched from within `Walker`'s own
    body, so this specific heuristic can't see them — an inherent limit of "self-dispatch," not a
    bug), while `WP_Customize_Control`'s generated entry adds four real override points
    (`get_link`, `input_attrs`, `link`, a second `json`) the hand list didn't have at all. Ran
    against this project's whole 8-theme test corpus (astra, blocksy, generatepress, hello-biz,
    hello-elementor, kadence, oceanwp, swiftqueue) — zero finding-count changes, since none of
    them happen to extend the ~200 newly-covered classes the hand list didn't already name;
    the value is prospective (the next theme that does), not something this pass could confirm
    fixed a currently-known false positive the way most other items in this file were.
  - `INTERFACE_CONTRACT_METHODS` (`ArrayAccess`, `Iterator`, `Countable`, ...) deliberately left
    untouched — those are built-in *PHP language* interfaces, not WP core ones, so there's no WP
    core source to scan for them in the first place; this tool only ever targeted the base-class
    half of the original complaint.

- [x] **Namespaced static calls aren't scoped — stale entry, this was already fixed (commit
  `37b63d4`) and the checkbox just never got updated.** `Some\Namespace\Foo::method()` is handled
  by its own dedicated `T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED` branch in the main loop (see
  the "`\SwiftQueue\License_Bridge::initialize()`" doc comment in `PhpTokenParser::parse`),
  calling the same `findScopedCallTarget`/`resolveClassNameToken` machinery the `T_STRING` branch
  uses — confirmed still covered by `PhpTokenParserTest::
  testFullyQualifiedAndNamespaceQualifiedStaticCallsResolveAsClassReferences`. The namespace-aware
  FQCN resolution work above additionally improved what that branch resolves *to* — the scoped
  call's receiver is now the real FQCN (`SwiftQueue\License_Bridge`) instead of just the bare
  short name — but the scoping itself predates that work and was never actually missing.

- [x] **Contract-method exemption (and the class-unused check) only ever knew about base
  classes/interfaces the scan itself parsed — a class extending a real Composer dependency
  (`vendor/`, never part of `$files`) always dead-ended and got flagged.** Found by running
  wp-specter against a real Roots Sage (Acorn) theme: `ThemeServiceProvider extends
  SageServiceProvider` (vendor) overriding `register()`/`boot()`, and `App`/`Post`/`Comments`
  extending `Roots\Acorn\View\Composer` (vendor) with zero syntactic reference anywhere in
  project code (Acorn auto-discovers both by PSR-4 directory convention, not a literal call) —
  10 false-positive unused methods and 3 false-positive unused classes, all real-world, all
  idiomatic code. Fixed in three parts:
  1. **`VendorClassReflector`** (`src/Analyzer/VendorClassReflector.php`): given a list of
     `vendor/autoload.php` paths, answers "does class/interface X declare method Y" via PHP's
     own autoloader + `ReflectionClass` — sees the *real* inheritance chain, including further
     vendor ancestors, with no per-framework list to maintain. `isContractMethod`'s
     extends/implements walk falls back to it the moment it steps off the edge of
     `$classDefsByName` (a vendor target), generalizing the existing curated
     `BASE_CLASS_CONTRACT_METHODS`/`INTERFACE_CONTRACT_METHODS` lists rather than replacing them
     (those stay as the fast path, and remain the only path for classes that aren't
     Composer-autoloadable at all, e.g. WP core's `WP_Widget`/`Walker`). Deliberately opt-in and
     best-effort: every entry point is wrapped in `try/catch \Throwable` per path, so a
     missing/broken/side-effecting vendor file degrades to "no answer," never a fatal scan.
  2. **`FULLY_EXEMPT_BASE_CLASSES`** in `ClassAnalyzer` (currently just `'Composer'` — Acorn's
     `Roots\Acorn\View\Composer`): reflection can't help here since there's no fixed method name
     to check against — a Composer subclass's methods are Blade-view data providers, discovered
     by matching an author-chosen method name against the view's requested variable at render
     time. `isFullyExemptClass()` walks the extends chain the same bounded way
     `isContractMethod()` does, and is checked by *both* `findUnusedClasses` and
     `findUnusedMethods` — the first curated list in this file that suppresses a class-level
     finding, not just a method-level one.
  3. **`PhpTokenParser::parseUseImports`**: file-level `use Some\Namespace\Name [as Alias];`
     imports were never tracked at all before this (only the in-class-body trait-`use` case was)
     — without it, `VendorClassReflector` had no way to turn `extends SageServiceProvider` (the
     short name `parseClassDef` always stores) back into the real, autoloadable
     `Roots\Acorn\Sage\SageServiceProvider`. Recorded on `ParseResult::$useImports` (short
     name/alias => FQCN), merged globally across files the same way `$classDefsByName` already
     is. Deliberately does NOT support group-use (`use App\{Foo, Bar as B};`) — bails out
     without recording anything the moment it sees `{`, rather than guessing; verified this
     doesn't desync the main loop's brace-depth tracking, since those are still real, balanced
     braces the generic `{`/`}` handling counts correctly either way.

  Also fixed along the way: `Application::resolveVendorAutoloadPaths` collects vendor
  autoloaders from *both* the detected composer project root AND every scan target's own
  directory (not just one or the other) — a Bedrock-style layout has its own root `vendor/`,
  but a theme like Sage that requires Acorn directly has a second, separate `vendor/` right
  under the theme, and the classes a scan needs to reflect on can live in either.

  Considered and rejected: shelling out to PHPStan for this. Its open-source engine has no
  built-in unused-code rule at all (that's a Pro-only paid feature) — pulling it in wouldn't
  have solved the actual problem, just added a heavy, BC-unstable-internals dependency to a
  currently zero-runtime-dependency tool for a job plain Reflection already does.

- [x] **A real plugin's own `ABSPATH` "no direct access" guard could silently kill the entire
  scan process, with no error at all — not a detection gap, a scan-reliability bug.** Found by
  switching the test corpus from themes to real plugins and running wp-specter against a real
  WooCommerce checkout: `wp-specter scan woocommerce` printed only the header (path/mode/file
  count) and then nothing — no findings, no summary, no error, exit code 0, looking exactly like
  a clean "all clear" run despite `--type=classes` alone reproducing it in under 2 seconds.
  Root cause: `VendorClassReflector::classHasMethod()`'s `class_exists($className)` triggers
  Composer's autoloader on whatever PSR-4 file declares that class — and `defined('ABSPATH') ||
  exit;`, WordPress's ubiquitous "no direct access" convention, sits at the top of 927 files in
  that one WooCommerce checkout alone, including
  `Automattic\WooCommerce\Admin\API\Reports\Query`, which is *also* part of WooCommerce's own
  Composer PSR-4 map — reached while `isContractMethod()` was resolving a completely unrelated
  extends chain, having nothing to do with that class itself. `exit()` isn't a catchable
  `\Throwable`, so the existing per-path `try/catch \Throwable` wrapping (added for exactly this
  class of vendor-code risk) couldn't help — the bare `exit;` terminates the whole PHP process
  immediately, mid-scan, with exit code 0 and zero output, which is what made this so easy to
  mistake for "the tool succeeded and found nothing" rather than "the tool silently died."
  Reproduced in complete isolation down to two lines (`require 'vendor/autoload.php';
  class_exists('Automattic\WooCommerce\Admin\API\Reports\Query');` — the second line's `echo`
  right after it never runs) before touching any wp-specter code, to be certain of the exact
  mechanism rather than guessing from the symptom alone.
  - Fixed: `VendorClassReflector::isAvailable()` now defines `ABSPATH` (to an arbitrary
    non-empty placeholder, `'/'` — nothing reachable here is expected to call a real WordPress
    function that would depend on its actual value) before the very first `require_once`, so
    every subsequent autoload this reflector triggers — the initial "files"-autoload entries
    *and* any class lazily autoloaded later via `class_exists()` in `classHasMethod()` — sees it
    already defined and never takes the `|| exit` branch.
  - Deliberately narrow: this neutralizes the single most common instance of this class of guard
    (confirmed as the *only* guard pattern found across all 7 real plugins in the new test
    corpus, and by far the most common — 927 files in WooCommerce alone, vs. a handful in
    Elementor/Jetpack/Smush and zero in Akismet/CF7/Wordfence), not every conceivable
    direct-access check a plugin's vendor code might make. A fully robust fix would isolate
    vendor-autoload loading and reflection in a separate process so *any* hard exit there
    couldn't take the main scan down with it — not attempted here, since the `ABSPATH` guard
    alone already accounts for every real failure found across the whole new corpus.
  - Verified against all 7 plugins in the new corpus post-fix: every one now completes with a
    real, non-empty findings summary (previously silent/incomplete only for WooCommerce, since
    it's the only one of the seven whose vendor tree happens to be reachable from an
    extends/implements chain wp-specter's own scan actually walks).

## Reporting

- [x] **A method belonging to a class already reported `UnusedClass` was reported again,
  individually, as `UnusedMethod`** — redundant once the class-level finding already says
  nothing in it is reachable. Fixed: `ClassAnalyzer::analyze()` takes
  `$suppressUnusedClassMethods` (default `true`; CLI: `--no-suppress-unused-class-methods` to
  see both) and drops a method finding whenever its owner class is already in the current scan's
  unused-class set. Off by default risk: a false-positive `UnusedClass` (e.g. the
  WP-CLI-registered or `Walker`/`WP_Widget`-subclass shapes documented above, before their
  dedicated fixes) would have compounded — hiding N real `UnusedMethod` findings underneath one
  wrong class-level one. Both of the confirmed false-positive shapes found in the Astra theme
  during this pass are now fixed at the source instead, so the compounding risk is lower than
  when the flag was designed, but the flag (default-on, escape hatch available) was kept as the
  agreed design rather than re-litigated after the fact.

## Suggested priority if picked back up

Items checked above are done. Remaining, in rough priority order:

All of the class/method-detection items in this file are now fixed except the deliberately-
accepted scope limits (bare-string class references' general case is covered, but truly dynamic/
concatenated class names remain unresolvable by design; local variable tracking's lack of
control-flow awareness; namespaced calls). Every item in the Function detection and Hook &
template tag detection sections below is fixed too, except the ones explicitly marked as
deliberately out of scope (a suffix-only template-part slug — no real WP convention motivates it).

What's left is only the items already documented throughout this file as deliberate, accepted
scope limits — the Blade/Acorn convention-based template gaps (Template detection, above),
dynamic instantiation via a concatenated/computed class name, control-flow-aware variable
tracking, namespaced static calls, `spl_autoload_register()` class maps, and the rest. None of
these are "next to fix" so much as "not worth the precision/complexity trade-off for how rarely
they occur in typical WordPress code" — picking any back up should start by re-reading its own
entry above rather than this summary, since each one's trade-off reasoning is spelled out there.

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

- [x] **A plugin that ships `vendor/` but no `composer.json` of its own has none of the above
  to read, yet every dependency class is still genuinely autoloaded — false positives.**
  Real-world case (WooCommerce): `composer.json` is dev-only tooling stripped from the shipped
  plugin zip, but `vendor/` ships anyway; `FileAnalyzer::isCandidate` had nothing to exempt
  `src/*.php` with, so every PSR-4-autoloaded class under `src/` (e.g.
  `Automattic\WooCommerce\Internal\Admin\Marketing\MarketingCampaign`) was flagged
  `UnusedFile`. Fixed: `FileAnalyzer::loadGeneratedComposerAutoload` additionally reads
  Composer's own *generated* `vendor/composer/autoload_psr4.php`,
  `autoload_namespaces.php` (legacy PSR-0), `autoload_classmap.php`, and `autoload_files.php` —
  each a plain `return array(...)` file Composer itself keeps fully resolved and merged across
  the *whole* dependency tree (unlike `composer.json`, which only ever has the top-level
  package's own declared rules) — and folds their entries into the same `$autoloadDirs`/
  `$autoloadFiles` lists the `composer.json` path already populates. `include`d directly rather
  than parsed: each file only computes two local path variables (`$vendorDir`/`$baseDir`) from
  its own location and has no other side effects, so there's nothing here for a JSON parse or
  `PhpTokenParser` pass to buy over just letting PHP evaluate the `return`. Verified against the
  real corpus: WooCommerce's `MarketingCampaign.php` false positive is gone, and all 7 real
  plugins in the test corpus (akismet, contact-form-7, elementor, jetpack, woocommerce,
  wordfence, wp-smushit) still scan clean (no crashes, no regressions) post-fix.

- [x] **Every Composer-mapped file above was exempted wholesale — being autoloadABLE isn't proof
  a class is actually used.** Raised directly: "just because a file can be autoloaded does not
  mean it is used." Fixed for the case where it's actually sound: PSR-4/classmap dirs/files
  resolving OUTSIDE `$rootDir/vendor/` (`$projectAutoloadDirs`/`$projectAutoloadClassFiles`) are
  the scanned project's OWN first-party code laid out for Composer's class loader instead of a
  literal `include()`/`require()` — exactly what this analyzer exists to check, unlike a genuine
  third-party dependency under `vendor/` (still blanket-exempted; auditing a dependency's own
  internal dead code is out of scope). `isProjectAutoloadedClassUsed()` now requires the mapped
  class's short name (a classmap entry's own array key, or a PSR-4/psr-0 file's own basename —
  the same filename-equals-class-name rule PSR-4 autoloading itself depends on, so trustworthy
  here unlike the spl_autoload_register case below) to actually appear in
  `$referencedClassNames`, built the same way `ClassAnalyzer::findUnusedClasses` already builds
  its own version of this set: every `PhpTokenParser`-tracked `$classReferences` entry, PLUS
  every string literal in `$result->functionCalls` (needed for WP's bare-string class
  registration pattern — WooCommerce's own `Init.php` hands an array of FQCNs to a generic
  "instantiate each of these" REST-controller loop; without this fallback,
  `MarketingCampaigns`/`MarketingCampaignTypes` false-positived the moment real-usage-proof
  replaced the blanket pass). Two more corpus-driven fixes to the vendor/project split itself:
  (1) some plugins vendor a dependency *outside* `vendor/` via php-scoper/Mozart/Strauss-style
  prefixing (WooCommerce bundles GraphQL, Symfony Polyfill, League/ISO3166, Pelago/Emogrifier,
  and PSR/Container under `lib/packages`, mapped through the PSR-4 prefix
  `Automattic\WooCommerce\Vendor\`) — `isVendorPrefixedNamespace()` additionally treats any PSR-4
  prefix with a literal `Vendor\` segment (Mozart's own documented default convention) as
  vendor-like regardless of physical path, else all 26 of those files false-positived at once;
  (2) composer.json's own classmap/psr-4 entries are always project-own by construction (a
  package only ever declares its own layout in its own composer.json) except this same
  Vendor-prefix case, which a project can just as easily declare locally. Known accepted
  residual: WooCommerce's `MarketingCampaign.php` (the original motivating example — a public
  extension-point class with zero internal callers by design, confirmed via its own docblock)
  correctly comes out "unreferenced" under this stricter check, but stays unflagged in practice
  because `src/Autoloader.php`'s own hand-rolled `spl_autoload_register()` fallback separately
  blanket-exempts the whole `src/` tree (see the entry below) — a real, if narrow, gap where two
  independent exemption mechanisms happen to cover the same directory in the same plugin.

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

- [x] **A dynamic-middle-segment `require`'s captured directory-prefix literal wasn't trimmed to
  a real directory boundary when it also carried a filename prefix.** `PhpTokenParser::
  findIncludeDirPrefixBeforeVariable()` (the mechanism behind the Kadence-theme fix above it,
  confirmed via `require_once get_template_directory() . '/inc/customizer/options/' . $key .
  '-options.php';`) captures whichever string literal sits directly before the dynamic segment
  and trusts it as-is — correct for Kadence's own shape, since that literal already ends in `/`
  (a clean directory boundary), but wrong whenever the literal instead mashes a real directory
  together with a filename *prefix*. Found by a fresh gap-hunting pass over an expanded real-theme
  corpus: Sydney theme's `inc/dashboard/class-dashboard.php`:
  `require get_template_directory() . '/inc/dashboard/html-' . $tab_id . '.php';` — the captured
  literal `/inc/dashboard/html-` was trusted verbatim as a directory, which can never match a real
  file (`html-` isn't a subdirectory), silently defeating the whole exemption — 8 of Sydney's 14
  `--type=files` findings were every `inc/dashboard/html-*.php` tab-content partial this exact
  line loads, none of them actually dead. Fixed: the captured literal is now trimmed back to the
  last real `/` boundary whenever it doesn't already end in one (a no-op for Kadence's own clean
  case); a literal with no `/` at all (nothing directory-shaped to exempt) now returns null rather
  than an empty string, which would otherwise be misread downstream as "exempt the whole project"
  the same empty-string convention `FileAnalyzer::isUnderDynamicLoadExemptDir` already gives a
  root-level bulk-include caller. Verified: Sydney's own dashboard tab partials no longer flagged
  (14 → 7 findings), full 19-target corpus sanity pass (11 plugins + 8 themes) shows no crashes,
  and the existing Kadence/`__DIR__`-relative regression tests pass completely unchanged. This
  "prefix + dynamic-tab + suffix" `require` shape is a common WP admin tabbed-settings-page
  convention — likely to recur beyond just these two themes.

- [x] **A cross-file "bulk-directory-loader" method call wasn't recognized as a bulk-include at
  all.** Every existing bulk-include mechanism (`glob()`/`scandir()` loops, the dynamic-middle-
  segment `require` fix above, `spl_autoload_register()`) only looks *within a single file* for the
  co-occurring signals (a directory-shaped literal, an include/require keyword). Found by a fresh
  gap-hunting pass over an expanded real-theme corpus: Flynt theme's `functions.php` calls
  `FileLoader::loadPhpFiles('inc')`, and `loadPhpFiles()` itself — declared in a completely
  different file, `lib/Utils/FileLoader.php` — walks that directory via `DirectoryIterator` and
  `require_once`s every PHP file it finds, from inside a closure passed to a second helper method.
  There's no `glob()`/`scandir()` call anywhere in either file, and the literal directory name and
  the `require_once` that actually consumes it live in two separate files, connected only by an
  ordinary method call — invisible to every existing mechanism, and ~20 of Flynt's `inc/*.php`
  files were false-positived as a result. Fixed with the same "coarse net, not proven causality"
  trade-off the existing `glob()`-loop detection already accepts, just spanning two files instead
  of one: `PhpTokenParser` now tracks, per function/method body (including nested closures, via a
  brace-depth-tracked stack matching the existing per-scope tracking pattern), whether an
  include/require keyword appears anywhere inside it (`FunctionDef::$hasIncludeInBody`); every
  scoped call (`Foo::bulkLoad('inc')`) with a plain string-literal first argument is recorded
  unconditionally as a candidate (`PendingDirectoryLoaderCall`), regardless of what the callee
  actually does. `FileAnalyzer::loadDynamicLoadExemptDirs` resolves these once every scanned
  file's parse is merged: a candidate only becomes a real directory exemption when
  `$receiverClass::$methodName` resolves to a method whose own `hasIncludeInBody` is true — the
  same merge-after-all-files-parsed pattern `ClassAnalyzer`'s `PendingReturnTypedCall` resolution
  already uses. Verified: new `PhpTokenParserTest` cases confirm `hasIncludeInBody` detection (both
  a direct `require` and one nested inside a closure) and `PendingDirectoryLoaderCall` capture at
  the call site; new `FileAnalyzerTest` end-to-end cases confirm the Flynt shape is exempted and
  that a lookalike call to a method whose body has no include is correctly left un-exempted (no
  false negative introduced); real Flynt corpus (`../wp-tests/themes/flynt-v2.1.2`) now scans
  `--type=files` with zero findings; full 19-target corpus sanity pass (11 plugins + 8 themes)
  shows no crashes; full suite (506 tests) and phpstan both green. **Scope limitations**: only the
  `Class::method('literal')`/`self::method('literal')`/`static::method('literal')` scoped-call
  shape is recognized — `$this->method('literal')` (an ordinary property/local-variable-scoped
  call, a different code path in the parser entirely) is not instrumented, so that variant of the
  same pattern would still go undetected; the literal must be the call's *first* argument
  specifically.

- [x] **Two related base-class/interface widening gaps found in Yoast SEO's heavily
  interface-and-abstract-class-based dashboard code, both variants of the same "declared on the
  base/interface, only ever satisfied by a descendant" shape `isUsedByDescendantReceiver()`
  already exists for.**

  1. **A property declared on an abstract base class, only ever populated by a concrete
     subclass's own constructor via a plain (non-promoted) typed parameter, was invisible to a
     `$this->prop->method()` read site back in the base class's own body.**
     `Abstract_Scores_Route::$score_results_repository` is read via
     `$this->score_results_repository->get_score_results(...)` from inside
     `Abstract_Scores_Route`'s own method — but the property is only ever assigned in a concrete
     subclass's constructor: `Readability_Scores_Route::__construct(Readability_Score_Results_Repository
     $x) { $this->score_results_repository = $x; }`. `$this->` at the read site resolves to
     `Abstract_Scores_Route` (where the code physically is), never the subclass that did the
     assigning, so `$propertyAssignedClasses[$call->ownerClass]` always missed — even though the
     parser already correctly tracked the assignment itself (`assignedVariableClassName()` already
     resolves a plain typed-parameter-to-property assignment, this part of the pipeline wasn't the
     gap). Fixed: `ClassAnalyzer::findUnusedMethods`'s `propertyMethodCalls` resolution now falls
     back to `$descendantsOf[$call->ownerClass]` (already built for
     `isUsedByDescendantReceiver`'s own scoped-call case) when the direct lookup misses, trusting
     the first known descendant that assigned the same property name — same coarse
     "any resolvable concrete type is good enough" trade-off `isUsedByPolymorphicCall` already
     accepts.
  2. **`$descendantsOf` only ever walked `extends`, never `implements` — so an interface's own
     bodyless method declaration had no equivalent rescue mechanism at all**, unlike an abstract
     class's shared concrete method (which is usually incidentally rescued the moment *any*
     concrete subclass is itself called via its own receiver somewhere).
     `Score_Results_Collector_Interface::get_score_results()` is only ever reached through a
     concrete implementer resolved to its own concrete type
     (`Cached_Readability_Score_Results_Collector::get_score_results()`, via fix 1's property
     resolution above) — never through a call scoped to the interface type itself — so
     `scopedCalled[interface][method]` was never populated, and no widening mechanism existed to
     credit the interface's own declaration from a satisfied implementer the way base-class
     methods already are. Fixed: `$descendantsOf`'s build walk now also records every class's own
     `implements` clause at each level of the extends chain walked (mirrors `isContractMethod`'s
     existing "implements is checked at every level" stance), turning it into a general "who
     satisfies this type" map instead of a pure extends-chain one; `isUsedByDescendantReceiver`
     needed no change at all, since it already just does a flat lookup regardless of whether
     `$ownerClass` names a class or an interface.

  Verified: two new `ClassAnalyzerTest` cases (one per fix, both modeled directly on the real
  Yoast shapes above, including a genuinely-unused sibling method in each fixture to prove no
  false negative was introduced); real Yoast SEO corpus — both real `get_score_results` findings
  (the abstract-repository one and the interface one) gone; full 19-target corpus sanity pass (11
  plugins + 8 themes) shows finding counts only ever decreasing or unchanged, never increasing,
  and no crashes — most notably Yoast SEO itself, 492 → 441 unused-method findings; full suite
  (508 tests) and phpstan both green. **Scope limitation**: the interface fix is deliberately only
  one level deep — an interface that itself `extends` another interface
  (`Section_Interface extends Item_Interface`) is not walked further when building the reverse
  map, so a class implementing only the child interface won't count as a descendant of the
  grandparent interface. Not hit by either real-world case found here, but a known, narrower gap
  than full transitive-interface support would close.

- [x] **`use function Fully\Qualified\Name;` (PHP's function-import syntax) wasn't tracked at
  all — a bare call to an imported function never resolved to its real declaration when that
  declaration lives in a namespace that's neither the caller's own nor the global fallback.**
  `PhpTokenParser::parseUseImports()` explicitly bailed out (`return [];`) the moment it saw
  `use function`/`use const`, treating both as "not a class import, nothing to record" — correct
  for `use const` (irrelevant to this tool), but silently discarded exactly the information needed
  to resolve the call it imports. A bare call's only two existing candidates
  (`FunctionCall::$extraCandidateFqcn` — the plain name, or a current-namespace-prefixed guess)
  both miss whenever the real declaration lives in a *third* namespace, imported by name instead
  of inferred from context. Found by a fresh gap-hunting pass over the plugin corpus: Jetpack's
  `json-endpoints/class.wpcom-json-api-update-post-v1-2-endpoint.php` has
  `use function Automattic\Jetpack\Extensions\Map\map_block_from_geo_points;`, then calls it bare
  — the function is declared in a completely different file/namespace
  (`extensions/blocks/map/map.php`), so neither existing candidate ever named it. Not an isolated
  case: 164 `use function` import lines exist across 5 of the 11 scanned plugins. Fixed generally,
  not just for this one call site: `parseUseImports()` now also parses `use function
  Name\Space\foo [as alias];` (still bailing cleanly on `use const` and on group-use, exactly as
  before), returning a second, separate alias => FQCN map alongside the existing class-import one
  — kept separate because a function import and a class import don't collide even when they'd
  share a bare name, and because a *function*-imported name changes what a same-named bare *call*
  resolves to, never what a class *reference* does. Both bare-call-dispatch sites that compute
  `extraCandidateFqcn` now check this map first — a `use function` import shadows the usual
  current-namespace-then-global runtime fallback deterministically (PHP fixes it at compile time),
  so a match there takes priority over the plain namespace-prefixed guess, not just an additional
  fallback.

  Verified: 4 new `PhpTokenParserTest` cases (import + bare call across namespaces, `as`-aliasing,
  import-wins-over-namespace-fallback priority, and confirming the existing "`use function`
  is not recorded as a class" test still passes unchanged) and one new end-to-end
  `FunctionAnalyzerTest` case modeled directly on the real Jetpack shape (declaration and importing
  caller in different namespaces, in different files); real Jetpack corpus:
  `map_block_from_geo_points` no longer flagged, and the plugin's total unused-function count
  dropped from 51 to 49 (more than the one traced case — other `use function` imports elsewhere in
  Jetpack benefited too); full 19-target corpus sanity pass (11 plugins + 8 themes) shows every
  other finding count unchanged and no crashes; full suite (512 tests) and phpstan both green.

- [x] **A callback-name or file-path literal split across a bounded numeric `for`-loop's own
  counter (`'prefix_' . $i` inside `for ($i = 1; $i < 5; $i++)`) couldn't be resolved to any of
  its N real concrete values at all — the parser has no concept of loop semantics.** Found by a
  fresh gap-hunting pass over the full corpus, independently in **two** themes:
  - Sydney's customizer (`inc/customizer/customizer.php`):
    `'render_callback' => 'sydney_partial_slider_title_' . $i` inside `for ($i = 1; $i < 5; $i++)`
    — 8 real functions (`sydney_partial_slider_title_1..4`/`subtitle_1..4`) false-positived as
    unused. Bonus: Sydney's own loop bound is off-by-one — `sydney_partial_slider_title_5`/
    `subtitle_5` also exist as real declarations but the loop never reaches 5, so a *correct* fix
    needed to keep flagging those two specifically, not just quiet everything with that prefix.
  - Astra's icon loader (`inc/core/common-functions.php`):
    `"{$icons_dir}/icons-v6-{$i}.php"` inside a similar bounded loop — 4 files false-positived.

  Fixed generally, not by special-casing either theme: `PhpTokenParser` now recognizes the clean
  canonical bounded-ascending `for` form (`parseForLoopBoundedRange()` — int-literal init,
  int-literal bound compared with `<`/`<=`, unit `$var++` increment; anything else, e.g. a
  non-literal bound, is left completely untracked, same "don't guess" stance as everywhere else
  in this parser) and tracks the loop variable's own concrete value range with the same brace-
  depth-tracked stack pattern used throughout (`$forLoopVarDepthStack`/`$forLoopVarNameStack`/
  `$forLoopVarValuesStack`). A new `resolveForLoopConcatenatedLiteral()` recognizes a string
  literal immediately concatenated with a tracked loop variable (optionally followed by a further
  literal suffix) and enumerates one concrete string per value in the loop's range — computed
  once per literal and shared by both existing consumption sites it affects (the bare/scoped
  callback-name resolution, and the `.php`-suffixed file-path check), since a callback-shaped
  identifier and a `.php`-suffixed path are mutually exclusive per literal in practice. A
  resolved array-callback receiver present at the same time now gets *exact* enumerated
  `ScopedMethodCall`s instead of the coarser `ScopedMethodCallPrefix` fallback the existing
  `array($this, 'footer_html_' . $index)` mechanism already used — a real, automatic precision
  upgrade to that pre-existing mechanism, confirmed by updating its own test to the sharper
  expected behavior once the loop in that exact fixture became recognizably bounded.

  Verified: 3 new `PhpTokenParserTest` cases (Sydney's exact bare-callback shape enumerating all 4
  names, a file-path-via-concatenation case for the same mechanism applied to `phpPathStrings`,
  and a non-literal-bound loop confirming no enumeration/no regression when the shape isn't
  recognized) plus one existing test updated to its new, more precise expected output (see above);
  real Sydney corpus — `sydney_partial_slider_title_1..4`/`subtitle_1..4` no longer flagged, `_5`
  *correctly still flagged* (proving this isn't just "quieter," it's more precise), and the
  theme's total unused-function count dropped from 32 to 24; full 19-target corpus sanity pass (11
  plugins + 8 themes) shows every other finding count unchanged and no crashes; full suite (516
  tests) and phpstan both green. **Scope limitation, found and deliberately not chased further
  here**: Astra's own real-world case uses double-quoted *string interpolation*
  (`"...{$icons_dir}/icons-v6-{$i}.php"`) assigned to a variable, then `include_once`d — a
  fundamentally different token shape (interpolated strings tokenize as several separate tokens,
  not one literal) than the concatenation this fix recognizes, and this parser has no interpolated-
  string-to-template resolution mechanism at all yet (elsewhere or here) to build on. Astra's
  `icons-v6-0..3.php` false positives remain unresolved; would need a separate, larger effort to
  parse interpolated-string templates generally, not a small extension of this fix.

- [x] **Legacy `spl_autoload_register()` class-map callbacks — partially recognized, known
  remaining gap accepted as-is.** Pre-Composer (or hybrid) WP plugins sometimes register their
  own autoloader mapping class name → file path in code, rather than declaring `composer.json`
  autoload rules. `FileAnalyzer::loadDynamicLoadExemptDirs` already detects any
  `spl_autoload_register(...)` call and exempts the *calling file's own directory* from
  candidacy (real-world cases confirmed: Kadence, Hello Biz, Hello Elementor — the callback and
  every file it can load live together in the same directory tree). Confirmed against a fourth
  real plugin (Elementor's `includes/autoloader.php`) that this heuristic is too narrow in
  general: Elementor's `Autoloader::run()` defaults its target root to `ELEMENTOR_PATH` — a
  constant defined in the plugin's main bootstrap file, resolving to the whole plugin root, not
  `includes/` — so files it autoloads outside `includes/` still false-positive as unused.
  Widening the exemption to the whole project root whenever *any* file anywhere calls
  `spl_autoload_register()` would fix this (and is probably closer to correct for most
  real-world hybrid autoloaders, which are rarely restricted to a single sibling directory) —
  but was considered and explicitly declined: the cost is that unused-file detection turns off
  entirely, project-wide, for any theme/plugin with a hand-rolled autoloader anywhere in it,
  which is a much bigger precision trade than the current directory-scoped exemption. A fully
  precise fix (actually resolving the callback's own path logic) remains intractable in general
  — confirmed by reading Elementor's real callback, which combines a hardcoded class map, a
  CamelCase→kebab-case convention transform, *and* a deprecated-alias system in the same
  function, with no single shape a generic parser could match. Left as-is: caller-directory
  exemption only, Elementor-style project-root autoloaders remain a known, accepted false-
  positive source.

  Also tried and reverted: extending the same real-usage-proof built for Composer's PSR-4 case
  (see the entry above) to this exemption too — requiring the caller directory's basename-as-
  class-name to appear in `$referencedClassNames` before exempting, instead of a blanket pass.
  Sound for Composer because PSR-4 *enforces* filename-equals-class-name as part of the
  autoloading spec itself; a hand-rolled `spl_autoload_register()` callback is bound by no such
  rule. Falsified immediately against a fifth real plugin (wp-smushit): `app/class-admin.php`
  declares `class Admin` — WP's own long-standing `class-{slug}.php` file-naming convention,
  which strips the `class-` prefix before ever comparing to a real class name — so "does the
  basename appear as a referenced class name" was always false there, and every file under that
  autoloader's tree false-positived at once (a previously fully-clean plugin, 0 → 221 findings).
  Confirms the original scope call above rather than superseding it: reverted back to the
  directory-scoped blanket exemption.

# Function, Hook & Template Detection — Open Issues

Known gaps in `FunctionAnalyzer`, `HookAnalyzer`, and `TemplateAnalyzer` — the `functions`,
`hooks`, and `templates` checks. Same spirit as the sections above: documented scope limits in a
single-pass, no-AST tokenizer, not shipped bugs. Found by auditing these three analyzers the same
way the class/method/file gaps above were found; nothing here has been fixed yet.

## Template detection

- [x] **`TemplateAnalyzer` doesn't know about `Template Name:` custom page templates.** Fixed:
  `TemplateAnalyzer` now has its own `hasPageTemplateHeader()` (same regex as
  `FileAnalyzer`'s), checked right alongside `isHierarchyTemplate()` for every collected
  template file. WP Page Templates are selected from the admin UI by scanning the theme for
  this header comment — never through a literal `include()`/`get_template_part()` call
  anywhere in project code — so a custom-named page template like `template-landing.php` (an
  author-chosen name is the whole point of a custom page template) has no hierarchy name to
  match and, before this fix, always got falsely flagged `UnusedTemplate`.

- [x] **Roots Sage/Acorn's Blade views (`resources/views/`) were invisible as a template
  concept entirely and fell through to `FileAnalyzer` as generic "unused files."** Found by
  running wp-specter against a real Sage theme: 18 false-positive `UnusedFile` findings, every
  `.blade.php` in the theme. Root causes and fixes:
  1. `resources/views` wasn't in either analyzer's `TEMPLATE_DIRS` list, so `FileAnalyzer`
     treated every Blade view as a generic support file instead of handing it to
     `TemplateAnalyzer` — added to both, same hand-off convention as `templates`/
     `template-parts`/`parts`.
  2. `TemplateAnalyzer` computed a template's basename via `basename($file, '.php')`, which
     only strips the final `.php` — on `single.blade.php` that leaves `single.blade`, never
     matching `WpModeDetector`'s hierarchy name list (`single`). New
     `TemplateAnalyzer::templateBasename()` strips `.blade.php` as a unit first. This alone
     fixed most of Sage's default views: its WP-hierarchy-equivalent files
     (`index`/`single`/`page`/`404`/`search.blade.php`) and several partials that happen to
     share a name with a real hierarchy entry (`sections/header.blade.php`,
     `sections/footer.blade.php`, `sections/sidebar.blade.php`, `partials/comments.blade.php`,
     `partials/page-header.blade.php` via the existing `page-` prefix match) were already
     covered by the existing hierarchy-exemption logic once the basename was computed
     correctly — no Blade-specific handling needed for those.
  3. The remaining views (`layouts/app`, `partials/content*`, `partials/entry-meta`,
     `components/alert`) are only reachable through Blade's own directive syntax
     (`@extends('layouts.app')`, `@include('partials.x')`, `@includeFirst([...])`,
     `<x-alert>`), which isn't PHP syntax at all — a `.blade.php` file is almost entirely
     inline HTML/text from a tokenizer's point of view, so `PhpTokenParser`'s
     `T_INCLUDE`/`T_REQUIRE`-based include-ref detection never sees any of it. New
     `TemplateAnalyzer::extractBladeReferences()` scans a `.blade.php` file's raw content
     directly (regex/manual paren-matching, not tokenizing) for a curated list of
     include-family directives (`BLADE_INCLUDE_DIRECTIVES`) plus anonymous component tags
     (`<x-name>` → `components/name.blade.php`), converting Blade's dot notation to slashes.
     Deliberately grabs every quoted string literal inside a directive's parens rather than
     trying to identify "the" argument — `@includeFirst(['partials.content-' .
     get_post_type(), 'partials.content'])` needs the second array element even though the
     first is dynamic, and `@includeWhen($cond, 'partials.entry-meta')`'s view name isn't the
     first argument.
  4. Bug caught fixing this: `collectTemplateFiles()`'s "always skip functions.php/index.php"
     guard matched on basename alone, so `resources/views/index.blade.php` (a real,
     legitimately-used hierarchy template) was being silently filtered out before ever
     becoming a template candidate — same failure mode as the bootstrap `index.php` exemption,
     just colliding on name. Fixed to match the exact root-relative path instead.

  Not attempted: Acorn's own internal view-resolution conventions with zero literal reference
  anywhere in project code at all (e.g. `forms/search.blade.php`, wired to `get_search_form()`
  by Acorn's own vendor code, not project code) — turned out to be a non-issue in practice
  here purely by naming coincidence (`search` is already a real WP hierarchy name so it's
  exempted either way), but a differently-named case of the same pattern would still be a
  false positive. Same category of gap as the View Composer/ServiceProvider auto-discovery
  case documented under Class detection above; not worth a special case without a second
  real-world example motivating it.

- [x] **`TemplateAnalyzer`'s WP-hierarchy exemption fired for Plugin-mode scans too, not just
  theme scans — but enabling the check for plugins was tried and reverted after real-corpus
  verification showed it makes things worse, not better.** Found by a fresh gap-hunting pass:
  `isHierarchyTemplate()` was gated on `$mode !== WpMode::Block`, which is also true for
  `WpMode::Plugin` — so a plugin's own bundled `templates/`-directory file whose name happens to
  start with a WP hierarchy prefix (`taxonomy-`, `single-`, `archive-`, ...) got the same
  free-pass exemption a theme's real hierarchy file correctly gets, even though WP's own
  `locate_template()` never auto-locates a *plugin's* bundled templates that way — that's purely
  the plugin's own override-lookup convention (WooCommerce's `WC_Template_Loader`, and every
  CPT-heavy plugin that copies the pattern). Confirmed real, currently-unflagged case:
  WooCommerce's `templates/taxonomy-product-cat.php`/`taxonomy-product-tag.php` have zero literal
  reference anywhere in its source (reachable only via `$default_file = 'taxonomy-' .
  $object->taxonomy . '.php';`, a runtime concatenation no static tokenizer can resolve) — both
  escape detection purely because of this exemption bug.

  **Fixed at the `TemplateAnalyzer` level**: the hierarchy exemption now only fires for an actual
  theme scan (`$mode === WpMode::Classic || $mode === WpMode::Hybrid || $mode === null`), mirroring
  `collectTemplateFiles()`'s own existing "is this a theme scan" condition for root-level file
  collection. Covered by new unit tests
  (`testDoesNotExemptHierarchyNamedTemplatesInPluginMode`/`testStillExemptsHierarchyTemplatesInHybridMode`).

  **Originally NOT wired up to actually run for Plugin mode** — `Application.php` independently
  skipped the whole templates check for `WpMode::Plugin` targets (a second, separate gate), first
  enabling it was tried and reverted after real false positives surfaced: WooCommerce loads most
  of its own `templates/` directory through its own wrapper functions (`wc_get_template()`/
  `wc_get_template_part()`), not the WP-core `get_template_part()`/`get_header()`/`get_footer()`/
  `get_sidebar()` calls `PhpTokenParser`'s `TEMPLATE_FUNCS` list tracked at the time —
  `wc_get_template_part('content', 'product')`/`('content', 'single-product')` calls were
  genuinely used but statically invisible, since the parser had no way to know
  `wc_get_template_part` maps its two string arguments to a `slug-name.php` filename the way it
  already knew `get_template_part`'s single argument does.

  **Fixed properly, then re-enabled**, in two parts:
  1. `TEMPLATE_FUNCS` originally also listed `wc_get_template_part`/`wc_get_template` and
     WooCommerce's own documented legacy aliases `woocommerce_get_template_part`/
     `woocommerce_get_template` by exact name — treated identically to `get_template_part` (arg 0
     becomes the template ref), deliberately scoped to WooCommerce specifically rather than a
     generic suffix guess since that was the only confirmed real-world example in this project's
     test corpus at the time.

     **Superseded** once a second, independent real-world instance of the exact same shape
     turned up (Sydney theme's own `sydney_get_template_part()`) — confirming this is a
     widely-replicated WordPress-ecosystem naming convention, not one plugin's own invention, and
     a fixed name list would only ever cover whichever specific plugins happened to get traced by
     hand. Replaced with `PhpTokenParser::isTemplateLoaderFunc()`: a name matching
     `TEMPLATE_FUNCS` (now just the 4 WP-core functions) OR ending in `_get_template_part`/
     `_get_template` is treated the same way — no per-plugin addition ever needed again. Found
     and fixed as part of this generalization: a bare call to a recognized template-loader
     function was never also credited as an ordinary function call (`return;` right after
     recording the template ref, before ever reaching the generic `$functionCalls[] = ...` at the
     bottom of `dispatchBareFunctionCall`) — harmless for a WP-core name (never itself
     project-declared) but a real false positive the instant a project both declares AND calls
     its own wrapper, exactly Sydney's `sydney_get_template_part()` shape. Fixed by also pushing
     to `$functionCalls` inside the template-loader branch itself, using the same
     already-computed `$extraCandidateFqcn` the generic fallback path uses.

     Verified: new `PhpTokenParserTest` cases (the general suffix match across 4 independently-
     named wrappers including one with no real-world corpus evidence at all — EDD's
     `edd_get_template_part()` — proving genuine generalization, not just re-listing what was
     already confirmed; and a negative case confirming a merely-similar name like
     `wc_get_templates()`/`some_get_template_data()` doesn't false-positive) and a new
     `FunctionAnalyzerTest` case for the call-credit fix; real corpus — WooCommerce's own
     unused-template count unchanged (5, confirming no regression from the delisting), Sydney's
     `sydney_get_template_part()` no longer flagged unused and 3 more of its own real templates
     (reached only through it) no longer flagged either; full 19-target sanity pass shows every
     other finding count unchanged and no crashes; full suite (519 tests) and phpstan both green.
  2. A second, independent bug found while verifying the first fix: `TemplateAnalyzer`'s
     existing `isReferencedByPartialMatch()` (the mechanism that already made
     `get_template_part('slug', $name)` => any `slug-*.php` file reachable) checked the
     candidate file's *full relative path* (`templates/content-product.php` for a plugin) against
     the bare slug ref (`content`) — a prefix match that can never succeed once a directory
     segment sits in front of it. This never mattered for a theme, where hierarchy files
     conventionally sit at the theme root with no such prefix; it broke immediately for a
     plugin's own nested `templates/` directory. Fixed by also checking the candidate's bare
     basename (no directory, no extension) against the same partial-match logic, bringing Plugin
     mode's precision up to parity with what theme mode already had.

  `Application.php`'s Plugin-mode exclusion removed once both were verified fixed. Real-corpus
  result for WooCommerce (the only plugin among all 7 in the test corpus that ships a
  `templates/` directory at all): 8 → 5 findings, with the 3 removed
  (`content-product.php`/`content-single-product.php`/`content-product-cat.php`) confirmed
  previously-genuine false positives, and the remaining 5 confirmed either directly (the two
  taxonomy files) or by the same "resolved only via runtime string concatenation, unresolvable
  statically" pattern (`taxonomy-product_brand.php`, and `my-downloads.php`/`my-orders.php`,
  which have zero reference anywhere in the codebase under any name). New test coverage:
  `PluginTest::testUnusedPluginTemplateIsDetected` (integration, a fixture plugin's own
  `templates/` directory with a used and an orphaned file). Full 7-plugin sanity pass (all 6 of
  the other plugins have no `templates/`/`template-parts/`/`parts/` directory at all, so nothing
  to check either way) shows no crashes.

## Function detection

- [x] **Namespaced/fully-qualified function calls were invisible to `FunctionAnalyzer`.** The
  only call-detection branch in `PhpTokenParser::parse` fired on `T_STRING`; a call like
  `Foo\Bar\my_helper()` or `\My\Ns\init()` tokenizes as `T_NAME_QUALIFIED`/
  `T_NAME_FULLY_QUALIFIED`, and the main loop's per-token dispatch never checked those types for
  call purposes at all — the token was silently skipped, not even reaching the `'('`-lookahead
  that would otherwise register a `FunctionCall`, making a real, called function look unused.
  Fixed exactly per the fix shape below: the existing `T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED`
  block (added earlier for the `::` receiver case — `\SwiftQueue\License_Bridge::initialize()`)
  now also checks for `'('` and registers a `FunctionCall` resolved to the unqualified tail via
  `shortClassName()` — consistent, not just convenient, since a function can only ever be
  *declared* with a bare name in PHP (the enclosing `namespace` block carries the namespace, never
  the declaration's own name token), so `FunctionDef` was already just as namespace-blind on the
  definition side.

- [x] **`FunctionAnalyzer` had the same flat, short-name-only collision `ClassAnalyzer` had before
  its own namespace-aware rework — deliberately deferred at the time since function-call
  resolution has a real ambiguity classes don't (PHP falls back to the global namespace for an
  unqualified call it can't resolve locally; there's no equivalent fallback for an unqualified
  class reference).** Confirmed real and worth fixing by checking the actual corpus first:
  namespaced top-level function *declarations* turned out to be common in real code even where
  namespaced *classes* dominate — Jetpack alone declares roughly 440 of them, Elementor ~50,
  wp-smushit ~10 (WordPress convention keeps hook-callback/helper functions global far more
  consistently than it keeps classes global, but far from universally).

  Fixed with a scheme that respects the real asymmetry between declarations and calls, not a
  copy-paste of the class-name fix:
  - `FunctionDef` gained an `$fqcn` field (declarations have no resolution ambiguity of their
    own — resolved the same deterministic way `ClassDef::$fqcn` is). `FunctionAnalyzer` now keys
    `$definitions` by this instead of the bare name.
  - `FunctionCall` gained `$extraCandidateFqcn` (nullable) instead of changing `$name`'s existing
    meaning at all — `$name` stays the bare identifier every other consumer of
    `ParseResult::$functionCalls` (`ClassAnalyzer`'s bare-string class-reference fallback,
    `FileAnalyzer`'s equivalent) already relies on unchanged. Three shapes, three treatments:
    - A qualified/fully-qualified call (`Foo\Bar\helper()`, `\My\Ns\init()`) resolves
      deterministically — no runtime fallback — via the same `resolveFqcn()` a class reference
      uses; `$extraCandidateFqcn` holds that one real target.
    - A bare call made from *inside* a namespaced file is the genuinely ambiguous case:
      `$extraCandidateFqcn` holds the "if it resolves locally" candidate
      (`$currentNamespace . '\\' . $name`), while `$name` itself still stands in for the "or it
      falls back to global" candidate — `FunctionAnalyzer` credits a definition matching either,
      favoring a false negative over a false positive in the rare case both exist (the same
      conservative bias this analyzer already took for its own unscoped-pool fallback).
    - A bare call from *un-namespaced* code (still the common case — around 60% of real files in
      this corpus) gets `$extraCandidateFqcn: null`; `$name` is already the only candidate,
      exactly as before this fix existed. Zero behavior change there.
  - The same string-literal-callback path that already needed a namespace-aware fix once before
    (`__NAMESPACE__ . '\my_handler'`, the Sakurairo theme regression covered above) needed the
    identical `$extraCandidateFqcn` treatment applied to it too — found by an existing regression
    test failing the moment `$definitions` switched to FQCN keys, confirming that code path is a
    second, independent `FunctionCall` producer this fix had to cover, not just the main
    `dispatchBareFunctionCall()` one.

  Verified against the real corpus (before/after `git stash` comparison, `--type=functions`):
  akismet, contact-form-7, woocommerce unchanged (no real collisions present). Jetpack: 49 → 51,
  both new findings traced to a real root cause, not a false positive from this fix — a bare call
  made from *un-namespaced* legacy code (`json-endpoints/class.wpcom-json-api-update-post-v1-2-
  endpoint.php`, a WordPress.com REST API v1.2 endpoint) to a function only ever declared inside
  `Automattic\Jetpack\Extensions\Map`/`...\Shared`, with no `use function` import anywhere bringing
  it into global scope — as written, that call would fail at runtime with "call to undefined
  function." Whether the true root cause is dead code or a latent bug elsewhere in Jetpack, a
  bare, unqualified call from the global namespace to a namespace-only function is exactly the
  case this fix is designed to stop silently masking. No crashes on any of the 7 plugins.

- [x] **`FunctionAnalyzer`'s blanket `wp_`/`get_`/`the_`/`is_`/`has_`/`do_`/`apply_` name-prefix
  exclusion was undocumented since the project's very first commit and provably too broad in one
  direction while only accidentally correct in the other.** Found by a fresh gap-hunting pass:
  any function whose name merely *starts with* one of these prefixes was excluded from "unused"
  reporting entirely, unconditionally — no comment anywhere explaining why, unusual for this
  codebase, which documents every other trade-off in detail. Confirmed real false negative
  (wp-smushit): a same-named function with every call site commented out would be invisible
  purely because of its `wp_` prefix — though on closer inspection this exact function turned out
  to *also* be a real `function_exists()`-guarded polyfill (see below), so it wasn't actually a
  clean example of the prefix hiding otherwise-checkable dead code; the underlying "any
  `wp_`/`get_`/etc-prefixed function gets a free pass regardless of whether it's guarded" problem
  is still real for any unguarded same-prefixed function, just not demonstrated by that specific
  corpus example. Confirmed the legitimate case the exclusion was accidentally protecting
  (wp-smushit again): `wp_sizes_attribute_includes_valid_auto()`, a polyfill for a real WP-core
  function of the same name, guarded by
  `if ( ! function_exists( 'wp_sizes_attribute_includes_valid_auto' ) )` — called by WP core
  itself once it exists, invisible to any single-plugin scan by design, and genuinely needing
  *some* exemption.

  Fixed: the real signal isn't the name prefix, it's whether the function is declared directly
  inside its own matching `function_exists()` guard — the actual WP polyfill convention. New
  `PhpTokenParser::functionExistsGuardName()` recognizes exactly
  `if ( ! function_exists( 'name' ) ) { ... }` (leading `!` required — the only shape real code
  uses this for, since the non-negated form would define the function only once it already
  exists, an immediate redeclaration fatal error) immediately followed by `{`; a
  `$functionExistsGuardDepthStack`/`$functionExistsGuardNameStack` pair (same push-on-`{`/
  pop-on-`}` pattern as `$classDepthStack`) records the guarded name for that block, and a
  `T_FUNCTION` declaration whose own name matches the current top of the stack gets
  `FunctionDef::$guarded = true`. `FunctionAnalyzer::isExcluded()` now only excludes magic-style
  names (`__`-prefixed); the prefix list is gone, and guarded functions are dropped from
  `$definitions` entirely — a legitimate polyfill is never reported, the same as before, but a
  same-prefixed function that *isn't* guarded is now actually evaluated instead of getting a free
  pass by name alone. Verified: `wp_sizes_attribute_includes_valid_auto()` stays exempt (guarded);
  a synthetic unguarded `wp_my_function()`/`get_my_data()`/etc. is now correctly reported (new
  test coverage, since the real corpus didn't have a live unguarded example). Full 7-plugin
  corpus sanity pass shows no crashes.
  - Deliberately narrower than the `T_STRING` branch: WP's own hook/template/glob/`define`/
    existence-check special-cased function names (`add_action`, `get_template_part`, `glob`,
    `class_exists`, ...) aren't given the same special-case dispatch here. Found while
    implementing this: a fully-qualified call to one of *those* — `\add_action(...)`, a realistic
    pattern in namespaced WP code that explicitly opts out of the current namespace for a global
    core function — is **also** presently invisible to hook/template/glob detection specifically
    (not just `FunctionAnalyzer`), completely independent of this fix (it was already exactly as
    invisible before). Not fixed here — genuinely out of scope for what this item asked for, and
    not yet documented anywhere else, so recorded as its own new item just below rather than
    silently left to be rediscovered.
  - Also confirmed (not a new bug, pre-existing and unrelated): `new Foo\Bar()`/`new Foo()` were
    already adding the bare class name to `$functionCalls` too, a coincidental cross-contamination
    between the class-reference and function-call pools that predates this fix entirely — the new
    qualified-name handling is consistent with that existing behavior, not introducing a new
    instance of it.

- [x] **WP core's own hook/template/glob/`define`/existence-check functions weren't recognized
  when called via a namespaced or fully-qualified name.** `\add_action(...)`,
  `\get_template_part(...)`, `\Foo\Bar\class_exists(...)` inside a namespaced file that explicitly
  opts out of the current namespace for a WP core global — the large `T_STRING`-only dispatch
  block that recognizes these specific function names by string comparison
  (`HOOK_REGISTER_FUNCS`, `HOOK_INVOKE_FUNCS`, `TEMPLATE_FUNCS`, `glob`/`scandir`, `define`,
  `EXISTENCE_CHECK_FUNCS`) never ran for a `T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED` token, so
  a real hook registration/invocation, template-part reference, bulk-include glob, or
  redeclaration guard called this way was silently invisible to `HookAnalyzer`/
  `TemplateAnalyzer`/`FileAnalyzer` alike. Fixed exactly per the fix shape below:
  - The whole dispatch block (previously inline in the `T_STRING` branch) is now
    `dispatchBareFunctionCall()`, a shared private method taking the call's already-resolved name
    — the bare value for a `T_STRING` call, or the unqualified tail (`shortClassName()`) for a
    qualified one — plus every accumulator array it mutates (hook registrations/invocations,
    template refs, glob dirs, defined constants, ...), most by reference. Both the `T_STRING`
    branch and the qualified-name branch now call it, so a WP core function reached either way is
    recognized identically, with no duplicated dispatch logic to keep in sync.
  - Confirmed the resulting cross-contamination (a `define()`/`add_action()`'s own string
    arguments *also* landing in the generic `$functionCalls` pool, since nothing suppresses them
    the way `EXISTENCE_CHECK_FUNCS` explicitly does) is identical, pre-existing behavior for the
    plain non-qualified call shape too — not a new regression introduced by sharing the dispatch,
    just newly visible because a test's first draft wrongly assumed otherwise.
  - Caught by phpstan, not by guessing: extracting the dispatch into its own method turned
    `$line`'s type from an inferred `int` (destructured from the `Token` phpstan-type in the outer
    scope) into an explicit `string|int` parameter (matching the same loose typing
    `parseHookRegistration` and its siblings already declared) — losing the narrowing that let
    `new FunctionCall($name, $line, $file)` type-check before. Fixed with the same `(int) $line`
    cast every other constructor in this method already uses.

## Hook & template tag detection

- [x] **A hook or template-part tag held in a variable resolved to nothing** —
  `$hook = 'my_plugin_loaded'; do_action($hook);` or `get_template_part($dynamic_slug)` came back
  fully dynamic (empty tag, no prefix), so a real, literal `add_action('my_plugin_loaded', ...)`
  registration elsewhere in the project reported as unmatched, and a real
  `template-parts/hero.php` file looked unused. Fixed per the sketched fix shape, for the
  *variable* half of this item (constants are a separate, still-open item just below):
  - New `$varLiteralValueStack`, last-write-wins exactly like `$varTypesStack` but for a string
    value instead of a class name — `$var = 'literal';` seeds it (reusing the same
    `singleStringLiteralRhs` check that already feeds the accumulating
    `$varLiteralAssignmentsStack` for the unrelated return-value-resolution feature; deliberately
    a *separate* stack rather than reusing that one, since accumulating every literal ever
    assigned — right for "what might this function return across every branch" — is the wrong
    semantics for "what does this variable's value actually resolve to right here").
  - `classifyArgTokens` (via `extractStringArgAt`, threaded through
    `parseHookRegistration`/`parseHookInvocation`/`parseCronScheduleHook`/`parseTemplateRef`) now
    also recognizes a bare single-variable argument and resolves it against the current scope's
    map — a resolved variable is treated exactly like a literal directly in the call, so every
    existing consumer (`HookAnalyzer`, `TemplateAnalyzer`, `generate-stubs`) benefits without its
    own separate change.
  - Confirmed real-world (not just theoretical) in the Blocksy theme:
    `inc/components/woocommerce/archive/product-card.php` builds `$action_to_hook` across several
    conditional branches (`'init'`, then reassigned to `'elementor/editor/init'` inside an
    Elementor-editor-context check) before `add_action($action_to_hook, ...)` — previously
    invisible to `HookAnalyzer` entirely; now correctly resolves to (whichever assignment is
    textually last, the same no-control-flow-awareness trade-off `$varTypesStack` already
    accepts) `'elementor/editor/init'` and reports it as unmatched, the same as every other
    external-plugin hook already in that report.
  - Updated three existing tests whose whole premise was the old "always fully dynamic" behavior
    for this exact shape (`PhpTokenParserTest`, `HookAnalyzerTest`, `GenerateStubsTest`) — each
    now also has a companion test confirming a *genuinely* unresolvable variable (assigned from a
    function call, not a literal) still falls back to the old dynamic/skipped behavior correctly.

- [x] **A hook or template-part tag held in a class constant resolved to nothing.**
  `do_action(self::HOOK_NAME)` — fixed per the sketched fix shape:
  - New `$classConstants` (class name => constant name => literal value), populated by a new
    `parseClassConstants()` triggered on `T_CONST` directly inside a class/interface/trait/enum
    body (same brace-depth guard already used for the in-class-body trait `use` case) — file-
    scoped/flat, populated live as the file scans, same trade-off as the existing `$definedConstants`
    (for `define()`) it directly mirrors. Handles multiple comma-separated constants per
    statement (`const A = 'x', B = 'y';`); deliberately doesn't attempt PHP 8.3+ typed constants
    (`const string NAME = ...`) — rare enough in current WP code that guessing which token is the
    type versus the name risked misparsing an untyped one by mistake.
  - `classifyArgTokens` now also recognizes a bare `self`/`static`/`parent`/`Foo::CONST_NAME`
    argument shape (three tokens: receiver, `T_DOUBLE_COLON`, name) and resolves it against
    `$classConstants`, `self`/`static` resolved to whichever class the call is physically inside,
    `parent` to that class's own `extends` target — same resolution logic used everywhere else in
    this parser for a `::` receiver, just reached from inside argument-classification instead of
    the main token loop. Threaded through `parseHookRegistration`/`parseHookInvocation`/
    `parseCronScheduleHook`/`parseTemplateRef` alongside the variable-value map from the item
    above, so `HookAnalyzer`/`TemplateAnalyzer`/`generate-stubs` all benefit without their own
    separate change, the same way the variable fix did.
  - Bug caught while first writing this: comparing the receiver-to-constant separator token
    directly against the bare string `'::'` — like `->` before it, `::` tokenizes as
    `T_DOUBLE_COLON` (an array token), not a plain string, so the naive comparison would have
    silently matched nothing. Caught this time by checking the existing `findScopedCallTarget`
    precedent *before* writing the comparison, rather than by a failing test afterward.
  - Confirmed end-to-end (parser-level tag resolution, `HookAnalyzer` reporting a
    constant-resolved registration as correctly unmatched, and `TemplateAnalyzer` not flagging a
    constant-referenced template part) before writing the regression suite. Not yet observed
    turning up a new real-world finding in this project's own theme test corpus (unlike the
    variable case, which caught a real one in Blocksy) — consistent with this being the rarer of
    the two patterns, as expected going in.

- [x] **Dynamic hook segment *before* the literal part wasn't caught.** `classifyArgTokens` only
  recognized a resolvable *prefix* when the literal came first (`'foo_' . $x` or `"foo_{$x}"`);
  `do_action("{$this->id_base}_widget_updated")` — dynamic first, literal suffix — yielded no
  prefix at all, so any literal registration in that hook family always reported unmatched.
  Fixed exactly per the fix shape below:
  - `classifyArgTokens`'s return tuple grew a 4th element (literal suffix, alongside the existing
    tag/isDynamic/prefix), populated by two new cases mirroring the existing prefix ones but
    checking the *last* two tokens instead of the first two: `$dynamic . 'literal_suffix'`
    (concatenation) and `"...{$expr}literal_suffix"` (an interpolated string ending in a literal
    segment right before the closing quote — doesn't need to understand what precedes it, a
    property access, a function call, several concatenated variables, whatever). Checked only
    after the prefix cases already had their chance, so a tag with *both* a literal prefix and
    suffix (rare — e.g. `"foo_{$x}_bar"`) still credits the prefix, the same "first match wins,
    don't over-engineer" stance the rest of this method already takes.
  - New `HookInvocation::$tagSuffix` (the mirror of the existing `$tagPrefix`), threaded through
    `parseHookInvocation`/`parseCronScheduleHook`; `HookRegistration` deliberately untouched — the
    prefix/suffix mechanism only ever exists to rescue a *literal* registration from looking
    unmatched when what fires it is dynamic, so it only ever needed to live on the firing
    (`do_action`/`apply_filters`) side, same asymmetry the existing prefix mechanism already has.
  - `HookAnalyzer` builds a `$firedSuffixes` set alongside its existing `$firedPrefixes` one and
    checks `str_ends_with()` the same way `matchesAnyPrefix()` already checks `str_starts_with()`.
  - Deliberately scoped to hooks only, not template-part slugs — `TemplateAnalyzer`'s equivalent
    prefix case exists for a real WP convention (`get_template_part("variants/$variant")`, general
    directory before a dynamic filename), but a *suffix*-only template slug (dynamic first,
    literal last) isn't an idiomatic WP naming shape the TODO item or any real example called for,
    so no matching change was made there.
