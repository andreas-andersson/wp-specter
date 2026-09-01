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

- [x] **A trait can call a method it never declares itself — a template-method pattern, expecting
  whoever `use`s it to provide the real implementation — and the real override commonly lives on
  a *concrete descendant* of the trait's direct consumer, not the consumer itself.
  `isUsedByTraitConsumer()` above only composes when the checked method's own `$ownerClass` IS
  the trait; it never fires here at all, since `$ownerClass` is a concrete leaf class several
  inheritance levels below.** Found in a fresh gap-hunt, traced precisely: Elementor's
  atomic-widgets module (`modules/atomic-widgets/`) —
  ```php
  trait Has_Atomic_Base {                          // has-atomic-base.php, @mixin Element_Base
      public function render() {
          $this->define_atomic_controls();         // never declared on this trait itself
      }
  }
  abstract class Atomic_Widget_Base extends Widget_Base {
      use Has_Atomic_Base;                         // the trait's only direct consumer
  }
  class Atomic_Button extends Atomic_Widget_Base {
      public function define_atomic_controls() { ... }   // the real override — several
  }                                                        // levels below the consumer
  ```
  Top 7 method names alone (`define_atomic_controls`, `get_templates`, `define_props_schema`,
  `define_base_styles`, `get_capability`, `get_position`, `has_children`) accounted for 122 of
  Elementor's 502 `UnusedMethod` findings.

  Fixed with a new composed check, `isUsedByAncestorsTraitSelfCall()` — the mirror image of
  `isUsedByTraitConsumer()`'s own walk direction: starting at the checked method's own
  `$ownerClass`, climb its `extends` chain (same bounded walk `isContractMethod()` already uses),
  and at every level visited, check whether any trait that class directly (or transitively — one
  trait using another, via a new `traitsUsedTransitively()` helper mirroring
  `isUsedByTraitConsumer()`'s own BFS but in the opposite direction) `use`s itself calls
  `$this->$methodName()` internally. Needed one new piece of state alongside the existing
  `$traitUsers` map (trait ⇒ consumers): its own inverse, `$traitsUsedByClass` (consumer ⇒
  traits it directly `use`s), built from the same `$result->traitUsages` data in the same pass.

  Verified: new `ClassAnalyzer` end-to-end regression
  (`testDoesNotReportConcreteDescendantOverrideOfATraitsOwnUndeclaredTemplateMethod`) reproducing
  the real 3-level shape (trait → abstract consumer → concrete override), confirmed to fail
  without the fix — caught a real test-authoring mistake in the process: the fixture's own
  `Atomic_Button` needed to be independently referenced (`new Atomic_Button()`), or the
  whole-class-already-unused suppression masked the method-level result regardless of whether the
  fix worked, a reminder to check *why* a negative control passes, not just that it does.
  `composer check` is clean (640 PHPUnit tests, PHPStan, formatting). Traced precisely against
  the real corpus, not just Elementor: `UnusedMethod` drops Elementor 502 → 399 (−103 — short of
  the full 122-estimate since a few of the top 7 names turned out already resolved some other
  way), WooCommerce 851 → 812 (−39), WPForms 216 → 207 (−9), WordPress SEO 435 → 432 (−3),
  Contact Form 7 19 → 15 (−4), Broken Link Checker 83 → 82 (−1) — confirming this is a general
  OOP-PHP idiom (a trait implementing a template-method pattern, consumed by an abstract base
  with concrete leaf subclasses), not an Elementor-specific shape; full 28-project corpus
  re-scanned with no crash and no other category regressed anywhere.

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

- [x] **`use Foo\Bar as Alias;` import aliasing was resolved for scoped-method-call receivers but
  not for the plain class-reference tracking that feeds unused-class detection.** Real-world
  finding (Elementor): `use Some\Namespace\Trigger_Value as TriggerValueValidator; ...
  TriggerValueValidator::is_valid();` (also confirmed via `new Assets_Loader()` and `extends
  Aliased_Base` shapes) recorded the *alias* itself as the class reference, which never matched
  `Trigger_Value`'s (or `Loader`'s/`Base`'s) own declared short name, so the class looked
  permanently unused despite a real, resolvable reference right there — while the identical
  method-*call* resolution (`resolveClassNameToken`) already handled the alias correctly, since
  that path was fixed independently. Reproduced in isolation before touching real code. 5
  confirmed real instances in Elementor alone (`String_Value`/`Trigger_Value`/`Breakpoints_Value`
  via `Validator::is_valid()`, `CSS_Renderer` via `new Variables_CSS_Renderer(...)`, `Loader` via
  `new Assets_Loader()`) — Elementor has 462 aliased `use` statements total, so the real footprint
  is broader than these 5. Fixed: 3 capture sites recorded the raw alias text instead of the
  resolved class's real short name —
  - `PhpTokenParser`'s `T_STRING` `::` branch (`Foo::method()`/`Foo::class`) now resolves through
    `resolveClassNameToken` before pushing to `$classReferences`.
  - `captureClassNameAfter()` (the `new`/`instanceof` path) gained `$currentNamespace`/
    `$useImports` params and now resolves through `resolveFqcn` too.
  - `ClassRef`'s `$short` field (feeding `extends`/`implements`/trait-`use`/return-type/param-hint
    references) is now derived from the resolved `$fqcn` instead of the raw token text — a new
    shared `newClassRefFor()` helper centralizes this for all 4 construction sites, which
    incidentally also fixes the same alias blind spot for `ClassAnalyzer`'s curated-table
    (`BASE_CLASS_CONTRACT_METHODS`/interface) matching on an aliased base class, not just
    `$classReferences`.
  Verified: Elementor's unused-class count drops 96 → 87, WooCommerce's drops 167 → 142 (aliasing
  turns out to be common there too, not Elementor-specific), no regressions and no crashes across
  the full corpus.

