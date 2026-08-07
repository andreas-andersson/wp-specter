# Parsing / Class & Method Detection — Open Issues

Known gaps in `PhpTokenParser` and the class/method-unused analysis built on top of it
(`ClassAnalyzer`). Nothing here is a correctness bug in what's shipped — each is a documented
scope limit that trades recall or precision for staying a single-pass, no-dependency tokenizer
(no AST, no type inference). Recorded here so they don't get re-discovered from scratch.

## Class detection

- [ ] **Interfaces/traits/enums are never tracked as a `ClassDef`.** Only `class Foo {}`
  declarations produce one (`PhpTokenParser::parseClassDef`, only called from the `T_CLASS`
  branch). `interface`/`trait`/`enum` bodies still open a brace context (so methods inside are
  correctly marked `isMethod`), but the declaration itself is invisible to `ClassAnalyzer`'s
  unused-class check. An unused interface, trait, or enum is never flagged, full stop.
  - Fix shape: give `T_INTERFACE`/`T_TRAIT`/`T_ENUM` their own lookahead (mirroring
    `parseClassDef`) and either extend `ClassDef` with a `kind` field or add a
    parallel `InterfaceDef`/`TraitDef`/`EnumDef`. Also need to decide whether trait/enum
    references should count the same way class references do (`use TraitName;` inside a class
    body currently isn't captured as a classReference at all — a second gap bundled with this one).

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

- [ ] **Contract-method exemption (`ClassAnalyzer::isContractMethod`) only checks the declaring
  class's own `extends`/`implements`, not the full inheritance chain.** A class that extends
  `My_Base_Widget`, which itself extends `WP_Widget`, won't get the `widget()`/`form()`/`update()`
  exemption — `ClassDef::$extends` only has `My_Base_Widget`, since each `ClassDef` only records
  its *own* declaration's clause, and there's no cross-class resolution step.
  - Fix shape: `ClassAnalyzer` already builds `$classDefsByName` — walk it (`$def->extends[0]`
    repeatedly, bounded depth to survive a cycle/bad input) before checking contract methods,
    instead of only checking one level.

- [ ] **Property types aren't tracked.** `$this->service = new My_Service(); $this->service->render();`
  — local variable tracking (`$varTypesStack`) only covers local variables, not object
  properties. `$this->service->render()` falls back to the unscoped/name-only pool.
  - Fix shape: would need a per-class (not per-function) property-type map, populated from
    `$this->prop = new ClassName()` assignments seen anywhere in the class body, and consulted
    for `$this->prop->method()` — meaningfully bigger than local-variable tracking since it's
    class-scoped rather than function-scoped and has to survive being set in one method and read
    in another.

- [ ] **Type-hinted parameters don't seed variable tracking or count as class references.**
  `function foo(My_Class $x) { $x->method(); }` — `$x`'s type is stated right there in the
  signature but `parseFunctionDef` doesn't parse parameter types at all (only the function name).
  Two independent gaps bundled here: (1) `$x->method()` stays unscoped even though the type is
  statically known, (2) `My_Class` isn't added to `classReferences` from the type-hint alone, so
  a class *only* ever used as a parameter type still looks unused.
  - Fix shape: extend `parseFunctionDef`'s lookahead to walk the parameter list, capturing
    `TypeHint $var` pairs, and seed the new function scope's `$varTypesStack` entry with them
    (reuse `resolveClassNameToken` for `self`/`static` hints); push each hint type into
    `classReferences` too.

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

1. Type-hinted parameters (two gaps, one parser change, likely highest value-to-effort — WP OOP
   code type-hints service/collaborator parameters constantly).
2. Multi-level inheritance for contract-method exemption (small, bounded change to
   `ClassAnalyzer`, removes a real false-positive source for any widget/walker/controller
   subclass more than one level deep).
3. Interface/trait/enum unused-detection (currently a complete blind spot, not just an
   imprecision).
4. Everything else — property types, return-type inference, control-flow awareness, namespaced
   calls — is progressively more work for progressively rarer real-world patterns in typical
   WordPress code.