- [x] **A class name synthesized via the canonical snake_case-to-PascalCase idiom
  (`str_replace(' ', '', ucwords(str_replace('_', ' ', $x)))`) concatenated onto a fixed
  namespace prefix, then resolved dynamically (`class_exists($full_class_name)`), was invisible
  to unused-class/-file detection.** Real-world finding (WPForms, 25 confirmed instances):
  `SmartTags::get_smart_tag_class_name($smart_tag_name)` turns each key of
  `smart_tags_list()`'s own returned config array (`'admin_email'`, `'field_id'`,
  `'url_lost_password'`, ...) into `'\WPForms\SmartTags\SmartTag\' . $class_name` — the target
  class name (`AdminEmail`, `FieldId`, `UrlLostPassword`, ...) is never spelled out as a literal
  anywhere, so all 25 `SmartTag\*` classes and their files false-positived as unused. Fixed with
  the same "no proof of causality, cross-product against every literal key the class ever
  declares" trade-off `$selfDispatchPrefixSuffixTemplates`/`$classArrayKeyLiterals` already
  accept for method-name dispatch (see the Method detection section below):
  - `PhpTokenParser::snakeToPascalTransformSourceVar()` recognizes the exact nested-call shape
    assigned to a variable; `literalConcatVarRhs()` then recognizes that variable concatenated
    onto a literal ending in `\`, recorded as `$classNameTransformTemplates[ownerClass][]`.
  - `$classArrayKeyLiterals`'s existing assignment-shape capture (`$var['literal'] = ...;`) was
    joined by a second capture, `resolveReturnedKeyedArrayLiteralKeys()`, for a keyed array
    literal returned directly (`return ['admin_email' => ..., ...];`) — WPForms' own
    `smart_tags_list()` never builds its array via repeated key assignment.
  - `ClassAnalyzer::findUnusedClasses()` and `FileAnalyzer::loadReferencedClassNames()` both
    (kept in step, same as their existing `$calledNames`/`$referencedClassNames` fallback) cross
    the two: every literal key the owner class declares anywhere, transformed and credited as
    referenced. Same-class only, no descendant fan-out — the one confirmed real-world case has
    both the transform site and the array literal on the same class.
  Verified: wpforms-lite's unused-class count drops 29 → 4, unused-file count drops 43 → 15 (all
  25 resolved paths independently confirmed against `smart_tags_list()`'s own keys), full corpus
  re-scanned with no crash and byte-identical output on every other of the 27 other
  plugins/themes.

- [x] **The item above hardcoded one exact transform idiom — a corpus-wide gap-hunt turned up
  the same general pattern (dynamic class name = a fixed namespace prefix + some string-literal
  transform of a per-class config-array domain) recurring with *different* transform
  combinations across 6 independent plugins** (wp-nested-pages, Jetpack, Botiga, WooCommerce,
  WordPress SEO, and the already-logged Blocksy trait item), strong enough corroboration to
  generalize the mechanism instead of hardcoding a second idiom alongside the first.
  Real-world shape actually fixed (wp-nested-pages, `Form\Events::setHandlers()`, 22 confirmed
  classes): `$this->actions = ['wp_ajax_npsort', 'admin_post_npBulkActions', ...];` (a flat
  literal array assigned to a property, not a keyed array — a shape
  `$classArrayKeyLiterals` didn't cover yet) `foreach`-transformed as `$class =
  str_replace('admin_post_np', '', $action); $class = ucfirst(str_replace('wp_ajax_np', '',
  $class));` (**two chained statements reassigning the same variable**, not one nested
  expression) then `$this->handlers[$key]->class = 'NestedPages\Form\Listeners\\' . $class;`
  (assigned to a property-of-an-array-element — an lvalue this parser doesn't otherwise attempt
  to track at all). Fixed:
  - `PhpTokenParser::resolveTransformChainExpr()` replaces the old single-idiom recognizer with a
    general recursive one: any nesting of `str_replace('lit', 'lit', INNER)` /
    `ucfirst(INNER)` / `ucwords(INNER)`, bottoming out at a bare variable — one already tracked
    in `$transformChainVars` (letting the chain span several statements, reassigning itself) or a
    fresh one. Returns the resolved step list in application order (outermost call last),
    replayed later via the real PHP functions (`WpSpecter\Support\StringTransformChain::apply()`)
    rather than reimplementing their semantics — shared by `ClassAnalyzer` and `FileAnalyzer` so
    both resolve identically. `splitTopLevelCommaArgs()` (a generic depth-tracked comma splitter)
    is the one new small helper this needed.
  - The concatenation-detection itself (`literalConcatVarAt()`, renamed/refactored from
    `literalConcatVarRhs()`) moved from "only inside a bare `$var = ` assignment" to wherever a
    matching string literal appears in the token stream at all (the same general hook point
    `resolvePrefixVarSuffixSelfDispatchTemplate`'s own self-dispatch capture already uses) —
    required because the real assignment target here is `$this->handlers[$key]->class`, an
    lvalue several tiers more complex than anything this parser otherwise tracks. Deliberately
    doesn't attempt to understand *what* is being assigned; the concatenation shape alone is the
    signal, the same "coarse net" stance the rest of this mechanism already takes.
  - `$classArrayKeyLiterals` gained a second capture shape (alongside `$var['literal'] = ...;`
    and a returned keyed array literal): `$this->prop = ['lit1', 'lit2', ...];` — a property
    assigned a flat literal array — feeding the exact same class-wide domain.

  Confirmed but not attempted in that same round: Jetpack's own instance
  (`'Jetpack_Tiled_Gallery_Layout_' . ucfirst($this->atts['type'])`) applies the transform
  *inline*, with no intermediate variable assignment at all, and its innermost expression is a
  property-array-key access (`$this->atts['type']`) rather than a bare variable.

  Verified: wp-nested-pages' unused-class count drops 22 → 1 and unused-file count drops 25 → 1
  (the one remaining class, `RedirectsFrontEnd`, confirmed genuinely dead — zero references
  anywhere in the corpus copy).

- [x] **Jetpack's inline-transform instance, above, needed two further extensions plus a real
  bug fix once implemented (caught by testing the reconstruction path with real, non-namespace
  data — every prior test used a namespace-shaped prefix, which happened to mask it).**
  - `resolveTransformChainExpr()` gained an `$allowOpaqueBase` flag: when set, an innermost
    expression that's neither a bare variable nor a recognized transform call still counts as a
    (zero-step) base rather than failing the whole match — letting `ucfirst( $this->atts['type']
    )` resolve to one step even though `$this->atts['type']` itself is never identified further.
    `literalConcatVarAt()` (the "wherever this literal appears" hook from the item above) now
    uses this for the expression on either side of its own `.`, so a bare tracked variable and an
    inline transform-with-opaque-base both flow through the exact same match.
  - The domain itself needed a *third* capture shape: `private static $talaveras = array(
    'rectangular', 'square', ... );` is a class-body property **declaration** with a flat array
    default, not an assignment inside a method body (the two shapes `$classArrayKeyLiterals`
    already covered). Added at the same class-body depth guard the existing `T_CONST` capture
    already uses.
  - **The actual bug:** the literal-ends-in-`\` gate obviously doesn't fit a flat, non-namespaced
    class-naming convention like `Jetpack_Tiled_Gallery_Layout_*`, so a second, alternative gate
    was added — the literal must *either* end in `\` *or* look like a capitalized, underscore-
    joined identifier ending in `_` (`preg_match('/^[A-Z][A-Za-z0-9_]*_$/', ...)`, a shape
    heuristic, not proof — Jetpack's own `ucfirst(...)` result never reaches a `new $var(...)`
    this parser could cross-check against, since the concatenation and the `new` are two separate
    statements). But `ClassAnalyzer`/`FileAnalyzer`'s own cross-product kept comparing the bare
    *transformed value* ('Columns') against `$def->name`, never the true short name
    ('Jetpack_Tiled_Gallery_Layout_Columns') — correct by accident for every *namespace*-prefixed
    case (`$def->name` is already namespace-stripped, so the transformed value alone genuinely is
    the short name there), but silently wrong for this one. Fixed by reconstructing the real
    short name (`$isNamespacePrefix ? $transformed : $prefix . $transformed`) before comparing,
    in both analyzers.

  Verified: wpforms-lite's/wp-nested-pages' own resolutions are unaffected (both use a namespace-
  ending prefix, so the reconstruction is a no-op for them — confirmed by the full existing suite
  passing unchanged). Jetpack's unused-class count drops 23 → 21 (`Jetpack_Tiled_Gallery_Layout_
  Columns`/`_Rectangle`; the other three of the five originally-flagged classes were already
  resolved via an unrelated `extends`/direct-`new` chain in a completely different file,
  `modules/widgets/gallery.php` — confirmed by isolating the two files that matter and diffing
  before/after). 6 new tests (the inline-shape resolution and its "doesn't look like a class
  name" negative case, the static-property-array-default capture, and — the ones that actually
  caught the bug — `ClassAnalyzer`/`FileAnalyzer` end-to-end regressions using real, non-
  namespace-prefixed data, each independently confirmed to fail without the fix by toggling it
  off). `composer check` is clean (620 PHPUnit tests, PHPStan, formatting); full 28-project
  corpus re-scanned with no crash and byte-identical output on every project except Jetpack, wp-
  nested-pages, and wpforms-lite (the latter two reflecting this session's earlier, separate
  fixes, not this one).

- [x] **Botiga's own instance of the same general "dynamic name via a recognized transform"
  pattern, above, needed a whole parallel mechanism rather than an extension of the class-name
  one — the target is a *function*, not a class, dispatched via `call_user_func()`, and every
  supporting piece (the domain, the transform, the scope) has a structurally different shape.**
  Real-world code (`inc/plugins/woocommerce/features/quick-view.php` +
  `woocommerce-template-functions.php`):
  ```php
  function botiga_get_default_single_product_components() {
      $components = array( 'woocommerce_template_single_title', ..., 'botiga_divider_output' );
      // ... conditionally $components[] = 'botiga_single_wishlist_button'; ...
      return apply_filters( 'botiga_default_single_product_components', $components );
  }
  function botiga_get_quick_view_summary_components( $components = array() ) {
      $components = array_map( function( $component ) {
          $suffix = str_replace( 'woocommerce_template_single_', '', $component );
          if ( $component === "woocommerce_template_single_$suffix" ) {
              return "botiga_quick_view_summary_$suffix";
          }
          return $component;
      }, $components );
      return apply_filters( 'botiga_quick_view_product_components', $components );
  }
  // template-parts/content-quick-view.php:
  foreach ( $components as $component ) {
      if ( function_exists( $component ) ) { call_user_func( $component, $product ); }
  }
  ```
  Three genuinely new mechanisms, none of them extensions of the class-name machinery above:
  - **Domain**: `resolveReturnArrayLiterals()` — a function/method returning a flat literal array
    (`return $var;` / `return apply_filters('tag', $var, ...);` where `$var` is tracked in
    `$anyArrayLiteralVars`) is captured into a new `$functionArrayReturns` map, the array-valued
    counterpart to `$functionLiteralReturns`.
  - **Transform surface**: the result here is built via double-quoted string *interpolation*
    (`"botiga_quick_view_summary_$suffix"`), not `.` concatenation —
    `interpolatedPrefixVarAt()` recognizes the fixed `"`, `T_ENCAPSED_AND_WHITESPACE`,
    `T_VARIABLE`, `"` token sequence and feeds the variable into the *same*
    `$transformChainVars` lookup `literalConcatVarAt()` already uses, so `$suffix`'s own
    `str_replace('woocommerce_template_single_', '', $component)` assignment needed no new
    recognition at all.
  - **Scope**: no owner class connects the two functions — `array_map()`'s closure runs in
    completely procedural code. Rather than trying to prove which specific function call
    provided the array (a much deeper, more speculative trace through `get_theme_mod()`'s own
    default-value position), `$functionNameTransformTemplates` is a flat, **project-wide** list
    (not owner-scoped), cross-referenced in `FunctionAnalyzer` against *every*
    `$functionArrayReturns` entry project-wide — the same "coarse net" trade-off the class-name
    mechanism makes, just widened from same-class to project scope, since a resolved candidate
    that matches no real function definition is simply inert. Gated on two independent signals to
    keep this from over-matching ordinary procedural string-building: the closure must be
    `array_map()`'s own first argument (`$functionIsArrayMapClosureStack`, computed by walking
    backward past an optional `static` modifier at the closure's own `function` keyword), and the
    literal must look like a snake_case function-name prefix (`preg_match('/^[a-z][a-z0-9_]*_$/',
    ...)`) — the lowercase, underscore-joined counterpart to the capitalized-identifier heuristic
    Jetpack's own class-name case uses.

  **Scope limitation, confirmed by tracing the real code precisely rather than estimating:** of
  the theme's 8 `botiga_quick_view_summary_*` findings, only 6
  (`title`/`rating`/`price`/`excerpt`/`add_to_cart`/`meta`) actually flow through this exact
  transform — `wishlist` is appended to the domain array *conditionally*
  (`$components[] = 'botiga_single_wishlist_button';`, not part of the initial array literal
  this pass captures, and its value wouldn't survive the `str_replace()` guard even if it were),
  and `share` is dispatched through an unrelated WordPress hook (`do_action(
  'botiga_quick_view_share' )`), never through this components list at all. Both remain
  genuinely open, unrelated gaps if ever revisited.

  Verified: 4 new parser-level regressions (the array-return capture, the interpolated-transform
  capture, and two negative cases — outside an `array_map()` closure, and a non-snake_case
  literal) plus a `FunctionAnalyzer` end-to-end regression using the real two-function, cross-file
  shape, confirmed to actually fail without the fix by toggling it off. `composer check` is clean
  (625 PHPUnit tests, PHPStan, formatting). Botiga's `UnusedFunction` count drops 18 → 12
  (exactly the 6 traced above); full 28-project corpus re-scanned with no crash and byte-
  identical output on every project except Botiga (Jetpack/wp-nested-pages/wpforms-lite reflect
  this session's earlier, separate fixes).

- [x] **WooCommerce's own instance of the class-name-transform pattern, above, needed the domain
  side widened rather than the transform side — its own registry is a 3-level-nested array
  literal, deeper than any structured capture ($classArrayKeyLiterals' targeted shapes: a
  top-level `return [...]`, a `$var['key'] = ...;` assignment, a flat array assigned to
  `$this->prop`) can reach without tracing an actual variable-assignment/conditional-mutation/
  return flow through two `apply_filters()` hops.** Real code
  (`includes/admin/class-wc-admin-reports.php`):
  ```php
  public static function get_reports() {
      $reports = array(
          'orders' => array( 'title' => ..., 'reports' => array(
              'sales_by_category' => array( 'title' => ..., 'callback' => array( __CLASS__, 'get_report' ) ),
              // ... sales_by_date, sales_by_product, coupon_usage, downloads ...
          ) ),
          // ... 'customers', 'stock' the same shape ...
      );
      if ( wc_tax_enabled() ) { $reports['taxes'] = array( 'reports' => array( /* ... */ ) ); }
      $reports = apply_filters( 'woocommerce_admin_reports', $reports );
      $reports = apply_filters( 'woocommerce_reports_charts', $reports ); // BC
      return $reports;
  }
  public static function get_report( $name ) {
      $name  = sanitize_title( str_replace( '_', '-', $name ) );
      $class = 'WC_Report_' . str_replace( '-', '_', $name );
      include_once apply_filters( 'wc_admin_reports_path', 'reports/class-wc-report-' . $name . '.php', $name, $class );
      if ( ! class_exists( $class ) ) { return; }
      ( new $class() )->output_report();
  }
  ```
  Rather than teaching the parser to trace that specific flow (a losing battle — the exact
  variable name, mutation shape, and filter-hop count are all WooCommerce-specific, and a
  different plugin's registry would use different ones), `$classArrayKeyLiterals` gained a
  fourth, general capture: every `'literal' =>` pair found *anywhere* in a class body, at *any*
  nesting depth, regardless of what variable holds it or whether it ever reaches a `return` at
  all — the same "coarse net" trade-off this domain pool already makes, just no longer requiring
  a specific structural shape to recognize. Noise from unrelated keys in the same array
  (`'title'`, `'description'`, `'hide_title'`, `'callback'`) is harmless — a spurious transformed
  candidate that matches no real class/function definition is simply never looked up. The
  transform side needed one small addition: `sanitize_title()` recognized as a transparent
  (zero-step) identity in `resolveTransformChainExpr()` — every domain value here is already a
  clean slug with nothing for it to actually change, same reasoning as `basename()`/
  `sanitize_file_name()` elsewhere in this parser, but as a *step* to skip over rather than a
  node-identity wrapper.

  The general capture now legitimately overlaps with the existing targeted ones for a fixture
  that happens to match both (confirmed by two previously-passing parser tests: one started
  reporting duplicate keys, one that asserted a "bail entirely" all-or-nothing behavior for a
  *different*, narrower mechanism — `resolveReturnedKeyedArrayLiteralKeys()`'s own structured
  capture still correctly bails when any key isn't a plain literal, but the new general harvest
  doesn't share that requirement, so it now independently finds the plain-literal keys that
  survive). Both updated to assert the new, intentional behavior rather than reverted. Duplicate
  entries from genuine double-capture are deduplicated once per file (`array_unique`) when
  `PhpTokenParser::parse()` builds its `ParseResult`, rather than left to accumulate.

  Verified: 3 new parser-level regressions (the deep-nested harvest itself, the `sanitize_title`
  identity step, plus the two updated pre-existing tests) and a `ClassAnalyzer` end-to-end
  regression using the real 3-level-nested shape, confirmed to actually fail without the fix by
  toggling it off. `composer check` is clean (628 PHPUnit tests, PHPStan, formatting).
  WooCommerce's `UnusedClass` count drops 142 → 131 — traced precisely to 11 of the registry's 12
  report classes (`WC_Report_Sales_By_Date` was already resolved via an unrelated direct `new
  WC_Report_Sales_By_Date()` elsewhere in the codebase, confirmed by isolating it in the
  before/after diff). `UnusedFile` count is unchanged (377 in both): these same files are already
  wholesale-exempted by WooCommerce's own hand-rolled `spl_autoload_register()` directory
  convention, an entirely separate, already-working mechanism — this fix was only ever needed on
  the class-usage-proof side. Full 28-project corpus re-scanned with no crash and byte-identical
  output on every project except WooCommerce (Jetpack/wp-nested-pages/wpforms-lite/Botiga reflect
  this session's earlier, separate fixes).

- [x] **WordPress SEO's own instance of the class-name-transform pattern needed a shape none of
  the previous four fixes covered: double-quoted curly-brace complex interpolation
  (`{$var}`) with both a prefix *and* a suffix, and — the simplest case yet — no transform
  applied to the variable at all.** Real code (`src/integrations/front-end-integration.php`):
  ```php
  protected $base_presenters = [ 'Title', 'Meta_Description', 'Robots' ];
  // ... 8 more sibling property arrays: indexing_directive_presenters, open_graph_presenters, ...
  private function get_needed_presenters( $page_type ) {
      $presenters = $this->get_presenters_for_page_type( $page_type ); // array_merge/array_diff
                                                                        // across several of the
                                                                        // properties above
      $callback = static function ( $presenter ) {
          return "Yoast\WP\SEO\Presenters\\{$presenter}_Presenter";
      };
      return \array_map( $callback, $presenters );
  }
  ```
  The domain side needed nothing new: these are the same plain property declarations with flat
  array defaults Jetpack's fix already taught this parser to capture (the `static` modifier
  there is optional and was already skipped correctly when absent), and since all 9 sibling
  properties live in the *same* class, `$classArrayKeyLiterals`' own flat, undifferentiated
  per-class pool means the `array_merge`/`array_diff` chain combining them is simply irrelevant
  to domain-collection purposes — every property's values land in one pool regardless. Two new
  pieces on the transform side:
  - `interpolatedPrefixCurlyVarSuffixAt()` recognizes the `"`, `T_ENCAPSED_AND_WHITESPACE`,
    `T_CURLY_OPEN`, `T_VARIABLE`, `}`, optional `T_ENCAPSED_AND_WHITESPACE`, `"` token sequence
    — curly braces are syntactically *required* here specifically because of the suffix (a bare
    `$presenter_Presenter` would tokenize as one larger variable name instead of `$presenter`
    followed by literal text). Hooked at the raw `"` token handling (not the
    `T_CONSTANT_ENCAPSED_STRING` branch literalConcatVarAt() uses — an interpolated string never
    produces that token at all, an early wrong-hook-point mistake caught by the very first test
    run before it ever reached real code).
  - Unlike every prior confirmed case, `$presenter` here is never transformed — it's the bare
    closure parameter, used exactly as the config array declared it. `$classNameTransformTemplates`
    grew a third tuple element (a literal suffix, always `''` for the existing `.`-concatenation
    shape) and its consuming cross-product in `ClassAnalyzer`/`FileAnalyzer` now falls through to
    a zero-step identity when the variable was never in `$transformChainVars` at all, rather than
    requiring proof of a prior transform — the simplest real-world shape turned out to need the
    *least* structure, not the most.

  **Two pre-existing parser tests asserted the old 2-tuple shape and were updated (not
  reverted) to include the new, always-present suffix element** — a mechanical, non-behavioral
  change confirmed by the full pre-existing suite passing unchanged otherwise.

  Confirmed by tracing the real code precisely, not estimating: of this class's up to 40
  presenter names spread across 9 sibling properties, only `Meta_Author_Presenter` was actually
  net-new — every other domain value (`Title`, `Canonical`, `Robots`, ...) turned out to already
  be referenced literally elsewhere in this large, heavily cross-referenced codebase (e.g.
  `integrations/third-party/web-stories.php`'s own direct reference to `Title_Presenter`),
  confirmed by isolating the two relevant files in a standalone scan (where every presenter name
  in the fixture resolves correctly) versus the full corpus (where only the one with no other
  reference anywhere shows a net change) — the fix is correct and complete; the corpus's own
  redundancy is simply larger than the original gap-hunt's rough estimate suggested.

  Verified: 3 new parser-level regressions (untransformed curly interpolation, a transformed-
  source variant proving the chain still resolves correctly through this shape, and a negative
  case for a non-class-shaped prefix) plus a `ClassAnalyzer` end-to-end regression using the real
  shape, confirmed to actually fail without the fix by toggling it off (the first attempt at that
  end-to-end fixture also caught a second, independent authoring mistake — a single-element
  domain array, below `parseAnyStringLiteralArray()`'s own two-element minimum — a reminder that
  a fixture "looking right" isn't the same as verifying it against the real capture rules).
  `composer check` is clean (632 PHPUnit tests, PHPStan, formatting). WordPress SEO's
  `UnusedClass` count drops 39 → 38; full 28-project corpus re-scanned with no crash and byte-
  identical output on every project except WordPress SEO (Jetpack/WooCommerce/wp-nested-
  pages/wpforms-lite/Botiga reflect this session's earlier, separate fixes).

- [x] **Elementor's widget/control/element registries needed two more pieces the previous five
  class-name-transform fixes didn't cover: a `__NAMESPACE__`-prefixed concatenation whose
  literal, one-line-later reassigns the *same* variable it's built from, and a domain sourced
  from a `foreach` loop over a local flat array rather than any class-body array literal.** Real
  code (`includes/managers/widgets.php`):
  ```php
  class Widgets_Manager {
      public function register_widgets() {
          $build_widgets_filename = [ 'common', 'icon-box', /* ~30 more */ ];
          foreach ( $build_widgets_filename as $widget_filename ) {
              $class_name = str_replace( '-', '_', $widget_filename );
              $class_name = __NAMESPACE__ . '\Widget_' . $class_name;
              $this->register( new $class_name() );
          }
      }
  }
  ```
  Two bugs had to be fixed together, both exposed by this one shape:
  - **Self-referential reassignment was silently wiping the tracked chain.** The first statement
    (`$class_name = str_replace(...)`) correctly set `$transformChainVars['$class_name']`. But
    the second statement's own RHS (`__NAMESPACE__ . '\Widget_' . $class_name`) isn't a bare
    transform-function call — `resolveTransformChainRhs()` returns `null` for it, same as for any
    genuinely unrelated reassignment — and the existing "any other RHS invalidates a previous
    tracked type" unset logic (shared with `$varTypesStack` and every other single-assignment
    tracker in this parser) fired and deleted the entry *before* `literalConcatVarAt()` ever got
    a chance to read it later in the same forward scan. New `rhsIsSelfReferentialLiteralConcat()`
    narrowly recognizes exactly this shape — RHS ends in `. $sameVar` and contains a string
    literal somewhere before that — and skips the unset only then, leaving the entry for
    `literalConcatVarAt()` to consume once `$i` reaches the literal token itself. Deliberately
    narrow (not "any RHS mentioning the same variable survives") to avoid quietly preserving a
    stale chain across an unrelated self-referencing reassignment (`$x = $x + 1;` and similar)
    elsewhere in the corpus.
  - **The domain isn't in any class-body array literal at all.** Every previous confirmation
    (WPForms, wp-nested-pages, Jetpack, WooCommerce, WordPress SEO) had the transform's ultimate
    domain sitting in a property array or a returned/keyed array literal somewhere in the same
    class — `$classArrayKeyLiterals` was built entirely from those. Elementor's own domain
    (`$build_widgets_filename`) is a bare local variable, never a class member, consumed only by
    the `foreach` that drives the transform. Rather than teach `classArrayKeyLiterals` yet another
    capture site for "a local flat array feeding a foreach," the fix reuses what this parser
    already tracks for exactly this shape: when `literalConcatVarAt()`'s own resolved chain's
    source variable (`$concatChain[0]`, e.g. `'$widget_filename'`) matches an actively-tracked
    `foreach` loop (`$foreachLoopVarNameStack`/`$foreachLoopVarValuesStack`, populated from a
    local flat literal array by `parseForeachLiteralArrayLoop` — the same machinery
    `resolveForeachConcatenatedLiteral()` already reads elsewhere in this file), that loop's own
    concrete values are pushed into `$classArrayKeyLiterals[$ownerClass]` directly at capture
    time, no separate class-body array literal needed.

  A third, smaller gap: the gate deciding whether a concatenated literal "looks like" a class-name
  prefix (`str_ends_with($prefix, '\\')` for a namespace, or `^[A-Z][A-Za-z0-9_]*_$` for a flat
  prefix like Jetpack's `Jetpack_Tiled_Gallery_Layout_`) rejected Elementor's `'\Widget_'`
  outright — it starts with a backslash (the `__NAMESPACE__ .` separator) but doesn't end with
  one, and doesn't start with a capital letter either. Widened to
  `^\\?[A-Z][A-Za-z0-9_]*_$` (an optional single leading backslash). That leading backslash then
  has to be stripped again in `ClassAnalyzer`/`FileAnalyzer`'s own short-name reconstruction
  (`$reconstructPrefix = ltrim($prefix, '\\')`) — it's the `__NAMESPACE__` separator, never part
  of the literal short class name itself, and reconstructing `'\Widget_' . 'Icon_Box'` verbatim
  would produce `'\Widget_Icon_Box'`, an extra leading backslash that never matches any real
  `$def->name`.

  Verified: a new `ClassAnalyzer` end-to-end regression using the real shape (a local
  `foreach`-driven domain, the self-referential `__NAMESPACE__` reassignment, the leading-
  backslash prefix), confirmed to genuinely fail without each of the four pieces individually
  (the reassignment-preservation check, the widened gate regex, the foreach-domain merge, and the
  prefix `ltrim`) by toggling each off in turn. `composer check` is clean (633 PHPUnit tests,
  PHPStan, formatting). Traced precisely against the real corpus rather than estimating:
  Elementor's `UnusedClass` count drops 87 → 53 (34 widget classes, matching the original
  gap-hunt's own estimate exactly); full 28-project corpus re-scanned with no crash and
  byte-identical output on every other project.

  This extends the class-name-transform family to six independent real-world confirmations
  (WPForms, wp-nested-pages, Jetpack, WooCommerce, WordPress SEO, Elementor) — Elementor's own
  `Controls_Manager::register_controls()` and `Elements_Manager` (`__NAMESPACE__ . '\Control_' .
  $control_class_id` / `'\Element_' . $element_name`) likely share the same two mechanisms but
  weren't separately traced; not attempted here since their own domains (`self::$controls`-style
  registries) weren't confirmed to already be captured the way `$build_widgets_filename` is.

## Method detection

- [x] **A namespaced `Class::method` callback assembled as `__NAMESPACE__ .
  '\Class::method'` was resolved as a global class.** Fresh corpus evidence: LiteSpeed Cache
  registers its uninstall callback in `src/core.cls.php` as `register_uninstall_hook(
  $plugin_file, __NAMESPACE__ . '\Activation::uninstall_litespeed_cache')`, while the public
  static method is declared by `LiteSpeed\Activation` in `src/activation.cls.php`. The parser
  already split the literal at `::`, but passed the remaining `\Activation` to `resolveFqcn()`;
  the leading slash therefore incorrectly made it global `Activation`, leaving the real method
  reported unused. Fixed: the bare-class-string callback path now recognizes the exact adjacent
  `T_NS_C . '\Class::method'` token shape and combines the current namespace and suffix directly
  before recording the scoped method call. An ordinary standalone `'\Global_Class::method'`
  remains explicitly global, so this does not broaden callback matching beyond PHP's known
  namespace-constant construction. The same portable pattern resolved 12 genuine LiteSpeed
  callback methods (71 → 59 unused methods), with no other corpus target changing.

  Verified with new parser-level (`testNamespaceConcatenatedClassMethodCallbackUsesCurrentNamespace`)
  and analyzer-level (`testNamespaceConcatenatedClassMethodCallbackCountsAsCall`) regressions;
  the latter retains an actually-unused sibling method. Full `composer check` is clean (577
  PHPUnit tests, PHPStan, and formatting), and before/after `--type=all` scans of all 28 supplied
  plugins/themes changed only LiteSpeed Cache as described and WP Rig's separately-fixed template
  finding below.

- [x] **A `foreach`-driven string-callable whose class segment is the loop variable, sandwiched
  between a fixed namespace prefix and a fixed `::method` suffix, was invisible to the previous
  `__NAMESPACE__ . '\Class::method'` fix — that fix only handles a single, statically-written
  literal.** Real code (`thirdparty/entry.inc.php`):
  ```php
  $third_cls = array( 'Aelia_CurrencySwitcher', 'Autoptimize', /* ~20 more */ );
  foreach ($third_cls as $cls) {
      add_action('litespeed_load_thirdparty', 'LiteSpeed\Thirdparty\\' . $cls . '::detect');
  }
  ```
  The pieces needed were already sitting side by side in this parser but never connected:
  `resolveForeachConcatenatedLiteral()` already enumerates every concrete string a `'prefix' .
  $loopVar . 'suffix'` concatenation produces over a tracked `foreach` domain — but the existing
  callback-shape gate (`$isCallbackShaped`, checked once against the *prefix* literal alone,
  before enumeration) rejects it outright: `'LiteSpeed\Thirdparty\\'` ends in a namespace
  separator, so its own trailing segment is empty and obviously not callback-shaped, even though
  every *enumerated* result plainly is. Fixed: a new, parallel enumeration check
  (`$forLoopClassMethodEnumeration`) re-splits each concrete enumerated string on its own
  rightmost `\`/`::` independently (the class segment moves with the loop variable, so the split
  point isn't fixed the way the prefix-only check assumes), validates both the class and method
  fragments still look identifier-shaped, and registers a `ScopedMethodCall` for each.

  One more piece, exposed only by this shape: `stripQuotes()` deliberately never decodes PHP
  escape sequences (see its own docblock), so the source's `'LiteSpeed\Thirdparty\\'` — written
  with a doubled trailing backslash only because a single-quoted string can't otherwise end in an
  unescaped backslash without accidentally escaping its own closing quote — is captured *raw*
  (three backslash bytes) rather than runtime-decoded (two). Concatenated with the loop value,
  that leaves a doubled backslash sitting in the middle of the reconstructed class name
  (`LiteSpeed\Thirdparty\\Aelia_CurrencySwitcher`), which would never match the real, singly-
  separated FQCN any `namespace` declaration in this parser produces. Collapsed back to one
  separator (`preg_replace('/\\\\{2,}/', '\\', ...)`) before resolving the FQCN — a general fix
  for this same doubled-backslash-before-string-end idiom wherever it recurs, not specific to
  LiteSpeed.

  Verified: a new parser-level regression
  (`testForeachConcatenatedStringCallableWithNamespacePrefixAndMethodSuffixEnumeratesEachClass`)
  and an analyzer-level regression (`testCreditsMethodCalledThroughAForeachConcatenatedStringCallable`),
  each confirmed to fail without the fix (the enumeration-split branch and the backslash-collapse
  independently, by toggling each off in turn). `composer check` is clean (635 PHPUnit tests,
  PHPStan, formatting). LiteSpeed Cache's `UnusedMethod` count drops 59 → 37 (22 third-party
  `detect()` methods, exactly matching the original gap-hunt's own estimate); full 28-project
  corpus re-scanned with no crash and identical output on every other project.

- [x] **`WP_LIST_TABLE_CONTRACT_METHODS`' curated literal list was missing one real override
  point outright, and structurally couldn't cover another — a project-defined column key can
  never be enumerated as a fixed name a literal list would need.** Real code (Contact Form 7's
  `WPCF7_Contact_Form_List_Table`, `admin/includes/class-contact-forms-list-table.php`):
  `handle_row_actions( $item, $column_name, $primary )` (`single_row_columns()`'s own per-column
  row-actions override point — the same class's `column_title()` builds the "Edit | Duplicate |
  Delete" links it renders under the primary column) was simply never in the list. Separately,
  `column_title()`/`column_author()`/`column_shortcode()`/`column_date()` are WP core's own
  `single_row_columns()` `method_exists($this, 'column_' . $column_name)` dispatch — called for
  *whatever* column keys `get_columns()` itself returns, so no fixed set of names could ever be
  exhaustive.

  Fixed: added `handle_row_actions` to the existing literal list, and a new,
  narrowly-scoped parallel mechanism — `BASE_CLASS_CONTRACT_METHOD_PREFIXES` (`['WP_List_Table' =>
  ['column_']]`), checked via the same `matchesAnyPrefix()` helper `$scopedCalledPrefixes`
  already uses elsewhere in this analyzer — for the one base class actually needing a prefix
  match instead of a name list. `isContractMethod()`'s existing inheritance-chain walk needed no
  changes at all; the prefix check just joins the literal-list and generated-stub checks already
  made at each link.

  Confirmed broadly applicable, not one plugin's own: grepping the full 28-project corpus for
  `extends WP_List_Table` turns up 20 subclasses across contact-form-7, woocommerce, wpforms-lite,
  jetpack, wordpress-seo, and both bundled Action Scheduler copies — most declaring several
  `column_*()` overrides apiece.

  Verified: extended the existing `testExcludesWpListTableContractMethods` end-to-end test with
  both new shapes (`handle_row_actions`, `column_title`), each confirmed to fail independently
  without its own half of the fix by toggling it off. `composer check` is clean (636 PHPUnit
  tests, PHPStan, formatting). Traced precisely against the real corpus: `UnusedMethod` drops
  Contact Form 7 24 → 19 (5, matching the original gap-hunt's own estimate exactly), WooCommerce
  889 → 851 (38), WPForms 222 → 216 (6), Jetpack 328 → 325 (3); full 28-project corpus re-scanned
  with no crash and identical output on every other project.

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

- [x] **`method_exists($receiver, 'prefix' . $var . 'suffix')` — a dynamic-dispatch registry
  checking whether a computed method name exists before calling it — was invisible, even though
  it needs no array-callback shape at all: `method_exists()`'s own second argument being built
  this way is a stronger, more unambiguous signal than the array-callback prefix case above (the
  function name itself says this position names a real method — no "is this even callback-
  related" question to answer).** Found in a fresh gap-hunt, confirmed recurring across the
  corpus, not one plugin's own convention:
  ```php
  // WooCommerce, WC_Settings_API::generate_settings_html()
  if ( method_exists( $this, 'generate_' . $type . '_html' ) ) { ... }
  // WooCommerce, WC_AJAX::variation_bulk_action() — 24 variation_bulk_action_* methods
  if ( method_exists( __CLASS__, "variation_bulk_action_$bulk_action" ) ) { ... }
  // WordPress SEO, WPSEO_Replace_Vars — retrieve_* methods
  if ( method_exists( $this, 'retrieve_' . $var ) ) { ... }
  // WPForms, Views/Overview/Table.php — get_column_* methods
  if ( method_exists( $this, "get_column_{$column_name}" ) ) { ... }
  ```
  Unlike the class-name-transform family elsewhere in this parser, no domain enumeration is
  needed here at all: the check itself is the whole contract, regardless of what `$type`/
  `$bulk_action`/`$var`/`$column_name` actually range over — crediting *every* method matching
  the prefix(+suffix) as reachable is correct with zero further proof needed, the same
  "co-occurrence is the signal" trade-off `ScopedMethodCallPrefix` already accepts, just from a
  stronger, unambiguous source.

  Fixed by reusing and extending the exact mechanism above rather than inventing a parallel one:
  `ScopedMethodCallPrefix` gained an optional `$suffix` field (default `''`, so the existing
  array-callback capture site needed no changes); a new `method_exists()` recognizer in
  `dispatchBareFunctionCall()` resolves the first argument to a receiver (`$this`,
  `self::class`/`static::class`, `__CLASS__`, or a bare class-name string literal — the narrow,
  evidenced shapes; a tracked variable or computed expression is left unresolved, same "don't
  guess" stance `arrayCallbackReceiverClass()` takes for its own unhandled cases) and the second
  to a prefix/suffix pair (`'prefix' . <anything>` / `'prefix' . <anything> . 'suffix'`, or the
  double-quoted interpolated equivalents `"prefix$var"` / `"prefix{$var}"` / `"prefix{$var}suffix"`
  — new, narrowly-scoped matchers operating on the argument's own already-bounded token list,
  distinct from the existing quote-scanning interpolation helpers built for the class-name-
  transform family, which assume a different surrounding statement shape). `ClassAnalyzer`'s
  `$scopedCalledPrefixes` now stores `[prefix, suffix]` pairs instead of bare prefixes, checked by
  a new `matchesAnyPrefixSuffix()` (suffix empty means prefix-only, same as before).

  Verified: 2 new parser-level regressions (the concatenation and curly-interpolation shapes,
  each confirmed to fail without the fix) and a new `ClassAnalyzer` end-to-end regression using
  WooCommerce's real `generate_*_html` shape, confirmed the credit is scoped to the resolved
  receiver only — an identically-named method on an unrelated class with no matching
  `method_exists()` call of its own still correctly reports unused. `composer check` is clean
  (643 PHPUnit tests, PHPStan, formatting). Traced precisely against the real corpus: `UnusedMethod`
  drops WooCommerce 812 → 759 (−53), WordPress SEO 432 → 392 (−40), WPForms 207 → 198 (−9),
  wp-smushit 242 → 241 (−1, a new corroborating instance the original gap-hunt didn't report);
  Wordfence's own `method_exists($this, 'scan_' . $job . '_init')` shape turned out already
  resolved before this fix — traced to a *separate* call site, `array($this, 'scan_' .
  $jobName)`, already covered by the existing array-callback `ScopedMethodCallPrefix` mechanism
  above — confirming this fix adds real, non-overlapping coverage rather than double-counting an
  already-fixed case. Full 28-project corpus re-scanned with no crash and no other category
  regressed anywhere.

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

- [x] **`WP_List_Table` was missing from `BASE_CLASS_CONTRACT_METHODS`.** Real-world finding
  (Contact Form 7): `WPCF7_Contact_Form_List_Table extends WP_List_Table` had
  `get_sortable_columns()`, `get_bulk_actions()`, `column_default()`, `column_cb()`, and others
  flagged `UnusedMethod` — all called internally by WP core's own list-table rendering/AJAX
  pipeline (`display()`/`prepare_items()`/the AJAX response handler), never by a visible name
  reference in project code. Second corpus instance: Botiga's bundled
  `ActionScheduler_Abstract_ListTable` also extends it, same shape. Fixed: added
  `WP_LIST_TABLE_CONTRACT_METHODS` (`get_columns`, `get_sortable_columns`, `get_bulk_actions`,
  `column_default`, `column_cb`, `prepare_items`, `no_items`, `get_views`, `extra_tablenav`,
  `display_rows`, `single_row`, `single_row_columns`) to the curated list, same class as the
  existing `WP_Widget`/`WP_REST_Controller` entries. Verified: Contact Form 7's unused-method
  count drops 23 → 19, no regressions across the full corpus (no crashes).

- [x] **A property (or local variable) assigned from a static-singleton-factory call —
  `$this->prop = ClassName::cls()` / `::instance()` / `::getInstance()` / `::get_instance()` —
  wasn't type-tracked**, unlike `new ClassName()`. Real-world finding (LiteSpeed Cache): every
  module extends a shared `Base` class whose `cls()` factory method uses late static binding
  (`get_called_class()`), so `Cloud::cls()` always returns a `Cloud` instance regardless of
  `cls()`'s own (undeclared) return type — `PendingReturnTypedCall`'s existing return-type
  resolution doesn't apply since there's no declared type to resolve against. 8 confirmed
  instances in LiteSpeed Cache alone (`cli/online.cls.php:34`, `database.cls.php:42`,
  `object-cache-wp.cls.php:148`, `debug.cls.php:34`, `presets.cls.php:34`, `image.cls.php:36`,
  `avatar.cls.php:58`), plus one in Jetpack (`class.json-api-endpoints.php:436`,
  `WPCOM_JSON_API_Links::getInstance()`). Fixed: `assignedStaticFactoryClassName()`
  (`PhpTokenParser`) recognizes `ClassName::factoryMethod()` where `factoryMethod` is one of a
  curated `STATIC_FACTORY_METHOD_NAMES` list (`cls`, `instance`, `getInstance`, `get_instance` —
  same spirit as `TEMPLATE_FUNCS`/`BASE_CLASS_CONTRACT_METHODS`, a real reusable convention, not
  plugin-specific) and resolves it to the receiver class name directly; wired in as a fallback
  alongside `assignedNewClassName()` for both property assignment and local-variable tracking.
  Verified: LiteSpeed Cache's unused-method count drops 54 → 49, Jetpack's drops 321 → 314, no
  regressions across the full corpus (no crashes).

- [x] **A dynamic array-callback method name built from `'prefix_' . <expr>` inside a `foreach`
  over a tracked literal-string array wasn't resolved**, unlike the existing bounded-`for`-loop
  enumeration — and even a bare loop variable in this shape had its receiver-class detection
  break the moment the concatenated expression was a function call rather than a single token.
  Real-world finding (WooCommerce, `includes/class-wc-breadcrumb.php:79-81`):
  ```php
  foreach ( $conditionals as $conditional ) {   // literal array: 'is_home', 'is_404', ...
      if ( call_user_func( $conditional ) ) {
          call_user_func( array( $this, 'add_crumbs_' . substr( $conditional, 3 ) ) );
          break;
      }
  }
  ```
  13 confirmed unused-method false positives (`add_crumbs_home`, `add_crumbs_404`,
  `add_crumbs_attachment`, ...). Two independent gaps here, both fixed:
  - No mechanism tracked a `foreach`-over-literal-array loop variable's enumerated values at all
    for callback-name construction (only a bounded `for` loop's integer range fed
    `resolveForLoopConcatenatedLiteral`) — fixed with a string-valued sibling stack
    (`$foreachLoopVarNameStack`/`$foreachLoopVarValuesStack`, populated by the new
    `parseForeachLiteralArrayLoop()`) and a matching `resolveForeachConcatenatedLiteral()`, which
    also recognizes one additional shape the `for`-loop case has no evidence for: `.
    substr($loopVar, N)` with N a fixed literal offset — the one transform with confirmed
    real-world use. `$anyArrayLiteralVars` (via new `parseAnyStringLiteralArray()`) tracks a
    literal array regardless of whether any element contains a `/`, since the existing
    `$arrayLiteralVars`/`parseStringLiteralArray()` deliberately gates on that as a
    path-list-vs-arbitrary-array signal — kept as a separate pool rather than relaxing that gate.
  - Independently, `isLastArrayElementAt()` — which confirms a concatenated literal is genuinely
    the callback array's last element before trusting its receiver — only knew how to skip a
    single-token concatenated segment (a bare `$var` or literal) per `.`, so `.
    substr($conditional, 3)` (a multi-token call expression) made it stop one token early at
    `substr`'s own opening paren and misread it as "not the array's last element," silently
    falling back to the unscoped `$functionCalls` pool even after the enumeration above correctly
    produced the real method names. Fixed by skipping to the matching close paren for a
    call-shaped segment instead of just one token.
  Verified: WooCommerce's unused-method count drops (contributing to the combined 945 → 914 drop
  alongside the alias fix above), no regressions and no crashes across the full corpus.

- [x] **A same-class self-dispatch method — `call_user_func([$this, "{$param}_suffix"])` inside a
  dispatcher, called from multiple scattered call sites each with a literal argument — left every
  real target method invisible.** Real-world finding (Sydney theme,
  `inc/customizer/style-book/class-sydney-style-book.php`): `get_section($section) { if (
  method_exists( $this, "{$section}_section" ) ) { call_user_func( array( $this,
  "{$section}_section" ) ); } }`, called 8 times with literal strings — `$this->get_section(
  'colors' )`, `('buttons')`, `('typography')`, `('media')`, `('forms')`, `('lists')`,
  `('tables')`, `('quotes')`. All 8 real target methods (`colors_section`, `buttons_section`,
  etc.) were flagged `UnusedMethod`. Different collection shape from the loop-bounded
  mechanisms above — the literal values come from **discrete call sites scattered through the
  file** (or project), not a bounded loop's own known range. Fixed, two cooperating pieces:
  - `collectInterpolatedStringSegments()` extracted as the shared segment-walker
    `resolveInterpolatedLoopSuffixPath` already had inline, now reused by a new
    `isThisArrayCallbackReceiverAt()` (confirms an interpolated string is the second element of
    a `[$this, "..."]`/`array($this, "...")` array-callback) and `extractTrailingVarSuffix()`
    (the literal text trailing the last embedded variable, discarding everything before it).
    Together they recognize a method's own self-dispatch shape and record its suffix (`"_section"`
    here) keyed by `Class::method`, merged across files into `$selfDispatchSuffixes` the same way
    `$functionParamSuffixReturns` already is.
  - `PendingSelfDispatchCall` (new, mirrors `PendingDirectoryLoaderCall`'s shape) records every
    `$this->method('literal')` scoped call's literal first argument against its receiver/method,
    resolved in `ClassAnalyzer::findUnusedMethods()` once every file's parse is merged: each
    collected literal gets the dispatcher's own suffix appended and credited on `$scopedCalled`
    directly, so every existing exemption/matching mechanism applies to it for free.
  Verified: Sydney's unused-method count drops 26 → 18 (all 8 targeted findings gone), no
  regressions and no crashes across the full corpus.

- [x] **A `array($this, 'prefix' . $param . 'suffix')`-shaped self-dispatch, whose column/target
  domain comes from literal array-key assignments scattered through a *different* method (and
  often a *different* class in the inheritance chain) than the dispatcher itself — a genuinely
  different collection shape from the call-site-literal-argument case just above.** Real-world
  finding (WooCommerce): `render_columns( $column, $post_id ) { if ( is_callable( array( $this,
  'render_' . $column . '_column' ) ) ) { $this->{"render_{$column}_column"}(); } }`
  (`includes/admin/list-tables/abstract-class-wc-admin-list-table.php:264`, declared once on the
  abstract `WC_Admin_List_Table` base) and the equivalent `render_column()`/`call_user_func(...)`
  form (`src/Internal/Admin/Orders/ListTable.php:175`). The column domain isn't a call-site
  literal or a bounded loop at all — it's established by `$show_columns['thumb'] = ...;`-style
  assignments to a plain local variable inside `define_columns()`, a *different* method, declared
  on a *different* (concrete subclass) class than the dispatcher. 25 confirmed instances across 2
  files/dispatchers. Fixed, three cooperating pieces:
  - `resolvePrefixVarSuffixSelfDispatchTemplate()` — the plain-concatenation counterpart to the
    Sydney fix's interpolated-string shape, sharing its backward `$this`-receiver check via a new
    `isPrecededByThisArrayCallbackComma()` — recognizes `array($this, 'prefix' . $param .
    'suffix')` and records `[prefix, suffix]` against the dispatcher's own `Class::method` key
    (`$selfDispatchPrefixSuffixTemplates`, merged across files).
  - `arrayKeyLiteralAssignment()` recognizes `$anyVar['literal'] = ...;` for *any* variable name
    (not scoped to one method or property) and accumulates every literal key seen anywhere in a
    class's own methods into `$classArrayKeyLiterals[ownerClass]` — deliberately class-wide
    rather than call-site-driven, since there's no call site here at all (WP core's own list-table
    rendering loop invokes the dispatcher at runtime with a real column ID, invisible to this
    codebase).
  - Resolution in `ClassAnalyzer::findUnusedMethods()` cross-products each dispatcher template
    against its owner class's key pool — but the column keys and the dispatcher template turned
    out to live on *different* classes in the real case (base class dispatcher, subclass keys),
    so the cross-product also walks every known descendant via the already-built `$descendantsOf`
    map and credits `{prefix}{key}{suffix}` on whichever descendant the keys actually came from,
    not just the dispatcher's own declaring class.
  Verified: WooCommerce's unused-method count drops 914 → 890 (24 of the 25 confirmed instances
  gone — the 25th, `render_usage_limit_column`, turned out to be genuine dead code on closer
  inspection: the real column key is `usage`, not `usage_limit`, an orphaned method from an
  apparent rename, not a tool gap), no regressions and no crashes across the full corpus.

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

- [x] **WP 6.5+ script-translation files (`.l10n.php`) were never recognized as a convention-based
  exemption, the same way page templates and block patterns already are.** Real-world case
  (Advanced Custom Fields): `lang/acf-es_MX.l10n.php` and 51 sibling locale files — each a plain
  `return ['domain'=>..., 'messages'=>[...]];` array a build tool (`wp i18n make-json`/
  `@wordpress/scripts`) generates from a script's own `.po` file — **100% of ACF's 78
  `UnusedFile` findings**. WP core's own `load_script_translations()` (`wp-includes/l10n.php`)
  locates these purely by filename convention next to a registered script handle; nothing in
  project code ever `include()`s/`require()`s one directly.

  Fixed: `FileAnalyzer::isCandidate()` exempts any file whose path ends in `.l10n.php`,
  unconditionally — no directory scope needed (unlike the block-pattern header check just below
  it, which is deliberately restricted to `patterns/` since a bare header field isn't distinctive
  enough on its own), since the double extension is WP's own reserved convention: no legitimate
  PHP source file is ever named this way.

  Verified: new end-to-end regression (`testScriptTranslationL10nPhpFileIsExempt`), confirmed to
  fail without the fix. `composer check` is clean (637 PHPUnit tests, PHPStan, formatting). ACF's
  `UnusedFile` count drops 78 → 26 (all 52 `.l10n.php` files); full 28-project corpus re-scanned
  with no crash and identical output on every other project (no other project in the corpus ships
  these files, though the convention itself is WP-core-wide, not ACF-specific).

- [x] **webpack/`@wordpress/scripts` build-dependency manifests (`{entry}.asset.php`) — the same
  reserved-double-extension shape as `.l10n.php` above, just not yet applied.** A fresh gap-hunt
  turned up `woocommerce/assets/client/blocks/*.asset.php`: 237 files, each a plain `return
  ['dependencies' => [...], 'version' => ...];` array webpack generates per compiled bundle for
  `wp_register_script()`-adjacent tooling to read — loaded by a dynamic path built from each
  compiled entry's own name, never a literal `include()`/`require()`. Corpus-wide grep for
  `*.asset.php` found this convention far more widespread than the one WooCommerce directory
  that surfaced it: **885 files across 13 of the 28 projects** — Jetpack alone ships 466,
  Elementor 56, plus smaller counts in broken-link-checker, contact-form-7, wp-smushit, astra,
  generatepress, hestia, kadence, neve, sage, sydney.

  Fixed identically to `.l10n.php`: `FileAnalyzer::isCandidate()` exempts any file whose path
  ends in `.asset.php`, unconditionally — no other legitimate PHP source is ever named this way.

  Verified: new end-to-end regression (`testWebpackAssetPhpBuildManifestIsExempt`), confirmed to
  fail without the fix. `composer check` is clean (639 PHPUnit tests, PHPStan, formatting).
  Verified together with the `locate_template()` fix above in the same corpus pass (both changed
  file candidacy, so isolating each precisely wasn't meaningful — the combined before/after is
  the honest number): `UnusedFile` drops WooCommerce 377 → 85 (−292), Jetpack 131 → 66 (−65),
  Elementor 1016 → 962 (−54), GeneratePress 4 → 0 (fully clear), Kadence 21 → 18 (−3); no crash
  anywhere, no other finding category regressed on any of the 28 projects.

- [x] **Third-party plugin theme-override directories (bbPress's `bbpress/`, WooCommerce's
  `woocommerce/`) weren't recognized as a convention-based exemption or handed off to
  TemplateAnalyzer — they fell through to the default "is this used" check with no reachability
  signal either analyzer could ever find, since the theme itself never calls into them at all.**
  Found in a fresh gap-hunt: bbPress's own `bbp_locate_template()` scans the *active theme's* own
  `bbpress/` directory by filename convention, purely on the plugin's own initiative — real-world
  case (Kadence): all 13 files under `bbpress/` (100% of the theme's own `UnusedFile` findings).
  WooCommerce's `wc_locate_template()` scans a theme's `woocommerce/` directory the identical
  way — present (though not yet triggering the bug, since their files already resolve some other
  way) in Blocksy, Neve, and Sydney.

  Fixed with a new, deliberately separate constant, `THIRD_PARTY_TEMPLATE_OVERRIDE_DIRS` —
  *not* folded into the existing `TEMPLATE_DIRS` list just above, since that list's own files are
  handed off to `TemplateAnalyzer` (which knows how to check `get_template_part()`/
  `wc_get_template_part()`-style reachability), while these directories have no reachability
  check either analyzer could ever run — the whole point of the convention is that the *theme*
  never calls into them, so they're exempted outright instead, the same way Page Templates and
  Block Patterns already are just above in `isCandidate()`.

  Verified: new end-to-end regression (`testThirdPartyPluginTemplateOverrideDirectoryIsExempt`),
  confirmed to fail without the fix. `composer check` is clean (650 PHPUnit tests, PHPStan,
  formatting). Kadence's `UnusedFile` count drops 18 → 5 (all 13 `bbpress/` files); full
  28-project corpus re-scanned with no crash and identical output on every other project (no
  other project in the corpus currently has an unresolved `woocommerce/`-directory finding, but
  the convention itself is WooCommerce-ecosystem-wide, not one theme's own).

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
  tests) and phpstan both green. **Scope limitation at the time, closed by the next entry
  below**: Astra's own real-world case uses double-quoted *string interpolation*
  (`"...{$icons_dir}/icons-v6-{$i}.php"`), a fundamentally different token shape than the
  concatenation this fix recognizes, and wasn't resolved yet.

- [x] **Closing the scope limitation above: a `.php`-suffixed double-quoted *interpolated*
  string (as opposed to concatenation) split across a bounded loop variable still wasn't
  resolved.** Astra's real shape: `$icons_dir = ASTRA_THEME_DIR . 'assets/svg/logo-svg-icons';
  for ($i = 0; $i < 4; $i++) { $file = "{$icons_dir}/icons-v6-{$i}.php"; }` — `$icons_dir` is
  itself unresolvable (`ASTRA_THEME_DIR` isn't a tracked literal), but that turned out not to
  matter: `FileAnalyzer` already matches a `phpPathStrings` entry by **basename** alone (see
  `buildReferencedIndex()`), so the unresolvable directory prefix can simply be discarded rather
  than resolved — only the literal text immediately adjacent to the loop variable on each side
  ("/icons-v6-" and ".php") needs to survive. New `PhpTokenParser::
  resolveInterpolatedLoopSuffixPath()` walks an interpolated string's own token sequence (`"`,
  then alternating `T_ENCAPSED_AND_WHITESPACE` literal runs and `$var`/`{$var}` variable
  segments until the closing `"`) and, when the *last* variable segment matches a currently-
  tracked bounded for-loop variable (reusing the same `$forLoopVarNameStack`/
  `$forLoopVarValuesStack` the concatenation fix above already built) with only a single
  trailing `.php`-suffixed literal segment after it, enumerates one basename per loop value —
  keeping the literal segment directly before the matched variable too, so "icons-v6-" isn't
  lost along with the genuinely-unresolvable `$icons_dir` before it. Any more complex shape
  (`{$obj->prop}`, `${expr}`, two different loop variables, a non-'.php' suffix, ...) bails
  entirely, same "don't guess" stance as its concatenation-based sibling.

  A real bug caught mid-implementation, not just an edge case handled up front: the first cut
  discarded the adjacent "icons-v6-" literal along with the unresolvable `$icons_dir` before it
  (keeping only the *trailing* literal), enumerating `0.php`/`1.php`/... instead of
  `icons-v6-0.php`/`icons-v6-1.php`/... — caught by testing against the real file directly
  (`../wp-tests/themes/astra`) before declaring it fixed, not by the unit tests alone (their
  fixtures happened not to distinguish the two).

  Verified: 4 new `PhpTokenParserTest` cases (the real Astra shape, the simple `$var` — no
  braces — interpolation syntax, a non-`.php` suffix correctly left alone, and a
  `{$this->prop}`-shaped bail) and one new `FileAnalyzerTest` end-to-end case (including a 5th
  icon file the loop never reaches, confirming it's still correctly flagged — the "sharper, not
  just quieter" bar every fix in this project holds itself to); real Astra corpus —
  `icons-v6-0..3.php` no longer flagged, unused-file count dropped from 5 to 1; full 19-target
  corpus sanity pass (11 plugins + 8 themes) shows every other finding count unchanged and no
  crashes; full suite (534 tests) and phpstan both green.

- [x] **A plain array of 3+ string literals had its 2nd element silently vanish, misparsed as a
  `[$receiver, 'method']` array-callback pair.** `arrayCallbackReceiverClass()` (the mechanism
  behind `[$this, 'method']`/`['Foo', 'method']` recognition) only ever checked *backward* from
  a candidate "method name" string — a comma, then a plausible receiver, then the array's own
  opening bracket — never whether that candidate was also the array's *last* element. Every one
  of those backward checks passes just as well for the 2nd entry of `array('One', 'Two',
  'Three')` as it would for a real 2-element callback, so `'Two'` got consumed into a fabricated
  `ScopedMethodCall('One', 'Two')` and disappeared from both `$functionCalls` and
  `$classReferences` entirely — invisible to every analyzer. Found by a fresh gap-hunting pass:
  Sydney theme's `inc/abilities/class-sydney-abilities-registry.php` builds
  `apply_filters('sydney_abilities_groups', array('Sydney_Abilities_Colors',
  'Sydney_Abilities_Typography', 'Sydney_Abilities_Buttons', ...17 total))`, each class
  dynamically instantiated via `new $group()` in the consuming loop — `Sydney_Abilities_
  Typography` (array position 1) is genuinely used but false-positived as `UnusedClass` purely
  because of its position in the list.

  This is a **new-code-detection blind spot**, not a plugin-specific quirk — any project with a
  plain list of 3+ string literals anywhere (class names, hook names, file paths, option keys)
  has its 2nd element silently invisible. Fixed generally: new `isLastArrayElementAt()` checks
  that the candidate method-name string is followed (past an optional trailing `. <segment>`
  concatenation chain — `array($this, 'footer_html_' . $i)` is still exactly 2 elements — and
  one optional trailing comma) by the array's own closing `]`/`)`; `arrayCallbackReceiverClass()`
  now bails immediately if this fails, before any of its three receiver-shape branches run. A
  real callback pair is never longer than 2 elements, so this closes the gap with zero cost to
  the shapes it already recognized correctly.

  Verified: 4 new `PhpTokenParserTest` cases (the exact 3-element regression, a 4-element variant
  confirming the pattern isn't specific to exactly 3, a trailing-comma-on-a-real-2-element-
  callback case proving the fix doesn't over-correct, plus the pre-existing concatenation-shape
  test — `array($this, 'footer_html_' . $i)` inside a bounded loop — updated nothing since it
  already passed once the concatenation-chain skip was added) and one new `ClassAnalyzerTest`
  end-to-end case modeled directly on the real Sydney shape; real Sydney corpus —
  `Sydney_Abilities_Typography` no longer flagged; full 20-target corpus sanity pass (11 plugins
  + 9 themes, Sage newly added to the corpus) shows no crashes and several other real,
  directionally-consistent changes elsewhere — WooCommerce alone: unused-function count dropped
  19 (literals no longer swallowed) while unused-method count *rose* 19 (methods a fabricated
  scoped call was wrongly crediting as "used" now correctly evaluated on their own merits;
  spot-checked `add_payment_token` — zero real call sites anywhere in the plugin, a genuine,
  previously-hidden true positive, not a regression); full suite (538 tests) and phpstan both
  green.

- [x] **The exactly-2-element case of the same array-callback misparse — `isLastArrayElementAt()`
  can't rule it out, since a real `['Foo', 'method']` pair is also exactly 2 elements.** Found
  while corpus-checking the "ignoring effort" shipmonk/dead-code-detector question: WooCommerce's
  `includes/class-wc-brands.php` builds `$controllers = array('WC_REST_Product_Brands_V2_Controller',
  'WC_REST_Product_Brands_Controller'); foreach ($controllers as $controller) { (new
  $controller())->register_routes(); }` — a plain 2-item list of class names, not a callback at
  all, but shape-identical to a genuine bare-string callback pair. `arrayCallbackReceiverClass()`'s
  `['Foo', 'method']` branch (unlike its `$this`/`Foo::class` siblings, which have unambiguous
  receiver syntax) accepted *any* 2-element array of plain string literals as a callback pair —
  the 2nd literal (`WC_REST_Product_Brands_Controller`) was fabricated into
  `ScopedMethodCall('WC_REST_Product_Brands_V2_Controller', 'WC_REST_Product_Brands_Controller')`
  and vanished from `$functionCalls`/`$classReferences`, so it stayed `UnusedClass` even though
  `new $controller()` genuinely reaches it.

  No local token shape can disambiguate a real bare-string callback pair from an arbitrary
  2-string list — both are "two identifier-shaped string literals, last one preceded by a comma
  preceded by the array's own open bracket." The one signal available without full dataflow:
  every genuine bare-string callback pair found in the wild (Akismet: `add_action('init',
  array('Akismet', 'init'))`, repeated ~15x across the plugin) sits directly inside a function
  call's own argument list; a bare `$var = array(...)`/`$var = [...]` assignment never is. Fixed:
  new `tokenBeforeArrayLiteral()` walks back past the array's own opening bracket (past the
  `array` keyword for long syntax) to the token immediately before the whole literal; the
  `['Foo', 'method']` branch now bails if that token is `=`. Scoped narrowly to the ambiguous
  bare-string branch only — the `$this`/`Foo::class` branches have unambiguous syntax and are
  untouched.

  Verified: 3 new `PhpTokenParserTest` cases (the exact WooCommerce regression, a short-array-
  syntax variant, and a same-shape-but-nested-in-a-call-argument case proving the fix doesn't
  over-correct — Akismet's own shape, still resolves to `ScopedMethodCall('Akismet', 'init')`);
  full 28-target corpus sanity pass (13 plugins + 15 themes) shows zero crashes and only
  reductions, no new findings anywhere — WooCommerce: `WC_REST_Product_Brands_Controller` no
  longer flagged (168→167 unused classes, 948→945 unused methods, its own methods no longer
  double-counted per `--no-suppress-unused-class-methods` default); Jetpack:
  `hook_wpmu_dev_domain_mapping` (`3rd-party/class-domain-mapping.php`) no longer flagged
  (322→321 unused methods) — a second, independent real instance of the same bug, not spotted by
  manual inspection, only by the full corpus diff; every other project's finding counts unchanged.
  Full suite (557 tests) and phpstan both green.

- [x] **WordPress's "object cache drop-in" function contract was invisible.** A plugin/theme
  can ship `wp-content/object-cache.php` (WP core loads it *instead of* its own
  `wp-includes/cache.php` whenever present) — the drop-in must declare a fixed set of bare
  `wp_cache_*` function names itself, called by WP core directly at bootstrap from a file that
  lives entirely outside the scanned project's own tree (WP copies the plugin's own template
  file there at runtime). No call site can ever exist in project code, no matter how the backend
  is actually implemented — the same class of gap `BASE_CLASS_CONTRACT_METHODS` already exists
  for class methods, just for bare functions instead. Found by a fresh gap-hunting pass:
  LiteSpeed Cache's `src/object.lib.php` (the exact template it copies to
  `wp-content/object-cache.php`) declares all ~19 of these — `wp_cache_init`, `wp_cache_get`,
  `wp_cache_set`, `wp_cache_delete`, `wp_cache_flush`, etc. — every one of them flagged
  `UnusedFunction`.

  Fixed with a new `FunctionAnalyzer::WP_OBJECT_CACHE_DROPIN_FUNCS` curated list (the exact
  function set, taken directly from LiteSpeed's real drop-in template — which must mirror WP
  core's own `wp-includes/cache.php` function set exactly for the drop-in swap to work at all,
  so this isn't LiteSpeed's own naming, it's WP core's), checked by `isExcluded()` the same way
  the magic-`__`-prefix exclusion already is. A name-only match (no guard, no file-path check)
  is safe here specifically because these are WP core's own reserved global function names: WP
  core simply never loads its own `wp-includes/cache.php` once any of these is redeclared
  anywhere, making an unrelated same-named function an immediate fatal redeclaration error
  rather than a plausible false-match risk — a materially stronger signal than an ordinary
  name-prefix heuristic would be.

  Deliberately scoped to only this one drop-in: WP's other drop-ins (`advanced-cache.php`,
  `db.php`, `sunrise.php`, `maintenance.php`, ...) are whole-file replacements WP core executes
  wholesale, not a fixed callable-by-name API surface the way `object-cache.php` is — they don't
  share this exact shape, and extending the fix to them would be speculation with no real-world
  evidence behind it.

  Verified: new `FunctionAnalyzerTest` case (5 of the real drop-in function names, matching
  LiteSpeed's actual signatures); real LiteSpeed Cache corpus — all 19 `wp_cache_*` functions no
  longer flagged, plugin's unused-function count dropped from 19 to 0; full 20-target corpus
  sanity pass (11 plugins + 9 themes, WP Rig newly added to the corpus as a Hybrid-mode theme)
  shows every other finding count unchanged and no crashes; full suite (539 tests) and phpstan
  both green.

- [x] **`ClassAnalyzer`'s whole-class exemption (`FULLY_EXEMPT_BASE_CLASSES` — e.g. Roots Acorn's
  `View\Composer`) had no equivalent at the file level.** `--type=classes` already correctly
  showed an exempt-base-class subclass as used (`isFullyExemptClass()`), but `FileAnalyzer` had
  zero cross-talk with that decision — a file containing nothing but one of these classes was
  still flagged `UnusedFile`. Found by a fresh gap-hunting pass, first time the newly-added Sage
  theme (a real Roots Acorn theme) got any attention: `app/View/Composers/{App,Comments,
  Post}.php` each declare a single `class X extends Composer`, discovered entirely by Acorn's
  own filesystem convention and never referenced by name anywhere in the theme — `--type=classes`
  showed "✓ All clear" while `--type=files` still flagged all three.

  Fixed by sharing the exact same decision instead of duplicating or approximating it:
  `ClassAnalyzer::isFullyExemptClass()` is now `public static` (it never used instance state to
  begin with) so `FileAnalyzer` can call it directly. New `FileAnalyzer::loadFullyExemptFiles()`
  builds a file => bool map: a file is exempt only when *every* class it declares is fully
  exempt — a file mixing an exempt class with something else (another class, a helper function)
  isn't safe to exempt wholesale, since whatever else lives there might still need a real usage
  reference. Any current or future `FULLY_EXEMPT_BASE_CLASSES` entry gets this same file-level
  treatment automatically, for free — not something scoped to Acorn/Sage specifically.

  Verified: 2 new `FileAnalyzerTest` cases (the real Sage shape, and a mixed-file case confirming
  the fix doesn't over-correct) plus the existing `ClassAnalyzerTest` coverage for
  `isFullyExemptClass()` itself, unchanged; real Sage corpus — all three `View\Composers\*.php`
  files no longer flagged, theme's unused-file count dropped from 4 to 1 (the one remaining find,
  `app/filters.php`, is a separate, unrelated gap — `locate_template()` isn't yet a recognized
  template-loading call — left undone since the real shape there, an array iterated via a Laravel
  collection's `->each()` closure rather than a plain `foreach`, is a harder pattern to generalize
  than this fix and has only this one data point so far); full 20-target corpus sanity pass shows
  every other finding count unchanged and no crashes; full suite (541 tests) and phpstan both
  green.

- [x] **Action Scheduler's own scheduling functions weren't recognized as firing a hook, the
  exact same shape `wp_schedule_event`/`wp_schedule_single_event` already were.** Action
  Scheduler is a widely-bundled standalone library — WooCommerce, wpforms-lite, and many other
  plugins each ship their own copy — providing `as_enqueue_async_action()`/
  `as_schedule_single_action()`/`as_schedule_recurring_action()`/`as_schedule_cron_action()`,
  each scheduling a hook name that fires later via the library's own cron-like runner rather
  than a visible `do_action()` in project code. `CRON_SCHEDULE_FUNCS` (the function-name =>
  hook-argument-position map already driving `wp_schedule_event`'s own recognition) simply
  didn't list any of these four. Found by a fresh gap-hunting pass: WooCommerce's
  `includes/class-woocommerce.php` calls `as_schedule_recurring_action($tomorrow_3am,
  DAY_IN_SECONDS, 'wc_admin_daily_wrapper', ...)` and `as_schedule_single_action(time() + 10,
  'generate_category_lookup_table_wrapper', ...)` — both real, `add_action()`-registered hooks,
  flagged `UnmatchedHook` purely because the scheduling call itself was invisible; confirmed
  independently in wpforms-lite's `src/Tasks/Task.php` calling the same two functions.

  Fixed by simply adding the four function names to the existing map — no new mechanism needed,
  `parseCronScheduleHook()` was already fully generic over argument position. Argument positions
  taken from, and confirmed directly against, Action Scheduler's own real bundled source
  (`packages/action-scheduler/functions.php`) rather than assumed from documentation alone: hook
  is argument 0 for `as_enqueue_async_action`, 1 for `as_schedule_single_action`, and 2 for both
  `as_schedule_recurring_action` and `as_schedule_cron_action`.

  Verified: 4 new `HookAnalyzerTest` cases (one per function, two modeled directly on the real
  WooCommerce call sites above); real WooCommerce corpus — both named hooks no longer flagged,
  plugin's unmatched-hook count dropped from 63 to 51; full 20-target corpus sanity pass shows
  every other finding count unchanged and no crashes; full suite (545 tests) and phpstan both
  green.

- [x] **PHPUnit/WP-test-suite test case classes weren't recognized as a reflection-discovered
  base class, the same category `FULLY_EXEMPT_BASE_CLASSES` already covers for Roots Acorn's
  `View\Composer`.** A `TestCase`/`WP_UnitTestCase` subclass's `test*` methods (plus
  `setUp`/`tearDown`/...) are discovered and called by the test runner via reflection over the
  class itself — never a literal call anywhere in project code — so every test class in a
  project's own bundled test suite looked like dead code. Found by a fresh gap-hunting pass,
  confirmed independently in **two** real-world projects: WP Rig theme's own test suite
  (`tests/phpunit/...`, chaining through an intermediate project-own base class,
  `Component_Test extends Unit_Test_Case extends TestCase`, before finally reaching
  `PHPUnit\Framework\TestCase`) and wp-smushit's bundled `wpmudev-analytics` test
  (`class Test_WPMUDEV_Analytics extends WP_UnitTestCase`).

  Fixed by adding two entries to the existing `FULLY_EXEMPT_BASE_CLASSES` list — no new mechanism
  needed, the existing multi-level extends-chain walk (built for cases like
  `Walker_Nav_Menu -> Walker`) already handles WP Rig's intermediate-base-class shape for free:
  `'TestCase' => 'PHPUnit\Framework\TestCase'` and `'WP_UnitTestCase' => 'WP_UnitTestCase'`
  (itself with no namespace to import — a WP-core-test-suite global class — so its own entry
  relies on the existing "`$ref->fqcn === $ref->short`" leniency for an un-namespaced extends,
  already built for exactly this kind of case). As a side effect of the earlier
  `FULLY_EXEMPT_BASE_CLASSES`/`FileAnalyzer` cross-talk fix (see above), this same exemption now
  also correctly applies at the file level automatically, with no extra work.

  Verified: 2 new `ClassAnalyzerTest` cases (the `WP_UnitTestCase` shape with no import, and the
  multi-level `TestCase` chain); real corpus — wp-smushit's `Test_WPMUDEV_Analytics` no longer
  flagged (unused-class count 10 → 9), WP Rig's entire test suite no longer flagged (unused-class
  count 7 → 0, unused-method count 9 → 7); full 20-target corpus sanity pass shows every other
  finding count unchanged and no crashes; full suite (547 tests) and phpstan both green.

- [x] **A property assigned *externally*, against the exact declared type of a typed parameter
  or property, was invisible to `$propertyAssignedClasses` entirely — the exact inverse of the
  already-tracked `$this->prop = new X()`/`$this->prop = $typedParam` shapes, where `$this` is
  always the *receiver* of the assignment, never the *value* being stored into someone else's
  property.** Found by a fresh gap-hunting pass: Wordfence's bundled `lib/Diff.php` —
  `function render(Diff_Renderer_Abstract $renderer) { $renderer->diff = $this; return
  $renderer->render(); }` — populates a *different* object's property with the current instance.
  The real read site, `$this->diff->getA()`/`getB()`/`getGroupedOpcodes()`, lives inside
  `Diff_Renderer_Html_Array extends Diff_Renderer_Abstract` — a second, independent gap once the
  assignment itself became visible: the property is assigned against the exact *declared* type
  (`Diff_Renderer_Abstract`), but read from a *concrete subclass* of it, the mirror direction of
  the Yoast SEO base-class-property case (there, assignment happened in a descendant and the read
  in the base; here, assignment happens at the base type and the read is in a descendant).

  Fixed in two matching parts:
  1. `PhpTokenParser` now recognizes `$typedVar->prop = $this;` (the general, non-`$this` variable
     branch already tracking `$var = new ClassName()`/`$var->method()` gained a new case: when
     the variable isn't being reassigned or called on, check whether it's `->prop = $this;`
     instead) and writes into the *same* `$propertyAssignedClasses` map the existing `$this->prop
     = ...` tracking already populates — just keyed by the variable's own tracked declared type
     rather than the current class. No new `ParseResult` field or merge step needed anywhere
     downstream; every existing consumer of that map benefits for free.
  2. `ClassAnalyzer`'s `propertyMethodCalls` resolution gained a new **ancestor**-chain fallback
     (alongside the existing Yoast SEO **descendant**-chain one) — when a property read's direct
     lookup misses, walk `$call->ownerClass`'s own `extends` chain upward looking for the first
     ancestor the property was actually assigned against.

  The ancestor-chain fallback turned out to generalize well beyond the case that motivated it:
  it also fixes the much more common classic OOP shape it had no connection to originally — a
  property assigned via a completely ordinary `$this->prop = $typedParam;` in a **base class's
  own constructor**, then read via ordinary inherited `$this->prop->method()` access from a
  method declared directly on a **subclass**. Confirmed real and unrelated to the Wordfence
  Diff-specific parser addition: WordPress SEO's `Abstract_Aioseo_Importing_Action::__construct`
  assigns `$this->import_cursor = $import_cursor;` (a plain typed constructor parameter, same
  shape the Yoast SEO fix earlier this session already handles) — but the actual
  `$this->import_cursor->get_cursor()`/`set_cursor()` reads happen in
  `Abstract_Aioseo_Settings_Importing_Action extends Abstract_Aioseo_Importing_Action`'s own
  methods, a shape neither the direct lookup nor the existing descendant fallback could ever
  reach.

  Verified: 2 new `PhpTokenParserTest` cases (the real Wordfence shape, and a negative case
  confirming only `$this` specifically triggers this — an ordinary `$var->prop = $other_var;`
  must not be misread) and 1 new `ClassAnalyzerTest` end-to-end case modeled on the real Wordfence
  shape; real Wordfence corpus — `getA`/`getB`/`getGroupedOpcodes` no longer flagged; full
  20-target corpus sanity pass shows the ancestor-fallback's broader value directly: Wordfence
  -3 unused methods, but also Elementor -12, WooCommerce -9, WordPress SEO -9, wp-smushit -2,
  wpforms-lite -3 (all spot-checked via WordPress SEO's `import_cursor` case above — genuine,
  previously-unreachable true resolutions, not over-broad suppressions) and no crashes anywhere;
  full suite (550 tests) and phpstan both green.

- [x] **`WP_CLI::add_command()`'s class-name second argument was invisible in three real,
  independent ways at once, discovered layer by layer while chasing down a single piece of
  real-world evidence to an actual fix.** Found by a fresh gap-hunting pass: Elementor's
  `core/experiments/manager.php:941` — `\WP_CLI::add_command('elementor experiments',
  WP_CLI::class);` — and two more identical-shape call sites elsewhere in the plugin. All three
  of Elementor's own `Wp_Cli`-named command classes (and their public methods) were flagged
  `UnusedClass`/`UnusedMethod`.

  Three separate, compounding gaps, each confirmed necessary by testing against the real file
  after each fix and finding it *still* broken:
  1. **`secondArgStringLiteral()` only accepted a plain string literal as the second argument.**
     `WP_CLI::class` — the idiomatic modern-PHP way to reference a class by name — was never
     recognized at all. Fixed: it now also accepts a class-name token immediately followed by
     `::class`, returning the raw class-name text exactly as the string-literal branch already
     did, so the caller's existing `resolveFqcn()` handles both uniformly.
  2. **The `WP_CLI::add_command()` reflection-dispatch check only ever lived in the bare-`T_STRING`
     call-dispatch branch.** Elementor's real call is fully qualified —
     `\WP_CLI::add_command(...)`, explicitly opting out of the file's own namespace for the real
     global `WP_CLI` class, tokenizing as `T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED` instead of
     `T_STRING` — a completely different dispatch branch with no equivalent check at all. Fixed
     by extracting the check into a new shared `recordWpCliAddCommandDispatch()`, called from
     both branches — the same "shared helper, not duplicated logic" shape already used for
     `dispatchBareFunctionCall()` earlier in the project's history for the analogous bare-vs-
     qualified function-call gap.
  3. **Class-name matching was case-sensitive everywhere, but PHP's own class-name resolution
     is case-insensitive.** Elementor's own class is declared `class Wp_Cli extends
     \WP_CLI_Command` — deliberately mimicking the real vendor class's name, just not its exact
     casing — so even with both fixes above, `WP_CLI` (from `WP_CLI::class`) never matched
     `Wp_Cli` (the real declaration) in any of this project's own short-name-keyed maps. Fixed by
     lowercasing both sides of the three specific comparisons this blocked:
     `ClassAnalyzer::findUnusedClasses()`'s `$referenced`/`$calledNames` maps (whole-class
     usage) and `findUnusedMethods()`'s `$reflectionDispatchedClassNames` map (per-method
     exemption) — not a global case-insensitivity overhaul of every map in the codebase, just
     the specific comparisons genuinely blocking this evidence.

     This third fix independently generalized far beyond Elementor: re-running the full corpus
     surfaced a **second, unrelated real-world instance** confirming it's a genuine, recurring
     class of bug, not something invented to fit one plugin — Hestia theme's own hand-rolled
     autoloader (`inc/core/class-hestia-autoloader.php`) maps the string key
     `'Hestia_Wpbakery_Compatibility'` to a file declaring `class Hestia_WPBakery_Compatibility`
     — a casing mismatch in Hestia's *own* code, nothing to do with WP-CLI at all, resolved by
     the exact same fix.

  Verified: 3 new `PhpTokenParserTest` cases (the `Foo::class` second-argument shape, the fully-
  qualified-call dispatch shape, both modeled on the real Elementor call) and 2 new
  `ClassAnalyzerTest` cases (an end-to-end case combining all three fixes exactly as Elementor's
  real code does, and a standalone case-insensitivity case using a plain `new` reference,
  independent of WP-CLI, proving the fix is general); real corpus — all three Elementor `Wp_Cli`
  findings gone (unused-class count 101 → 96, unused-method count 520 → 502 — more than the 3
  directly-traced instances, confirming further un-traced case-mismatches elsewhere in Elementor
  also resolved), Hestia's `Hestia_WPBakery_Compatibility` also resolved (1 → 0 unused classes,
  confirmed via direct trace, not just count-watching); full sanity pass across the now-expanded
  28-target corpus (13 plugins + 15 themes) shows several further plausible wins from the same
  case-insensitivity fix (WooCommerce -5 classes, wpforms-lite -3, Jetpack -2 classes) and no
  crashes anywhere; full suite (554 tests) and phpstan both green.

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

- [x] **A scoped call's sole argument reaching `include`/`require` (via a callee that builds its
  return path from its own parameter) was only resolved when that argument was a literal
  directly — a local variable whose own possible values were knowable from context (a ternary's
  two branches, or sibling equality-comparisons against it) wasn't.** Real-world finding
  (wp-nested-pages), two independent shapes in one plugin:
  - `app/Entities/Listing/Listing.php:451,474`: `$row_view = ( $this->post->type !==
    'np-redirect' ) ? 'partials/row' : 'partials/row-link'; ... include( Helpers::view($row_view)
    );` — `$row_view`'s domain comes from a ternary assignment.
  - `app/Views/settings/settings.php:20`: `if ( $tab == 'general' ) ...; if ( $tab == 'posttypes'
    ) ...; include(NestedPages\Helpers::view('settings/settings-' . $tab));` — `$tab`'s domain
    comes from being tested against literals in sibling `if` conditions, not from assignment.

  Both route through `Helpers::view($file) { return dirname(__FILE__) . '/Views/' . $file .
  '.php'; }` — a scoped method (not the bare-top-level-function-only shape
  `$functionLiteralReturns`/`$pendingTemplateHelperCalls` already handled), so `partials/row.php`,
  `partials/row-link.php`, `settings-general.php`, `settings-posttypes.php`, and
  `settings-admincustom.php` were all flagged `UnusedFile`. Fixed, three cooperating pieces:
  - `PhpTokenParser::accumulateVarLiteral()` is now the single write path for
    `$varLiteralAssignmentsStack` (previously only fed by a plain `$var = 'literal';`) — routing
    every append through one textual call site turned out to be load-bearing for PHPStan's own
    `list<string>` inference, not just a style choice: multiple independent inline `[]=` append
    sites against the same nested array broke that inference even though each site was
    individually the same proven-safe shape.
  - Two new population sites feed it: `parseTernaryLiteralDomain()` (`$var = ( <cond> ) ?
    'lit1' : 'lit2';`, condition required to be parenthesized — the one real-world shape, not
    general operator-precedence handling) and a new `T_IS_EQUAL`/`T_IS_IDENTICAL` check
    (`$var == 'literal'` anywhere, order-sensitive — no evidence yet for the reversed `'literal'
    == $var`). Deliberately doesn't distinguish "ever assigned" from "ever tested against" when
    guessing a variable's possible values — same coarse-net spirit as the rest of this parser.
  - `resolveReturnParamSuffixTemplate()` recognizes a callee's own `return <ignored> . $param .
    'suffix';` shape (doesn't verify the variable is actually the declared parameter — a small
    concession, since a small helper's return is virtually always built from its own parameter in
    real code) — keyed by `functionDefIndexForBodyStack` rather than the bare-function-only
    `$functionNameStack`, so it covers methods too. Merged across files into
    `$functionParamSuffixReturns` the same way `$functionLiteralReturns` already is.
    `PendingParamSuffixCall` (new, mirrors `PendingTemplateHelperCall`) records a scoped call's
    resolved argument candidates (bare tracked variable, or `'literal' . $var`) against its
    receiver/method, resolved once every file's parse is merged, in both the bare-`T_STRING` and
    the namespaced/fully-qualified (`T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED`) scoped-call
    branches — wp-nested-pages' own `NestedPages\Helpers::view(...)` call site is namespace-
    qualified, so only wiring the bare-name branch would have missed half the evidence.
  Verified: wp-nested-pages' unused-file count drops 30 → 25 (all 5 targeted findings gone), no
  regressions and no crashes across the full corpus.

# Function, Hook & Template Detection — Open Issues

Known gaps in `FunctionAnalyzer`, `HookAnalyzer`, and `TemplateAnalyzer` — the `functions`,
`hooks`, and `templates` checks. Same spirit as the sections above: documented scope limits in a
single-pass, no-AST tokenizer, not shipped bugs. Found by auditing these three analyzers the same
way the class/method/file gaps above were found; nothing here has been fixed yet.

## Template detection

- [x] **A Rector configuration file at a classic/hybrid theme's root was misclassified as an
  unused WordPress template.** Fresh corpus evidence: WP Rig's `rector.php` imports
  `Rector\Config\RectorConfig` and returns Rector's configuration closure, yet
  `collectTemplateFiles()` treated every root PHP file other than `functions.php`/`index.php` as
  a theme-template candidate and reported it `UnusedTemplate`. Fixed with the deliberately
  narrow, tool-convention-based `ROOT_TOOLING_CONFIG_FILES` exclusion for Rector's documented
  `rector.php` entrypoint — not a theme-specific path or a broad guess based on arbitrary file
  contents. Other root PHP files continue to be evaluated as classic-theme template candidates.

  Verified with a new `TemplateAnalyzerTest::testRectorConfigEntrypointIsNotTreatedAsAThemeTemplate`
  regression plus `testOtherUnreferencedRootPhpFileRemainsATemplateCandidate` proving the
  exception does not hide arbitrary root PHP. WP Rig's `--type=all` result drops only
  `rector.php` (one unused template → none); full `composer check` is clean (577 PHPUnit tests,
  PHPStan, and formatting), and before/after scans of all 28 supplied corpus targets show no
  other template or target changes beyond LiteSpeed Cache's separately-fixed callback methods
  above.

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

- [x] **`locate_template()` — WP core's own lower-level template-file locator
  (`wp-includes/template.php`), which `get_template_part()` and friends are themselves built on
  — was never in `TEMPLATE_FUNCS` at all.** Found in a fresh gap-hunt: `grep`ing the full
  28-project corpus for direct `locate_template(` call sites (not through any wrapper) turned up
  9 different projects (sage, botiga, storefront, kadence, flynt-v2.1.2, wpforms-lite,
  woocommerce, jetpack) — a broadly-applicable WP-core omission, not one theme's own convention.
  Real-world shape (Kadence): `locate_template( 'template-parts/archive-title/hkb-searchbox' );`.

  Fixed by simply adding `'locate_template'` to `TEMPLATE_FUNCS` as a plain entry (no stem-prefix
  rewrite of its own, unlike `get_header`/`get_footer`/`get_sidebar` — arg 0 is already the exact
  relative template path/slug, with or without `.php`, and `TemplateAnalyzer::normalizePath()`
  already strips the extension either way). This one addition is free everywhere else in the
  parser too: `isLiteralPathSinkFunction()` already reuses `isTemplateLoaderFunc()`, so
  `locate_template()` also became a valid `LiteralPathPropagationResolver` sink for a literal
  reaching it through a wrapper parameter, with zero additional code.

  Verified: new `TemplateAnalyzerTest` regression, confirmed to fail without the fix. `composer
  check` is clean (638 PHPUnit tests, PHPStan, formatting). Full 28-project corpus re-scanned
  with no crash; several projects' `UnusedTemplate`/`UnusedFile` counts dropped as a direct
  result (see the File Detection section's own `.asset.php` entry, fixed alongside this one, for
  the combined before/after numbers — the two fixes were verified together in the same pass).

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

- [x] **A vendor-prefixed dependency firing a hook the host project registers a callback for was
  architecturally invisible to `HookAnalyzer` — `FileScanner`'s vendor-directory exclusion (see
  the File Scanning section above) happens before any analyzer ever sees the file list, so a
  vendored dependency's own `apply_filters()`/`do_action()` call was never even parsed.** Root
  cause is structural, not a parsing gap: vendor-exclusion is correct for "is this file/class/
  function dead" (auditing a dependency's own internals is out of scope), but WordPress's hook
  system is fundamentally cross-boundary — a bundled dependency firing a hook the host code
  listens to is a normal, correct pattern, not something host-code candidacy exclusion should
  hide from hook resolution specifically. Found in a fresh gap-hunt: Jetpack registers
  `add_filter('jetpack_sync_home_url', ...)`/`add_filter('jetpack_sync_site_url', ...)` in its
  own host code, genuinely fired at
  `jetpack_vendor/automattic/jetpack-connection/src/class-urls.php:162,181`
  (`apply_filters('jetpack_sync_home_url', $url)`) — both showed `UnmatchedHook` purely because
  `jetpack_vendor/` is excluded from the scanned file list entirely.

  Fixed with a deliberately separate, tokenizer-free path rather than widening the main scan: a
  vendor-prefixed tree can be most of a plugin's own file count (confirmed in the corpus: Jetpack
  ~1,250 files, wpforms-lite ~3,100 — comparable to or larger than the plugin's own first-party
  code), and full `PhpTokenParser` tokenization exists to answer "is this project's own code
  dead", a question genuinely out of scope for vendor code — so this needed a much cheaper way to
  answer the one narrower question that matters: "does a literal hook tag appear as this call's
  first argument anywhere in this file". New `FileScanner::scanVendorPrefixedFiles()` (a
  deliberately separate directory walk from `scan()` — its own vendor-exclusion prunes those
  directories from being visited at all, so there's nothing to bucket from the same walk without
  restructuring it) finds every PHP file under a vendor-prefixed directory; new
  `VendorHookInvocationScanner::scan()` runs a single `preg_match_all()` per file (no
  `token_get_all()` at all) for `do_action`/`apply_filters`/`do_action_ref_array`/
  `apply_filters_ref_array` calls with a literal first argument. Coarser than real parsing (a tag
  built via concatenation, or the same text inside a comment/unrelated string, isn't
  distinguished) — but this only ever *adds* a hook to the fired set, never fabricates a new
  finding, so a rare false positive here just suppresses one real `UnmatchedHook` rather than
  creating a wrong one; the same one-directional, low-stakes "coarse net" trade-off this codebase
  already accepts throughout the class-name-transform and literal-path mechanisms.
  `HookAnalyzer::analyze()` takes the resulting tag set as a new optional parameter, merged
  directly into its own `$firedTags` — every existing prefix/suffix/pass-through resolution
  layered on top of that set needed no changes at all. Wired in `Application.php`: only computed
  when `--type` includes `hooks` (a plain filesystem walk plus a lightweight regex pass, but
  still real work skipped when the check isn't requested), and only for a real filesystem target
  (a pre-supplied file list has no root path to search beneath, and isn't expected to include
  vendor code anyway).

  Verified: new `VendorHookInvocationScanner` unit tests (all four invoke functions, both quote
  styles, an unrelated function call correctly ignored, an empty file list), a new
  `FileScannerTest` regression confirming `scanVendorPrefixedFiles()` finds exactly the inverse
  of what `scan()` excludes, and a new `HookAnalyzerTest` regression reproducing Jetpack's real
  shape — each confirmed to fail without its own piece. `composer check` is clean (649 PHPUnit
  tests, PHPStan, formatting). Performance stayed reasonable despite the large vendor trees
  involved (a plain filesystem walk plus one regex pass per file, no tokenization): Jetpack's own
  `--type=hooks` scan completed in ~1.3s, wpforms-lite's (~3,100 vendor files) in ~1.6s,
  WooCommerce's (the corpus's largest, ~4,000 total files) in ~4.1s. Traced precisely against the
  real corpus, and turned out considerably broader than Jetpack alone: `UnmatchedHook` drops
  Jetpack 174 → 95 (−79, well past the original gap-hunt's own 55-finding estimate), wpforms-lite
  30 → 20 (−10), Hestia 46 → 41 (−5), Flynt 21 → 16 (−5), Neve 63 → 60 (−3), WooCommerce 46 → 44
  (−2) — six projects, not one; full 28-project corpus re-scanned with no crash and no other
  category regressed anywhere.

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

# Open — found, deliberately not fixed yet (higher effort than typical, thinner evidence)

Confirmed real gaps from a fresh gap-hunting pass, explicitly deferred by user decision rather
than fixed on the spot — each would need a genuinely new capability, not a small extension of an
existing mechanism, and each has only 1-2 confirmed real-world instances so far (this project's
own bar for "worth building" is usually cleared at 2+ independent instances plus a bounded,
low-risk implementation path — neither item here clears both). Re-read this entry before picking
either back up; don't re-discover the same evidence from scratch.

- [x] **A hook/action name passed as a literal argument to a private wrapper *function or
  method*, which internally calls an already-recognized hook-firing function using that
  parameter unchanged, wasn't resolved.** Confirmed real-world (WooCommerce,
  `includes/class-wc-post-data.php`): `self::schedule_variation_summary_regeneration(
  'wc_regenerate_attribute_variation_summaries', ...)` / `('wc_regenerate_product_variation_
  summaries', ...)` — the private static method's own body does
  `as_schedule_single_action($timestamp, $action_name, $args, $group)` using its own
  `$action_name` parameter, no concatenation, no transform. Both hooks are real,
  `add_action()`-registered, and genuinely fire, but showed as `UnmatchedHook` since nothing at
  the sink call site is a *direct* literal argument — the literal only reaches it via an
  intermediate parameter one call away.

  Fixed with a small, targeted piece rather than the full `LiteralPathPropagationResolver` graph
  (that mechanism is scoped to file/template sinks only): `captureHookPassThroughParam()`
  recognizes a `CRON_SCHEDULE_FUNCS`/`HOOK_INVOKE_FUNCS` call whose hook-tag argument is a bare
  variable resolving to one of the *enclosing* function's own `#param:N` graph nodes (reusing
  the node map `LiteralPathPropagation` already builds for every function — no new per-function
  tracking needed), and records `(functionKey, paramPosition)` into
  `$hookPassThroughParams`. `HookAnalyzer` resolves this directly against `$literalPathInputs`
  (already populated for every named/scoped call site project-wide, not just file-related ones)
  once every file is merged: any literal argument at that exact parameter position credits the
  hook as fired, the same way a direct literal at the sink already would. Verified: WooCommerce's
  `UnmatchedHook` count drops (both `wc_regenerate_*_variation_summaries` findings gone), no
  regressions and no crashes across the full corpus.

  **Also fixed — the closure variant:** `includes/wc-product-functions.php:575-611`, a local
  closure assigned to `$schedule`, called as `$schedule($date, 'wc_product_start_scheduled_sale')`
  / `('wc_product_end_scheduled_sale')`, whose body does `as_schedule_single_action($timestamp,
  $hook, $args, 'woocommerce-sales')` using its own `$hook` parameter. Same pass-through shape,
  but the call site is `$schedule(...)` — variable-as-function dispatch, which this parser
  doesn't track receivers/parameters for in general, so it needed its own small, entirely local
  mechanism rather than an extension of `captureHookPassThroughParam()`'s named/scoped-call
  machinery:
  - A closure has no stable `Class::method` key the way a named function/method does, so it gets
    no `LiteralPathPropagation` graph node at all (that machinery deliberately skips anonymous
    closures). Instead, `$var = function ($date, $hook) { ... };` is recognized directly at the
    `T_FUNCTION` token (backward-checking for `$var =` immediately before it), capturing the
    closure's own parameter names via the same `parseFunctionParameterNames()` used for named
    functions.
  - Inside the closure's body, a new `captureClosureHookPassThroughParam()` — the closure-local
    sibling of `captureHookPassThroughParam()` — matches a `CRON_SCHEDULE_FUNCS`/
    `HOOK_INVOKE_FUNCS` call's bare-variable tag argument against the closure's own parameter
    *names* (not a node map), recording which parameter position holds the hook name, keyed by
    the variable the closure is currently assigned to.
  - At the closure's own call site (`$schedule(...)`), a new bare-variable-invocation check
    (previously entirely unhandled) looks up that recorded position and, if the literal argument
    there resolves, emits a `HookInvocation` immediately — no merge-later `Pending*` struct
    needed, since a closure can never leak its identity to another file.
  - **Bug caught during implementation:** the closure's own body opens a fresh function scope
    like any other declaration (`$expectingFunctionOpen` fires unconditionally for every
    function/method/closure), which was pushing a *new* `$closureHookPassThroughParamStack`
    frame for the closure's own body — meaning every match got recorded into a frame that was
    discarded the instant the closure's own `}` closed, before the outer `$schedule(...)` call
    was ever reached. Fixed by skipping that stack's push/pop specifically when the scope being
    opened/closed is *also* a closure scope (checked via `$closureVarDepthStack`), so matches
    land in the enclosing scope's frame instead — pinned directly with
    `testClosureHookPassThroughParamResolvesAtItsOwnCallSite`, not just corpus verification.
  Verified: WooCommerce's `UnmatchedHook` count drops 48 → 46 (both `wc_product_*_scheduled_sale`
  findings gone), no regressions and no crashes across the full corpus.

- [x] **A literal reaching an include/template sink through named or scoped wrappers was only
  recognized at the sink itself.** Confirmed real-world finding (wpforms-lite):
  `wpforms_render('admin/challenge/embed', [...])` in `src/Admin/Challenge.php` passes its first
  parameter through `Templates::get_html()` and `Templates::include_html()`;
  `include_html()` appends `'.php'`, obtains `Templates::locate($template_name)` through
  `apply_filters()`, then reaches `load_template($located)`/`require $located`. Thus the
  outermost literal was several calls removed from the real template sink, leaving
  `admin/challenge/embed.php`, `admin/challenge/modal.php`, `admin/challenge/welcome.php`, and
  `admin/components/chart.php` among 118 apparent `UnusedTemplate` findings despite live
  `wpforms_render()` call sites.

  Fixed by the shared bounded `LiteralPathPropagationResolver`: the tokenizer records only
  exact parameter/local/return links through named or resolved scoped calls, applies fixed
  prefix/suffix fragments, and follows them for at most 16 links to `include`/`require` or a
  WordPress template sink (`load_template()` and the existing template-loader family). It also
  accepts the two portable, independently justified pass-through forms in this evidence:
  `apply_filters($tag, $value, ...)`'s documented value position and PHP-core
  `ltrim($path, '/')`; neither permits arbitrary transformations. This follows the same
  coarse-net philosophy as existing deferred resolution, but requires at least one fixed path
  fragment before a direct parameter-to-include can suppress a finding — it is specifically not
  an "any function that passes a variable" rule.

  **Scope limitation:** Literal values must be direct positional strings or direct literal
  key/value entries of an array argument; wrapper assignments must be a direct local/array-key
  flow, fixed concatenation, the two pass-through forms above, or a resolved scoped return.
  Dynamic function callbacks, variable-as-function calls, arbitrary string transforms, and the
  separate `wpforms_settings_output_field()` callback/config-array shape below remain unresolved.

  Verified with parser regressions for positional/keyed inputs and a rejecting arbitrary
  `str_replace()` transform, plus a `TemplateAnalyzer` end-to-end chain retaining an
  unconstructed direct-parameter orphan. Before/after current-corpus scans reduce wpforms-lite
  `UnusedTemplate` findings **118 → 42** (−76); direct graph inspection finds 113 literal
  `wpforms_render` inputs, 90 resolved paths, and 80 matching bundled templates (the four
  remaining already-referenced paths explain the 76-finding delta). The remaining 42 template
  findings are outside that resolved `wpforms_render` path. All 28 supplied targets were
  re-scanned with no crash; no other target/category count changed. Final `composer check` is
  clean (PHP-CS-Fixer, PHPStan, and PHPUnit).

  Related, same file, but turns out to be a *fourth*, deeper shape on investigation (re-read this
  before attempting it — see the Group 2 gap-hunt session that also fixed the Sydney/WooCommerce
  dispatch items above): `includes/admin/settings-api.php:22`, `$callback = 'wpforms_settings_' .
  $args['type'] . '_callback'; ... $callback( $args );` — a dynamic BARE FUNCTION name built from
  a prefix/suffix around `$args['type']`, called via a local variable holding the built name
  (`$callback($args)`, a variable-as-function call). The literal `'type' => 'checkbox'`-style
  values aren't passed directly to a call anywhere — they're buried as entries inside a giant
  static nested config array (`includes/admin/class-settings.php`'s `$settings` return array,
  hundreds of lines), which some other code later iterates and passes per-field to
  `wpforms_settings_output_field()`. Neither a scattered-call-site-literal-collection shape (like
  Sydney's, fixed) nor a scattered-array-key-assignment shape (like WooCommerce's, fixed) — it's
  "extract a specific key's literal value from deeply-nested array-literal entries scattered
  through one big static config array," a distinct and less contained collection problem. Thinner
  tractability than the other three items in this family; not attempted when Sydney/WooCommerce
  were fixed.

- [x] **An inline `// phpcs:ignore ...` comment directly after a call's opening `(`, before its
  first argument, silently defeated the whole `LiteralPathPropagationResolver` chain above for
  that call site.** Real-world finding (WPForms): `echo wpforms_render( // phpcs:ignore
  WordPress.Security.EscapeOutput.OutputNotEscaped\n\t'education/admin/did-you-know', [...] );`
  in `DidYouKnow.php` (and 7 sibling call sites across `lite/templates/`) — the stray comment
  token, not a literal, ended up first in the extracted argument-0 token list, so
  `literalPathStringLiteral()`'s own `count($tokens) === 1` exact-match check never matched and
  the literal was invisible to the graph, despite a live `wpforms_render()` call site right
  there. `literalPathCallArgumentTokensAt()` (the call-argument extractor feeding this whole
  mechanism) only stripped `T_WHITESPACE` from a collected argument's tokens, not
  `T_COMMENT`/`T_DOC_COMMENT` — fixed by stripping both, the same "insignificant" definition
  `skipInsignificant()` already uses everywhere else in this parser. Only reproduces through the
  real multi-hop chain (`wpforms_render()` -> `Templates::get_html()` ->
  `Templates::include_html()` -> `require`, each hop's own `apply_filters()`-wrapped assignment
  intact) — a single-hop wrapper resolves regardless of this bug via a different, simpler path,
  so a minimal one-hop reproduction attempt initially (and misleadingly) suggested no bug existed
  at all.
  Verified: wpforms-lite's `UnusedFile` count drops 43 → 7 and `UnusedTemplate` drops 42 → 35 (the
  same bug independently affected several other `lite/templates/` call sites carrying the same
  inline-comment style); full 28-project corpus re-scanned with no crash and byte-identical
  output on every other plugin/theme.

- [x] **`$functionLiteralReturns` (a `return` statement's resolved literal(s)) only ever tracked
  a top-level named function — `$functionNameStack` was `null` inside any method body, so a
  method's own `return 'literal';`/`return self::CONST;` was invisible to it, unlike the sibling
  `$functionParamSuffixReturns` mechanism, which already keys by `"Class::method"`. Also,
  `resolveReturnLiterals()` itself only recognized a direct literal, a variable, or
  `apply_filters()` — never a bare `self::`/`static::`/`Foo::CONST` return.** Real-world shape
  motivating this (WPForms): `General::get_slug()` does `return static::TEMPLATE_SLUG;` and
  `General::get_parent_slug()` does `return self::TEMPLATE_SLUG;`; `StripeCardTestingAlert::
  get_parent_slug()` does `return Summary::TEMPLATE_SLUG;` (an explicit class reference). Fixed
  two independent gaps:
  - `resolveReturnLiterals()` gained a `self::`/`static::`/`Foo::CONST` branch, sharing the exact
    same resolution logic `classifyArgTokens()`'s own identical branch already used (factored out
    into `resolveScopedClassConstant()`) — `static::CONST` resolves against the *physical*
    declaring class only, no late-static-binding fan-out across subclass overrides (same
    documented limitation as $classNameTransformTemplates' own scope note).
  - `$functionLiteralReturns`'s own population now uses `$functionDefIndexForBodyStack` (already
    built for `$functionParamSuffixReturns`) instead of the now-dead `$functionNameStack`/
    `$pendingFunctionName` (removed), keying a method by `"Class::method"` the same way.

  At the time this landed, no consumer read the method-keyed entries yet (see the immediate
  follow-up item just below, which wires one up) — verified in isolation with 5 new parser-level
  regressions (`testMethodReturningAPlainLiteral...`, `testMethodReturningSelfClassConstant...`,
  `testMethodReturningAnExplicitClassConstant...`,
  `testMethodReturningAnUnresolvableClassConstantYieldsNoLiteral`,
  `testTopLevelFunctionLiteralReturnKeyingIsUnchanged` — the last confirming the top-level-
  function case is unaffected by the method-keying change).

- [x] **`LiteralPathPropagationResolver`'s own concatenation-term classifier
  (`resolveLiteralPathExpressionTokens`) neither recognized a method-call term nor supported more
  than one unresolved term per statement — exactly what WPForms' own motivating chain above
  needs.** `General::get_full_template_name()`'s `$template = 'emails/' . $this->get_slug() .
  '-' . $name;` has *two* non-literal terms (`$this->get_slug()` and `$name`), where the existing
  "more than one dynamic value is not a bounded path template" rule would bail entirely. Fixed by
  teaching the term classifier a THIRD option beyond "is this a literal" / "is this the one
  flowing node": `literalPathStringLiteralOrMethodReturn()` recognizes a zero-arg
  `self::`/`static::`/`parent::`/`Foo::`/`$this->` method call
  (`literalPathZeroArgMethodCallKey()`, same `"Class::method"` keying as the item above) whose
  `$functionLiteralReturns` entry is a single deterministic literal, and substitutes it as if it
  were a literal term directly — leaving `$name` as the sole genuinely dynamic term. Same-file,
  single forward pass, defined-before-use (`get_slug()` is parsed before
  `get_full_template_name()` references it) — the same "coarse net" trade-off self::/static::CONST
  resolution already relies on elsewhere in this parser.

  Two more real, narrower gaps surfaced and were fixed alongside this, both confirmed necessary
  by direct trace against the real corpus (a synthetic reproduction missing either one silently
  "worked" through an unrelated path and would have hidden both):
  - `literalPathNodeFromTokens()`'s existing `basename($x)`-is-an-identity-transform allowlist
    only matched a bare `T_STRING` function-name token. WPForms' own code calls it backslash-
    qualified (`\sanitize_file_name( $name )`, reassigning `$name` before the concatenation above
    even runs) — a `T_NAME_FULLY_QUALIFIED` token, invisible to the bare-`T_STRING` check, which
    invalidated `$name`'s own tracked node before the critical line was ever reached. Fixed by
    switching to the same `CLASS_NAME_TOKENS` + `shortClassName()` pattern
    `literalPathFunctionNameFromTokens()` already uses elsewhere in this exact file, and added
    `sanitize_file_name` itself to the allowlist (same no-op-for-matching-purposes reasoning as
    `basename()` — every real template-part slug here is a plain lowercase word with nothing for
    it to strip).
  - Confirmed by trace, *not* fixed: `captureLiteralPathCall`'s own argument-source resolution
    doesn't check `literalPathScopedCallTarget()` the way `resolveLiteralPathAssignmentSource`
    does — a call argument that's itself a scoped call to an already-tracked function/method
    (`Templates::get_html( $this->get_full_template_name( $name ) )`) doesn't link the callee's
    return node to the outer call's own parameter node. Real-world impact turned out to be
    limited: WPForms' actual resolution path runs through the *separate*
    `Templates::locate( $template . '.php' )` existence-check guard inside
    `get_full_template_name()` itself (a plain concatenation-argument call, already supported),
    not through this specific shape — listed here as a confirmed, narrower, still-open gap rather
    than re-investigated from scratch next time.

  **Scope limitations, both pre-existing and already documented elsewhere, not reintroduced by
  this fix:** no late-static-binding fan-out for `static::CONST`/`$this->method()` across
  subclass overrides (`get_slug()`/`get_parent_slug()` always resolve against the *physical*
  declaring class, `General` — see the item above); and local-variable tracking's own
  control-flow blindness means `if ($this->plain_text) { $name .= '-plain'; }` unconditionally
  wins over the non-plain-text case in source order, so the resolved path always carries
  `-plain` — correctly crediting `general-body-plain.php` (which exists) but not
  `general-header.php`/`general-footer.php`/etc. (which have no `-plain` counterpart to
  incorrectly resolve to instead).

  Verified: 4 new parser-level regressions (`testLiteralPathPropagationResolvesAMethodCallTerm
  InAConcatenation`, `...LeavesAMethodCallTermUnresolvedWithMultipleReturns` — a method with
  several literal-return branches is correctly left unresolved, not guessed —
  `...TreatsSanitizeFileNameAsAnIdentityTransform`) plus one `TemplateAnalyzer` end-to-end
  regression (`testMethodCallTermInsideAConcatenationReferencesTemplatePath`, confirmed to
  actually fail without this fix by toggling it off). `composer check` is clean (607 PHPUnit
  tests, PHPStan, formatting). wpforms-lite's `UnusedTemplate` count drops 35 → 34
  (`general-body-plain.php` — the one case where every piece of this fix, the CONST-return
  support above, and the phpcs-comment fix earlier, all line up); full 28-project corpus
  re-scanned with no crash and byte-identical output on every other plugin/theme.

- [x] **A scoped call's literal argument reached an `include`/`require` only after the callee's
  own body wrapped the parameter in a transform function (`basename($param)`) before
  concatenating a fixed prefix/suffix around it — the existing bulk-directory-loader mechanism
  (`PendingDirectoryLoaderCall`) is the wrong shape for this and can't be patched into fitting.**
  Real-world finding (Akismet): `Akismet::view($name)` (`class.akismet.php:2078-2088`) does
  `$file = AKISMET__PLUGIN_DIR . 'views/' . basename( $name ) . '.php'; ... include $file;`,
  called throughout as `Akismet::view('activate')`, `Akismet::view('notice', ...)`, etc. — a
  textbook scoped-call-with-literal-first-arg-reaching-an-include, except **100% of Akismet's 14
  `UnusedFile` findings** (`activate.php`, `notice.php`, `start.php`, ... every file under
  `views/`) were flagged. Root cause wasn't really the `basename()` wrapper specifically — even
  without it, `PendingDirectoryLoaderCall` treats the literal argument (`'activate'`) as a
  *directory name* to exempt wholesale (the shape that fits `Foo::bulkLoad('inc')`: exempt the
  whole `inc/` tree), not as a filename to resolve to one specific file via a prefix/suffix
  template the callee's body reveals — a fundamentally different shape.

  Fixed by extending the shared `LiteralPathPropagationResolver` graph (see the wpforms/Blocksy
  items above) with two small, evidence-driven tolerances, rather than a bespoke Akismet
  mechanism:
  - `literalPathNodeFromTokens()` now recognizes `basename( $param )` as transparent for
    matching purposes — it resolves to the exact same graph node its inner expression would.
    This is a safe no-op specifically because `FileAnalyzer`'s own referenced-index already
    matches by `basename()` regardless of what the caller passes; the transform never changes
    what the tool can prove.
  - A new `isLiteralPathIgnorableConstantExpression()` tolerates a bare, unresolvable named
    constant (`AKISMET__PLUGIN_DIR`) as an ignorable concatenation term, the same "deterministic
    absolute root this parser doesn't need to resolve" treatment `isLiteralPathRootDirectoryExpression`
    already gives `get_template_directory()`/`get_stylesheet_directory()` — just generalized to
    the equally common WP-plugin-bootstrap `define()`d PATH/DIR constant convention instead of a
    curated pair of function names. A bare identifier term in concatenation position is virtually
    always a constant reference, not a genuinely unknowable value.
  Verified: Akismet's `UnusedFile` count drops **14 → 0** (`bin/wp-specter scan
  ../wp-tests/plugins/akismet` reports "All clear"), no regressions and no crashes across the
  full corpus.

- [x] **A literal reaching a sink only after crossing an object-construction boundary — a static
  factory forwards its argument into a constructor via `new self(...)`, the constructor stores it
  as a property, and a *different* method later reads that property to build the path — was
  invisible to `LiteralPathPropagationLink`'s graph, which only knew about parameters, locals,
  and return values.** Real-world finding (Wordfence): `wfView::create($view, $data)` (`lib/
  wfView.php`) does `return new self($view, $data);`; the constructor does `$this->view = $view;`;
  a wholly separate method, `render()` (called later, with no arguments of its own), does
  `$view = preg_replace('/\.{2,}/', '.', $this->view); $view_path = $this->view_path . '/' .
  $view . $this->view_file_extension; ... include $view_path;` — called throughout as
  `wfView::create('scanner/text/issue-base', ...)`. **100% of the ~60 files under `views/`** were
  flagged `UnusedFile` despite every one of them being loaded exactly this way.

  Three genuinely new pieces, none of them Wordfence-specific:
  - A property node identity (`literalPathPropertyNode()`: `Class::$prop`, stable regardless of
    which method reads it, unlike a per-function counter-based local node) plus a new capture,
    `captureLiteralPathPropertyAssignment()`, for `$this->prop = <expr>;` — the property-scoped
    sibling of the existing local-variable `captureLiteralPathAssignment()`. Only ever marks a
    property "tracked" (consulted by `literalPathNodeFromTokens()`'s own new `$this->` case) on a
    *resolved* source; `$this->view_path = WORDFENCE_PATH . 'views';` (an opaque constant) leaves
    `view_path` untracked. This is single-pass and defined-before-use, same limitation as every
    other cross-statement tracking in this parser: it only resolves when the writer (the
    constructor) is parsed before the reader (`render()`) in source order — true of every real
    case found, but undocumented as a general guarantee.
  - `new self(...)`/`new static(...)`/`new ClassName(...)` now feeds the same literal-path
    wrapper-call graph any plain function/method call already does (`resolveNewExpressionClassNameAt()`
    factored out of the existing `assignedNewClassName()` to share the self/static/parent
    resolution logic), targeting `<Class>::__construct` — previously `T_NEW` never fed this graph
    at all, assignment or not.
  - An untracked `$this->prop` term (`isLiteralPathIgnorablePropertyExpression()`) is treated as
    ignorable — contributing neither a literal fragment nor counting as the expression's one
    dynamic segment — the property-access counterpart to the existing bare-constant/
    `get_template_directory()` tolerances; without it, `render()`'s own `$this->view_path . '/' .
    $view . $this->view_file_extension` has three dynamic-looking terms and the whole expression
    (correctly, per the "more than one dynamic value" rule) refuses to resolve. Two smaller,
    narrowly-scoped pieces make the *other* two terms resolve cleanly instead of needing that
    tolerance themselves: `preg_replace('/\.{2,}/', '.', $x)` is now a transparent wrap (literal
    pattern/replacement required) alongside the existing `basename()`/`sanitize_file_name()`
    no-ops, and a class-body property declared with a single scalar string-literal default
    (`protected $view_file_extension = '.php';`) is captured
    (`$literalPathPropertyLiteralDefaults`) and resolved by
    `literalPathStringLiteralOrMethodReturn()` the same way a fixed literal segment already would.

  Deliberately not attempted: `wfView::create('scanner/text/issue-' . $i['type'], ...)` (two call
  sites) — the view slug's dynamic segment is an array-element read off a foreach loop variable,
  not a plain wrapper parameter; resolving it needs the array-element-access domain support noted
  as a separate, distinct gap.

  Verified: a new `FileAnalyzer` end-to-end regression
  (`testStaticFactoryConstructorPropertyRenderViewLoaderReferencesFile`) reproducing the real
  three-hop shape exactly, with a direct `LiteralPathPropagationResolver::resolve()` assertion
  (not just the coarser basename/stem match `FileAnalyzer` itself also does) confirming the
  resolved path carries the real `.php` extension, not just Wordfence's coarser fallback masking
  a broken piece — each of the six new pieces (the `new self()` capture, the property-assignment
  capture, the `$this->` node case, the ignorable-property check, the `preg_replace` wrap, and
  the scalar-property-default capture) confirmed individually load-bearing by toggling each off
  in turn and re-running the test. `composer check` is clean (636 PHPUnit tests, PHPStan,
  formatting). Traced precisely against the real corpus: Wordfence's `UnusedFile` count drops
  60 → 38 (22 files — every plain-literal `wfView::create()` call whose file wasn't already
  referenced some other way; the remaining 38 include the ~33 array-element-access cases noted
  above, correctly still unresolved); full 28-project corpus re-scanned with no crash and
  identical output on every other project.

- [ ] **WooCommerce's PayPal IPN handler dispatches via `payment_status_{$posted['payment_status']}`**
  (`class-wc-gateway-paypal-ipn-handler.php:76`) — genuinely unenumerable from static code alone
  (the dynamic segment comes from external HTTP request data). Not a tractable gap; listed here
  only so a future pass doesn't re-investigate it from scratch. (The sibling
  `render_{$column}_column` dispatch found alongside this is now fixed — see the Method detection
  section above.)

- [x] **A literal positional or keyed-array argument reaching `include`/`require` through a
  fixed-path wrapper and a second require helper was not resolved.** Confirmed real-world
  evidence (Blocksy theme): `blocksy_get_options('general/buttons')` in
  `admin/helpers/options.php` builds `get_template_directory() . '/inc/options/' . $path .
  '.php'` and passes `$path` to `blocksy_get_variables_from_file()` in `inc/helpers.php`, whose
  body does `require $file_path`. The sibling
  `blocksy_theme_get_dynamic_styles(['name' => 'global-inline', ...])` form in
  `inc/helpers/dynamic-css.php` first preserves its caller-supplied array via `wp_parse_args()`,
  builds `$args['path']` as `'/inc/dynamic-styles/' . $args['name'] . '.php'`, then calls the
  same require helper.

  Fixed by the shared literal-path graph described in the wpforms item above, rather than any
  Blocksy-specific name/path rule. It tracks the declared parameter position (or a direct
  literal keyed array entry), its fixed prefix/suffix construction, and the exact named/scoped
  call that passes the local result to another wrapper's parameter. A `wp_parse_args($sameArray,
  ...)` normalization preserves explicitly supplied literal keys because that is WordPress
  core's documented merge behavior; no other arbitrary array transform is accepted. This
  resolves each concrete file path rather than incorrectly exempting a directory as a
  bulk-loader.

  **Scope limitation:** It does not infer values from computed array keys, `array_merge()`,
  callback/config-array iteration, concatenated literals at the call site, or arbitrary
  sanitizers/transforms. A chain without at least one fixed prefix/suffix segment still cannot
  suppress an `UnusedFile`, even if its final helper contains `require`.

  Verified in the parser test's independent positional and keyed fixtures and the
  `FileAnalyzer` end-to-end regression, including a bare parameter-to-require negative case.
  On the supplied current Blocksy corpus, graph inspection resolves **70 existing
  `inc/options/` paths and 24 existing `inc/dynamic-styles/` paths** through these exact two
  forms. Its current baseline already reported `UnusedFile` **0 → 0**, so that historic
  77-finding cost cannot produce an additional visible scan delta in this worktree; the new
  resolver's exact paths and isolated analyzer regression are the attributable verification.
  All 28 supplied targets were re-scanned with no crash; only wpforms-lite's separately
  documented template count changed. Final `composer check` is clean (PHP-CS-Fixer, PHPStan,
  and PHPUnit).

- [ ] **A class-property array of literal-string entries, iterated by a shared trait/base method
  that registers `[$this, transform(literal)]` as an `add_action`/`add_filter` callback where
  `transform` is a known string function (`str_replace()`) applied to the literal, isn't
  resolved.** Real-world finding (Blocksy theme, `inc/classes/trait-wordpress-actions-manager.php`,
  used by 5 classes): `ArchiveLogic` declares `$actions = [['action' => 'blocksy:loop:before'],
  ['action' => 'blocksy:loop:after']]`; the trait's `attach_hooks()` does
  `add_filter($action['action'], [$this, str_replace([':','-'], '_', $action['action'])], ...)`,
  resolving to methods `blocksy_loop_before`/`blocksy_loop_after` — both flagged `UnusedMethod`. A
  second call site (`add-to-cart.php:317`) confirms the trait is the recurring cause, not a
  one-off. Harder to generalize than a simple concatenation (needs to model `str_replace`'s actual
  character-mapping semantics, or accept a curated list of recognized transform-function shapes)
  — cleanly bounded to one trait/pattern, but no second unrelated project confirms it recurs
  outside Blocksy yet.

- [ ] **A `get_template_part()`-style call built from a runtime-configurable option value (not a
  static array/loop/property) isn't enumerable at all, and shouldn't be guessed at.** Real-world
  finding (Kadence theme, `custom_header/component.php:211`): `foreach ($elements[$row][...] as
  $item) { get_template_part('template-parts/header/' . $item, ...); }` where `$item` comes from
  `kadence()->option('header_desktop_items')`, a customizer-configurable value — 8+ template-part
  files flagged unused. A literal catalog of valid values does exist elsewhere
  (`header_desktop_available_items` in `header-builder-options.php`, a customizer-control
  "available items" palette array) but it's UI metadata one more indirection away from the actual
  data flow (option storage → foreach → path), not a source of truth safely treated as
  authoritative. Listed here as evidence a future pass might reference, not as a proposed
  mechanism — this is a genuinely dynamic (user-configurable at runtime) value, structurally
  different from every other item in this section, which are all resolvable from static code
  alone.
