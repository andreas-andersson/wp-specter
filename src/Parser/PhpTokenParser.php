<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * @phpstan-type Token array{0: int, 1: string, 2: int}|string
 *   token_get_all()'s element shape: either a single-character token (a plain string) or
 *   [token type, token text, line number] for everything else.
 */
final class PhpTokenParser
{
    private const HOOK_REGISTER_FUNCS = ['add_action', 'add_filter'];
    private const HOOK_INVOKE_FUNCS = ['do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array'];
    // Hook tag argument position (0-indexed) for WP-Cron scheduling calls — the hook itself
    // fires later inside WP-Cron core, not via a visible do_action() in project code. The
    // as_*_action() entries are Action Scheduler's own equivalent — a widely-bundled standalone
    // library (WooCommerce, wpforms-lite, and many other plugins each ship their own copy)
    // providing the exact same "schedule a hook name, it fires later via a cron-like runner"
    // shape, just a different function family this dispatch was blind to entirely. Confirmed
    // real-world (WooCommerce): `as_schedule_recurring_action($time, DAY_IN_SECONDS,
    // 'wc_admin_daily_wrapper', ...)`/`as_schedule_single_action($time,
    // 'generate_category_lookup_table_wrapper', ...)` — both real add_action()-registered hooks,
    // flagged UnmatchedHook purely because Action Scheduler's own scheduling call was invisible.
    // Argument positions taken directly from Action Scheduler's own published function
    // signatures (function-scheduler.php in the library itself).
    private const CRON_SCHEDULE_FUNCS = [
        'wp_schedule_event' => 2,
        'wp_schedule_single_event' => 1,
        'as_enqueue_async_action' => 0,
        'as_schedule_single_action' => 1,
        'as_schedule_recurring_action' => 2,
        'as_schedule_cron_action' => 2,
    ];
    // get_header()/get_footer()/get_sidebar() are WP core's own three template-hierarchy loader
    // functions — each gets its own filename-stem prefix rewrite in parseTemplateRef() (arg 0
    // 'kiosk' => 'header-kiosk.php'), a WP-core-specific convention no third-party wrapper
    // replicates under its own name, so these three stay fixed, exact entries rather than folded
    // into the suffix-based check below. `locate_template()` is WP core's own lower-level
    // template-file locator (wp-includes/template.php) that get_template_part() and friends are
    // themselves built on — needs no stem-prefix rewrite of its own (arg 0 is already the exact
    // relative template path/slug, with or without ".php" — TemplateAnalyzer's own
    // normalizePath() already strips the extension either way), so it's just another plain entry
    // here rather than a fourth prefix-rewrite case. Confirmed called directly (not just through
    // a wrapper) in 9 of the 28 corpus projects — a broadly-applicable WP-core omission, not a
    // single theme's own convention. Real-world shape (Kadence):
    // `locate_template( 'template-parts/archive-title/hkb-searchbox' )`.
    private const TEMPLATE_FUNCS = ['get_template_part', 'get_header', 'get_footer', 'get_sidebar', 'locate_template'];
    // `<prefix>_get_template_part()`/`<prefix>_get_template()` — a widely-replicated WordPress-
    // ecosystem naming convention for a plugin/theme's own template-loader wrapper, not a single
    // plugin's own invention: WooCommerce's `wc_get_template_part()`/`wc_get_template()` (plus
    // its documented legacy aliases `woocommerce_get_template_part()`/`woocommerce_get_template()`)
    // and Sydney theme's own `sydney_get_template_part()` are two independently-confirmed
    // real-world instances of the exact same shape — both treat arg 0 as the template ref exactly
    // like WP core's own get_template_part(), and TemplateAnalyzer's existing partial-match logic
    // (get_template_part('slug', $name) => any "slug-*" file is reachable) already generalizes
    // correctly to the two-argument form with no further special-casing. A fixed name list would
    // only ever cover whichever specific plugins happened to get scanned and traced by hand;
    // matching the naming convention itself covers every current and future wrapper that follows
    // it, at the one-time cost of a slightly wider (but still narrow — this exact suffix, not a
    // bare "contains get_template") net. See isTemplateLoaderFunc()'s own docblock.
    private function isTemplateLoaderFunc(string $name): bool
    {
        return in_array($name, self::TEMPLATE_FUNCS, true)
            || str_ends_with($name, '_get_template_part')
            || str_ends_with($name, '_get_template');
    }
    // `if ( ! class_exists( 'My_Class' ) ) { class My_Class { ... } }` — an extremely common WP
    // redeclaration guard, self-referencing the very thing it's about to define. Without
    // excluding it, the guard's own string argument flows into the same generic name pool
    // FunctionAnalyzer's $called and ClassAnalyzer's class-reference fallback both trust,
    // permanently masking a genuinely-unused function/class as "used" purely because it checks
    // for its own prior existence — the opposite of a real usage signal. Confirmed in the wild:
    // GeneratePress's 8 deprecated Customizer control classes are every one of them wrapped in
    // exactly this guard.
    private const EXISTENCE_CHECK_FUNCS = ['class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'function_exists'];
    // `file_exists()` and `is_file()` are PHP-core predicates whose true branch establishes that
    // the exact candidate pathname can be loaded. LiteralPathPropagation permits a single
    // otherwise-unknown direct variable only when the same parameter-derived expression is
    // guarded by one of these — WPForms' Templates::locate() checks
    // `file_exists($template_path . $template_name)` before assigning that exact value to
    // `$located`. No project-defined predicate is trusted here.
    private const FILE_EXISTENCE_GUARD_FUNCS = ['file_exists', 'is_file'];
    private const INCLUDE_KEYWORDS = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
    // T_STRING: plain `Foo`. T_NAME_QUALIFIED: `Foo\Bar`. T_NAME_FULLY_QUALIFIED: `\Foo\Bar`.
    private const CLASS_NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
    // Method names conventionally used for a static-singleton-factory that returns an instance
    // of whichever class it's called on via late static binding (`new static()`/
    // `get_called_class()`) — see assignedStaticFactoryClassName().
    private const STATIC_FACTORY_METHOD_NAMES = ['cls', 'instance', 'getInstance', 'get_instance'];
    // Built-in/pseudo types that tokenize the same as a class name (T_STRING) in a type-hint
    // position — must be excluded so e.g. `int $x` doesn't get treated as a reference to a
    // class named "int". `array` and `callable` have their own dedicated tokens (T_ARRAY,
    // T_CALLABLE) so they never reach this list in the first place; "iterable" doesn't have a
    // dedicated token as of PHP 8.4, so it's listed here instead.
    private const PRIMITIVE_TYPE_NAMES = [
        'int', 'float', 'string', 'bool', 'object', 'iterable',
        'mixed', 'void', 'never', 'null', 'false', 'true',
    ];

    /**
     * Parses every file in order, reporting progress as it goes — the shared entry point every
     * analyzer's own `analyze()` uses instead of its own `array_map(fn($f) => $this->parser->
     * parse($f), $files)`, so a single change here (or a single progress-reporting mechanism)
     * covers all of them instead of five independent copies.
     *
     * @param list<string> $files
     * @param (callable(int, int): void)|null $onProgress Called after each file with (files
     *   parsed so far, total files) — e.g. to drive a progress bar. Never called when $files is
     *   empty.
     * @return list<ParseResult>
     */
    public function parseAll(array $files, ?callable $onProgress = null): array
    {
        $total = count($files);
        $results = [];
        foreach ($files as $index => $file) {
            $results[] = $this->parse($file);
            if ($onProgress !== null) {
                $onProgress($index + 1, $total);
            }
        }
        return $results;
    }

    public function parse(string $file): ParseResult
    {
        $code = file_get_contents($file);
        if ($code === false) {
            return $this->emptyResult($file, "Cannot read file: {$file}");
        }

        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $this->emptyResult($file, $e->getMessage());
        }

        $functionDefs = [];
        $functionCalls = [];
        $hookRegistrations = [];
        $hookInvocations = [];
        $templateRefs = [];
        $phpPathStrings = [];
        $classDefs = [];
        $classReferences = [];
        $scopedMethodCalls = [];
        $scopedMethodCallPrefixes = [];
        $reflectionDispatchedClassNames = [];
        // Class name => property name => the class assigned to it — see ParseResult's own
        // docblock. Deliberately flat/file-wide (not scoped to $classDepthStack the way
        // $varTypesStack is scoped to $functionDepthStack): a property set in one method and read
        // in another is exactly the case this exists to cover, so it must survive across the
        // whole class body, not just one method's.
        $propertyAssignedClasses = [];
        $propertyMethodCalls = [];
        $pendingReturnTypedCalls = [];
        $pendingDirectoryLoaderCalls = [];
        $traitUsages = [];
        // glob(__DIR__ . '/inc/*.php') + a foreach/require loop is a common WP bulk-include
        // pattern this tokenizer can't trace as dataflow (the require target is a plain loop
        // variable, fully dynamic). $globIncludeDirs records every directory a glob() call in
        // this file scans; $hasIncludeStatement records whether an include/require keyword
        // appears anywhere in it too — FileAnalyzer only trusts a glob'd directory as "reachable"
        // when both signals are present in the same file, a coarse but WP-idiomatic heuristic.
        $globIncludeDirs = [];
        $rootRelativeIncludeDirs = [];
        $hasIncludeStatement = false;
        $useImports = [];
        // Alias/bare-name => FQCN, from `use function Some\Namespace\name [as alias];`. Unlike
        // $useImports (which only ever disambiguates a class *reference*, never a function call's
        // own resolution), a matching entry here changes what a later BARE call actually resolves
        // to: PHP fixes a `use function`-imported name to its target at compile time, shadowing
        // the usual current-namespace-then-global runtime fallback entirely — see the two
        // extraCandidateFqcn computation sites below (T_STRING bare-call dispatch and the string-
        // callback shape) for where this gets consulted instead of the plain namespace-prefixed
        // guess.
        $useFunctionImports = [];
        // Bounded numeric for-loop variable tracking: `for ($i = 1; $i < 5; $i++) { ... }` — a
        // loop variable concatenated into a literal (`'prefix_' . $i`) can't be resolved to its N
        // concrete suffixes without knowing $i's own range (see parseForLoopBoundedRange/
        // resolveForLoopConcatenatedLiteral). Parallel brace-depth-tracked stacks, same push-on-
        // '{'/pop-on-'}' lockstep pattern used throughout this parser (see $expectingFunctionOpen
        // above); $expectingForLoopOpen only gets set when parseForLoopBoundedRange actually
        // recognized the clean canonical form, so an unrecognized/unbounded for-loop simply never
        // pushes anything — the loop variable then behaves exactly as before this mechanism
        // existed (unresolved, same as any other ordinary variable).
        $expectingForLoopOpen = false;
        $forLoopVarDepthStack = [];
        $forLoopVarNameStack = [];
        $forLoopVarValuesStack = [];
        $pendingForLoopVarName = null;
        $pendingForLoopVarValues = null;
        // `foreach ($conditionals as $conditional) { ... 'add_crumbs_' . substr($conditional, 3)
        // ... }` over a plain tracked literal-string array (real-world case: WooCommerce's
        // breadcrumb conditional dispatch, class-wc-breadcrumb.php) — the string-valued sibling
        // of the bounded-for-loop tracking above. Same push-on-'{'/pop-on-'}' lockstep pattern,
        // kept as separate stacks (rather than merged into the int-valued for-loop ones) since
        // the value type differs and only a plain "as $var" form (no key=>value, no
        // by-reference) is recognized — see parseForeachLiteralArrayLoop.
        $expectingForeachLoopOpen = false;
        $foreachLoopVarDepthStack = [];
        $foreachLoopVarNameStack = [];
        $foreachLoopVarValuesStack = [];
        $pendingForeachLoopVarName = null;
        $pendingForeachLoopVarValues = null;
        // CONST_NAME => trailing string literal from a `define('CONST_NAME', <expr>)` call in
        // this file — e.g. `define('ASTRA_HEADER_BUILDER_CONFIGS_DIR', ASTRA_THEME_DIR .
        // 'inc/.../configs/')` records 'inc/.../configs/'. Lets a later `scandir(CONST_NAME)`
        // resolve to that directory the same way a literal argument already would (see
        // $rootRelativeIncludeDirs above for why this needs project-root-relative resolution,
        // not calling-file-relative). File-scoped/flat, same trade-off as $arrayLiteralVars.
        $definedConstants = [];
        // Class name => constant name => literal string value, from a `const NAME = 'literal';`
        // declaration directly inside that class/interface/trait/enum body — populated live as
        // the file scans, same file-scoped/flat trade-off as $definedConstants above. Lets
        // do_action(self::HOOK_NAME) resolve the same way a literal directly in the call already
        // does (see parseClassConstants and classifyArgTokens's self::/static::CONST handling).
        $classConstants = [];
        // Token index => true, for a T_CONSTANT_ENCAPSED_STRING that's the sole argument of a
        // class_exists()/function_exists()/etc. call — computed ahead of that index (from the
        // EXISTENCE_CHECK_FUNCS branch below) and consulted once the main loop's own iteration
        // reaches it, so that one occurrence is excluded from the generic name pools.
        $skipStringIndices = [];

        $count = count($tokens);
        $line = 1;
        $skipNextString = false;

        // Class context tracking: push brace depth when a class body opens, pop when it closes.
        // $classNameStack and $classParentStack run in lockstep with $classDepthStack, tracking
        // which class (and its extends[0], for `parent::`) a method belongs to — null for
        // interface/trait/enum/anonymous-class bodies, which have no ClassDef. Used both for
        // contract (implements/extends) checks and for resolving $this->/self::/parent::/
        // static:: calls to a concrete receiver class below.
        $braceDepth = 0;
        $classDepthStack = [];
        $classNameStack = [];
        $classParentStack = [];
        $expectingClassOpen = false;
        $pendingClassName = null;
        $pendingClassParent = null;
        // String interpolation `{$var}` and `${var}` emit a STRING "}" token that closes the
        // interpolation but is NOT a code-level brace. Track depth so we skip those.
        $interpolationDepth = 0;

        // `if ( ! function_exists( 'some_name' ) ) { function some_name() {} }` — the real WP
        // polyfill convention. $functionExistsGuardDepthStack/$functionExistsGuardNameStack run
        // in lockstep (same push-on-'{'/pop-on-'}' pattern as $classDepthStack/$classNameStack),
        // recording the guarded name for whichever `{` immediately follows a recognized
        // `function_exists()` guard condition — consulted when a T_FUNCTION declaration's own
        // name matches the current top of the stack, see FunctionDef::$guarded.
        $functionExistsGuardDepthStack = [];
        $functionExistsGuardNameStack = [];
        $pendingFunctionExistsGuardName = null;

        // The file's current `namespace X;` declaration (bare form only — see resolveFqcn's own
        // doc comment for why the braced `namespace X { ... }` form is a deliberate non-goal).
        // Empty string means "no namespace declared" (or not yet seen), which is also the global
        // namespace — either way, resolveFqcn() reduces to the bare name in that case, so
        // un-namespaced code (the majority of real WP themes/plugins) sees zero behavior change.
        $currentNamespace = '';

        // Local variable type tracking: $var = new ClassName(...) is remembered for the rest of
        // that function/method's body, so $var->method() can be scoped the same way $this->
        // already is. $varTypesStack runs in lockstep with function-body brace depth (a fresh,
        // empty scope per function/closure — PHP variables don't leak into nested closures
        // without an explicit `use()`, and this parser doesn't attempt to track those either).
        $expectingFunctionOpen = false;
        $functionDepthStack = [];
        $varTypesStack = [[]];
        // $var = SomeFactory::make(); ... $var->method(); — same per-function scoping and
        // last-write-wins semantics as $varTypesStack, but for a call whose return type can only
        // be resolved once every file's parse is merged (see PendingReturnTypedCall). Mutually
        // exclusive with $varTypesStack for a given variable: an assignment always clears
        // whichever of the two it doesn't set.
        $varPendingCallStack = [[]];
        // Type-hinted parameters (`function foo(My_Class $x)`) seed the new scope's
        // $varTypesStack the same way `$x = new My_Class()` does — computed when T_FUNCTION is
        // seen, applied when its body's `{` actually opens the scope, mirroring the
        // $pendingClassName pattern above.
        $pendingParamTypes = [];

        // variable name => its literal array contents, see the $var = [...] tracking above and
        // the T_FOREACH handling below. Flat/file-wide like $globIncludeDirs, not scoped —
        // simplicity over precision, same trade-off made throughout this parser.
        $arrayLiteralVars = [];
        // Same shape as $arrayLiteralVars, but populated by parseAnyStringLiteralArray — which
        // has no path-specific "must contain a '/'" gate — so a plain array of non-path string
        // literals (e.g. WooCommerce's `$conditionals = array('is_home', 'is_404', ...)`,
        // dispatched via a `foreach`, not a file path) is still tracked for
        // parseForeachLiteralArrayLoop/resolveForeachConcatenatedLiteral. Kept as a separate map
        // rather than relaxing $arrayLiteralVars' own gate, since that gate is deliberately
        // narrow for the $phpPathStrings consumer (see parseStringLiteralArray's docblock).
        $anyArrayLiteralVars = [];

        // $varLiteralAssignmentsStack runs in lockstep with $functionDepthStack/$varTypesStack
        // (same push-on-'{'/pop-on-'}' pattern, keyed via $functionDefIndexForBodyStack below) —
        // together they let a `return` statement resolve to every literal a helper function or
        // method might hand back, e.g. `function ocean_single_post_header_template() { if (...) {
        // $p = 'a'; } elseif (...) { $p = 'b'; } return apply_filters('tag', $p); }` — real-world
        // example (OceanWP theme). $varLiteralAssignmentsStack *accumulates* every literal ever
        // assigned to a variable within the current function body (unlike $varTypesStack's
        // last-write-wins), since which conditional branch runs is exactly what can't be known
        // statically — the whole point is capturing every possibility a literal-only assignment
        // reveals, tolerating that a non-literal branch (a value this parser can't resolve at
        // all) is simply invisible rather than invalidating what *is* known.
        $varLiteralAssignmentsStack = [[]];

        // Per-function-body "does this function/method's own body (including nested closures)
        // contain an include/require-family keyword anywhere" tracking — same push-on-'{'/
        // pop-on-'}' lockstep as $functionDepthStack, but the *value* accumulates true instead of
        // being reset per scope, so a require inside a closure correctly marks every currently-
        // open enclosing scope too. $functionDefIndexForBodyStack pairs each open scope with its
        // FunctionDef's own index in $functionDefs (null for an anonymous closure, which has no
        // FunctionDef to update, or for a bodyless abstract/interface method signature, which
        // never opens a '{' at all and so never reaches this stack in the first place) so the
        // real value can be written back once the body actually closes — parseFunctionDef() runs,
        // and $def gets pushed, before any of its body has been scanned at all, so this can't be
        // known any earlier than that. See FunctionDef::$hasIncludeInBody's own docblock for what
        // this feeds.
        $functionHasIncludeStack = [];
        $functionDefIndexForBodyStack = [];
        $pendingFunctionDefIndex = null;
        // Same push-on-'{'/pop-on-'}' lockstep as $functionDefIndexForBodyStack, but tracks
        // whether the CURRENT scope is an inline closure passed as `array_map()`'s own first
        // argument (`array_map( function ( $x ) { ... }, $arr )`) — real-world shape (Botiga):
        // the closure body builds a dynamic function name from its own parameter, with no
        // enclosing class to scope $classNameTransformTemplates by (see
        // $functionNameTransformTemplates' own docblock on ParseResult). Computed once at
        // T_FUNCTION (walking backward past an optional `static` to check for `array_map(`
        // immediately before), since nothing later can tell an anonymous closure's own call
        // context apart from any other.
        $functionIsArrayMapClosureStack = [];
        $pendingFunctionIsArrayMapClosure = false;
        // $hook = 'my_plugin_loaded'; do_action($hook); — last-write-wins (unlike
        // $varLiteralAssignmentsStack's accumulation above), the same semantics as $varTypesStack
        // but for a string value instead of a class name: a hook/template-part call site cares
        // what the variable's value actually *is* right there, not every literal it was ever set
        // to across every branch. Consulted by classifyArgTokens() (via extractStringArgAt()) so
        // a bare-variable hook tag or template-part slug resolves the same way a literal already
        // does, instead of coming back fully dynamic.
        $varLiteralValueStack = [[]];
        // function name => every literal a `return` statement inside its body resolved to (see
        // the T_RETURN handling below). Flat/file-wide — merged with every other scanned file's
        // own copy by whichever analyzer consumes it, since the helper and its caller are
        // routinely in different files (as in the OceanWP example above).
        $functionLiteralReturns = [];
        // Same key convention as $functionLiteralReturns, but for a function/method that returns
        // a flat literal array (`$var = array('lit1', 'lit2', ...); return $var;` or
        // `return apply_filters('tag', $var, ...);`) rather than a single scalar — see
        // resolveReturnArrayLiterals()'s own docblock and $functionNameTransformTemplates below
        // for the real-world shape (Botiga) this feeds.
        $functionArrayReturns = [];
        // Flat, project-wide list of [literal function-name prefix, transform steps] pairs — the
        // procedural (no enclosing class) counterpart to $classNameTransformTemplates, feeding
        // FunctionAnalyzer instead of Class/FileAnalyzer. See the T_RETURN handling below for the
        // real-world shape (Botiga) and why this has no per-function "owner" to scope by the way
        // $classNameTransformTemplates does.
        $functionNameTransformTemplates = [];
        /** @var list<PendingTemplateHelperCall> $pendingTemplateHelperCalls */
        $pendingTemplateHelperCalls = [];
        // "Class::method" (or bare function name) => every literal suffix a `return <ignored> .
        // $param . 'suffix';` statement inside its body resolved to — see
        // resolveReturnParamSuffixTemplate's own docblock. Same flat/file-wide merge-later
        // treatment as $functionLiteralReturns, just keyed to also cover methods (a bare function
        // name alone can't disambiguate two unrelated classes' same-named helper).
        $functionParamSuffixReturns = [];
        /** @var list<PendingParamSuffixCall> $pendingParamSuffixCalls */
        $pendingParamSuffixCalls = [];
        /** @var list<LiteralPathPropagationLink> $literalPathPropagationLinks */
        $literalPathPropagationLinks = [];
        /** @var list<LiteralPathInput> $literalPathInputs */
        $literalPathInputs = [];
        /** @var array<string,true> $literalPathFileExistenceGuards */
        $literalPathFileExistenceGuards = [];
        // Each named wrapper scope maps its currently-known local values to graph nodes. A
        // reassignment creates a fresh node, so `$path = root() . '/x/' . $path . '.php'` keeps
        // the parameter's incoming value distinct from the constructed output path. WPForms'
        // `include_html()` and Blocksy's two loaders are the real-world shapes that need this.
        /** @var list<array<string,string>> $literalPathNodeStack */
        $literalPathNodeStack = [[]];
        /** @var list<?string> $literalPathFunctionKeyStack */
        $literalPathFunctionKeyStack = [null];
        /** @var array<string,string> $pendingLiteralPathNodes */
        $pendingLiteralPathNodes = [];
        $pendingLiteralPathFunctionKey = null;
        $literalPathNodeCounter = 0;
        // "Class::method" => every literal suffix a same-class self-dispatch
        // (`call_user_func([$this, "{$param}_suffix"])`) inside its own body resolved to — see
        // isThisArrayCallbackReceiverAt/extractTrailingVarSuffix. Same flat/file-wide merge-later
        // treatment as $functionParamSuffixReturns.
        $selfDispatchSuffixes = [];
        /** @var list<PendingSelfDispatchCall> $pendingSelfDispatchCalls */
        $pendingSelfDispatchCalls = [];
        // "Class::method" => every [prefix, suffix] pair a same-class self-dispatch
        // (`array($this, 'prefix' . $param . 'suffix')`) inside its own body resolved to — the
        // plain-concatenation counterpart to $selfDispatchSuffixes above, which only has evidence
        // for a suffix-only (no prefix) interpolated shape. See
        // resolvePrefixVarSuffixSelfDispatchTemplate's own docblock. Consumed differently:
        // there's no literal call-site argument to pair it with (WooCommerce's real dispatcher is
        // invoked by WP core's own list-table rendering loop at runtime, never by a literal call
        // site in the codebase) — see $classArrayKeyLiterals below for the actual domain source.
        $selfDispatchPrefixSuffixTemplates = [];
        // Owner class => every literal string array-key ever assigned via `$anyLocalVar['literal']
        // = ...;` anywhere in the class's own methods — real-world shape (WooCommerce):
        // `define_columns()` builds `$show_columns['thumb'] = ...; $show_columns['name'] = ...;`
        // (several gated behind conditionals), later consumed by a `render_{$column}_column`-
        // style dispatcher method elsewhere in the same class. Deliberately class-wide rather than
        // scoped to one variable name or method — the "coarse net" trade-off this parser already
        // makes throughout: correlating this with $selfDispatchPrefixSuffixTemplates' own owner
        // class in ClassAnalyzer is the actual precision-narrowing step.
        $classArrayKeyLiterals = [];
        // Owner class => property name => literal value, for a class-body property declared with
        // a single plain string-literal default (`protected $view_file_extension = '.php';`).
        // Feeds the literal-path mechanism's property-node support (see
        // $literalPathTrackedPropertyNodes' own declaration comment) as a compile-time-constant
        // term wherever `$this->propName` appears in a path concatenation and was never
        // reassigned to anything this parser tracks — real-world shape (Wordfence's `wfView`):
        // the fixed `.php` extension `render()` appends is this exact convention, never a
        // constructor parameter.
        $literalPathPropertyLiteralDefaults = [];
        // Owner class => property name => true, once `$this->propName = <expr>;` resolved to a
        // real literal-path source (a wrapper parameter, another property, ...) somewhere in this
        // same file — see captureLiteralPathPropertyAssignment()'s own docblock. Deliberately
        // whole-file-scoped (like $classArrayKeyLiterals), not per-method: real-world shape
        // (Wordfence's `wfView`), the constructor sets `$this->view = $view` and a *different*
        // method (`render()`) later reads `$this->view` — single-pass parsing means this only
        // resolves when the assignment is parsed *before* the read, i.e. only when the writer
        // appears earlier in the same file than the reader (true for every real case seen so
        // far: constructors are conventionally declared before the methods that consume what
        // they set). A property with no entry here is never treated as a literal-path node at
        // all — see literalPathNodeFromTokens()'s own `$this->` case.
        $literalPathTrackedPropertyNodes = [];
        // variable name => [ultimate source variable, transform steps] when it was last assigned
        // a recognized str_replace()/ucfirst()/ucwords() transform chain — see
        // resolveTransformChainExpr()'s own docblock for the shapes this covers and the two
        // independent real-world idioms that motivated generalizing it beyond one hardcoded
        // chain. Flat/file-wide, same trade-off as $arrayLiteralVars: the concatenation that
        // consumes it is almost always the very next statement (or, for a chain spanning several
        // statements, the same variable reassigned from itself).
        $transformChainVars = [];
        // Owner class => every [literal namespace prefix (ending in `\`), transform steps] pair
        // where that prefix was concatenated with a $transformChainVars-marked variable — see
        // $classNameTransformTemplates' own docblock on ParseResult for the real-world shape and
        // how ClassAnalyzer/FileAnalyzer resolve it.
        $classNameTransformTemplates = [];
        // Function/method key => which of its own declared parameter positions is passed
        // *unchanged* (no transform, no concatenation) as the hook-tag argument to an
        // already-recognized CRON_SCHEDULE_FUNCS/HOOK_INVOKE_FUNCS call inside its own body.
        // Real-world shape (WooCommerce): `schedule_variation_summary_regeneration( $action_name,
        // ... ) { as_schedule_single_action( $timestamp, $action_name, $args, $group ); }` — the
        // hook fires later inside Action Scheduler, never via a literal argument visible at this
        // call site. Resolved in HookAnalyzer against $literalPathInputs (already populated for
        // every call site, not just file-related ones) — a caller's literal argument at the
        // recorded position is exactly as good as passing that literal to the sink directly.
        $hookPassThroughParams = [];
        // A closure has no stable "Class::method" key the way a named function/method does, so
        // its own hook-pass-through param can't be merged cross-file the way
        // $hookPassThroughParams is — but it doesn't need to be: `$schedule = function ($date,
        // $hook) { as_schedule_single_action($t, $hook, ...); }; $schedule($d, 'literal');` is
        // always local to the same enclosing function (a closure has no way to leak its
        // identity to another file). Real-world shape (WooCommerce,
        // wc-product-functions.php:575-611). Tracked entirely inline, resolved the moment the
        // closure's own call site is reached — no merge-later Pending* struct needed.
        // $closureParamNamesStack/$closureVarNameStack run in lockstep with
        // $closureVarDepthStack (brace-depth-tracked, same push-on-'{'/pop-on-'}' pattern as
        // $forLoopVarDepthStack) — the closure IS the new scope, so its own param names are
        // only visible from directly inside its body, same as any other function's parameters.
        // $closureHookPassThroughParamStack is scoped to the *enclosing* function (lockstep with
        // $functionDepthStack) since that's the variable's own real scope.
        $expectingClosureOpen = false;
        $closureVarDepthStack = [];
        $closureVarNameStack = [];
        /** @var list<list<string>> $closureParamNamesStack */
        $closureParamNamesStack = [];
        $pendingClosureVarName = null;
        /** @var list<string> $pendingClosureParamNames */
        $pendingClosureParamNames = [];
        /** @var list<array<string,int>> $closureHookPassThroughParamStack */
        $closureHookPassThroughParamStack = [[]];
        // $var = helper_fn(); ... get_template_part( $var ); — one more level of indirection than
        // the direct `get_template_part( helper_fn() )` shape above: real-world example (OceanWP
        // theme) `$template_part = ocean_single_post_header_meta_template(); get_template_part(
        // $template_part );`. Runs in lockstep with $varTypesStack/$functionDepthStack, last-
        // write-wins like $varTypesStack (not accumulated like $varLiteralAssignmentsStack) — a
        // variable holds the result of one call at a time, there's no multi-branch possibility to
        // preserve the way there is for a literal assigned across several conditional branches.
        $varAssignedFromFunctionStack = [[]];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $braceDepth++;
                    if ($expectingClassOpen) {
                        $classDepthStack[] = $braceDepth;
                        $classNameStack[] = $pendingClassName;
                        $classParentStack[] = $pendingClassParent;
                        $expectingClassOpen = false;
                        $pendingClassName = null;
                        $pendingClassParent = null;
                    }
                    if ($expectingFunctionOpen) {
                        $functionDepthStack[] = $braceDepth;
                        $varTypesStack[] = $pendingParamTypes;
                        $varPendingCallStack[] = [];
                        $varLiteralAssignmentsStack[] = [];
                        $varLiteralValueStack[] = [];
                        $varAssignedFromFunctionStack[] = [];
                        $literalPathNodeStack[] = $pendingLiteralPathNodes;
                        $literalPathFunctionKeyStack[] = $pendingLiteralPathFunctionKey;
                        $functionHasIncludeStack[] = false;
                        $functionDefIndexForBodyStack[] = $pendingFunctionDefIndex;
                        $functionIsArrayMapClosureStack[] = $pendingFunctionIsArrayMapClosure;
                        // Deliberately NOT pushed when this scope-open is itself a closure
                        // (`$expectingClosureOpen` also true right now) — a closure's own body
                        // must resolve its hook pass-through into the *enclosing* scope's frame
                        // (where the variable it's assigned to actually lives and gets called),
                        // not a fresh frame that gets discarded the moment the closure's own `}`
                        // pops it. $closureVarDepthStack/etc below still track the closure's own
                        // parameter names separately; this stack only ever represents "the
                        // nearest enclosing *named* function/method scope."
                        if (!$expectingClosureOpen) {
                            $closureHookPassThroughParamStack[] = [];
                        }
                        $expectingFunctionOpen = false;
                        $pendingParamTypes = [];
                        $pendingFunctionDefIndex = null;
                        $pendingFunctionIsArrayMapClosure = false;
                        $pendingLiteralPathNodes = [];
                        $pendingLiteralPathFunctionKey = null;
                    }
                    if ($expectingClosureOpen) {
                        $closureVarDepthStack[] = $braceDepth;
                        $closureVarNameStack[] = $pendingClosureVarName ?? '';
                        $closureParamNamesStack[] = $pendingClosureParamNames;
                        $expectingClosureOpen = false;
                        $pendingClosureVarName = null;
                        $pendingClosureParamNames = [];
                    }
                    if ($pendingFunctionExistsGuardName !== null) {
                        $functionExistsGuardDepthStack[] = $braceDepth;
                        $functionExistsGuardNameStack[] = $pendingFunctionExistsGuardName;
                        $pendingFunctionExistsGuardName = null;
                    }
                    if ($expectingForLoopOpen) {
                        $forLoopVarDepthStack[] = $braceDepth;
                        // The `?? ''`/`?? []` fallbacks are unreachable in practice —
                        // $expectingForLoopOpen is only ever set true in the same branch that
                        // sets both pending values from a non-null parseForLoopBoundedRange()
                        // result — but keep the stacks' own element type non-nullable (unlike
                        // $functionDefIndexForBodyStack just above, where null is itself a
                        // meaningful value) since nothing downstream ever wants to match a
                        // tracked loop variable's name against null.
                        $forLoopVarNameStack[] = $pendingForLoopVarName ?? '';
                        $forLoopVarValuesStack[] = $pendingForLoopVarValues ?? [];
                        $expectingForLoopOpen = false;
                        $pendingForLoopVarName = null;
                        $pendingForLoopVarValues = null;
                    }
                    if ($expectingForeachLoopOpen) {
                        $foreachLoopVarDepthStack[] = $braceDepth;
                        $foreachLoopVarNameStack[] = $pendingForeachLoopVarName ?? '';
                        $foreachLoopVarValuesStack[] = $pendingForeachLoopVarValues ?? [];
                        $expectingForeachLoopOpen = false;
                        $pendingForeachLoopVarName = null;
                        $pendingForeachLoopVarValues = null;
                    }
                } elseif ($token === '}') {
                    if ($interpolationDepth > 0) {
                        $interpolationDepth--;
                    } else {
                        if (!empty($classDepthStack) && end($classDepthStack) === $braceDepth) {
                            array_pop($classDepthStack);
                            array_pop($classNameStack);
                            array_pop($classParentStack);
                        }
                        if (!empty($functionDepthStack) && end($functionDepthStack) === $braceDepth) {
                            array_pop($functionDepthStack);
                            array_pop($varTypesStack);
                            array_pop($varPendingCallStack);
                            array_pop($varLiteralAssignmentsStack);
                            array_pop($varLiteralValueStack);
                            array_pop($varAssignedFromFunctionStack);
                            array_pop($literalPathNodeStack);
                            array_pop($literalPathFunctionKeyStack);
                            // Mirrors the push side's own guard: a closure's own scope-close
                            // never had a frame of its own pushed here, so it must not pop one
                            // either — that would incorrectly discard the *enclosing* scope's
                            // frame instead.
                            $isClosureScopeClose = !empty($closureVarDepthStack) && end($closureVarDepthStack) === $braceDepth;
                            if (!$isClosureScopeClose) {
                                array_pop($closureHookPassThroughParamStack);
                            }
                            $hasInclude = array_pop($functionHasIncludeStack);
                            $defIndex = array_pop($functionDefIndexForBodyStack);
                            array_pop($functionIsArrayMapClosureStack);
                            if ($hasInclude && $defIndex !== null) {
                                $d = $functionDefs[$defIndex];
                                $functionDefs[$defIndex] = new FunctionDef(
                                    $d->name,
                                    $d->line,
                                    $d->file,
                                    $d->isMethod,
                                    $d->ownerClass,
                                    $d->returnType,
                                    guarded: $d->guarded,
                                    fqcn: $d->fqcn,
                                    hasIncludeInBody: true,
                                    parameters: $d->parameters,
                                );
                            }
                        }
                        if (!empty($functionExistsGuardDepthStack) && end($functionExistsGuardDepthStack) === $braceDepth) {
                            array_pop($functionExistsGuardDepthStack);
                            array_pop($functionExistsGuardNameStack);
                        }
                        if (!empty($forLoopVarDepthStack) && end($forLoopVarDepthStack) === $braceDepth) {
                            array_pop($forLoopVarDepthStack);
                            array_pop($forLoopVarNameStack);
                            array_pop($forLoopVarValuesStack);
                        }
                        if (!empty($foreachLoopVarDepthStack) && end($foreachLoopVarDepthStack) === $braceDepth) {
                            array_pop($foreachLoopVarDepthStack);
                            array_pop($foreachLoopVarNameStack);
                            array_pop($foreachLoopVarValuesStack);
                        }
                        if (!empty($closureVarDepthStack) && end($closureVarDepthStack) === $braceDepth) {
                            array_pop($closureVarDepthStack);
                            array_pop($closureVarNameStack);
                            array_pop($closureParamNamesStack);
                        }
                        $braceDepth--;
                    }
                } elseif ($token === '&' && $skipNextString) {
                    // & in "function &foo()" — skip following function name too
                } elseif ($token === ';') {
                    // Abstract/interface method declarations end in `;`, never open a body —
                    // clear a pending function-scope push so it doesn't wrongly latch onto
                    // whatever brace comes next (e.g. a sibling method's own body).
                    $expectingFunctionOpen = false;
                } elseif ($token === '"') {
                    // A bare '"' token (rather than one whole T_CONSTANT_ENCAPSED_STRING) means
                    // this double-quoted string is genuinely interpolated — see
                    // resolveInterpolatedLoopSuffixPath()'s own docblock for the real-world shape
                    // this covers (Astra theme's numbered icon files) and why it's deliberately
                    // narrow. A null result means the shape wasn't recognized — leave $i
                    // untouched so the token loop keeps walking through it one token at a time,
                    // exactly as it already did before this mechanism existed (including
                    // $interpolationDepth's own brace-depth-safety tracking below). Tried against
                    // the bounded numeric for-loop domain first, then the string-array foreach/
                    // collect()->each() domain (Sage theme's own `"app/{$file}.php"` over
                    // ['setup', 'filters']) — a project would need the same interpolated variable
                    // name bound by both loop shapes at once for this order to matter, no
                    // evidence of that ever happening.
                    $interpResult = $this->resolveInterpolatedLoopSuffixPath($tokens, $i, $forLoopVarNameStack, $forLoopVarValuesStack)
                        ?? $this->resolveInterpolatedLoopSuffixPath($tokens, $i, $foreachLoopVarNameStack, $foreachLoopVarValuesStack);
                    if ($interpResult !== null) {
                        [$enumeratedPaths, $lastInterpIndex] = $interpResult;
                        foreach ($enumeratedPaths as $path) {
                            $phpPathStrings[] = $path;
                        }
                        $i = $lastInterpIndex;
                    } else {
                        // `call_user_func( [ $this, "{$section}_section" ] )` — a same-class
                        // self-dispatch method whose real target name is built from its own
                        // parameter. Real-world shape (Sydney theme): see
                        // PendingSelfDispatchCall's own docblock. Recorded against the CURRENT
                        // enclosing method (this string is defining the dispatcher's own
                        // capability, not resolving a call site).
                        $collected = $this->collectInterpolatedStringSegments($tokens, $i);
                        if ($collected !== null) {
                            [$segments, $closingQuoteIndex] = $collected;
                            if ($this->isThisArrayCallbackReceiverAt($tokens, $i, $closingQuoteIndex)) {
                                $suffix = $this->extractTrailingVarSuffix($segments);
                                if ($suffix !== null) {
                                    $currentDefIndex = empty($functionDefIndexForBodyStack) ? null : end($functionDefIndexForBodyStack);
                                    if ($currentDefIndex !== null) {
                                        $currentDef = $functionDefs[$currentDefIndex];
                                        if ($currentDef->isMethod && $currentDef->ownerClass !== null) {
                                            $suffixKey = $currentDef->ownerClass . '::' . $currentDef->name;
                                            if (!isset($selfDispatchSuffixes[$suffixKey])) {
                                                $selfDispatchSuffixes[$suffixKey] = [];
                                            }
                                            $selfDispatchSuffixes[$suffixKey][] = $suffix;
                                        }
                                    }
                                }
                            }
                        }

                        // "Yoast\WP\SEO\Presenters\\{$presenter}_Presenter" — a curly-brace
                        // interpolated variable with both a prefix and a suffix. Unlike the
                        // concatenation shape literalConcatVarAt() recognizes, this doesn't
                        // require the variable to have gone through any recognized transform
                        // first: WordPress SEO's own real code uses the bare closure parameter
                        // completely unmodified — the simplest possible shape, a config value
                        // used exactly as-is to build a class name. $transformChainVars is still
                        // consulted first (falling through to a zero-step identity when the
                        // variable was never tracked there), so one that *was* transformed still
                        // resolves correctly too. See $classNameTransformTemplates' own docblock
                        // for how ClassAnalyzer/FileAnalyzer replay the (possibly empty) steps.
                        $interpolatedSuffix = $this->interpolatedPrefixCurlyVarSuffixAt($tokens, $i);
                        if ($interpolatedSuffix !== null) {
                            [$interpSuffixPrefix, $interpSuffixVarName, $interpSuffixSuffix] = $interpolatedSuffix;
                            $interpSuffixOwnerClass = empty($classNameStack) ? null : end($classNameStack);
                            if (
                                $interpSuffixOwnerClass !== null
                                && (str_ends_with($interpSuffixPrefix, '\\') || preg_match('/^[A-Z][A-Za-z0-9_]*_$/', $interpSuffixPrefix) === 1)
                            ) {
                                $interpSuffixChain = $transformChainVars[$interpSuffixVarName] ?? [$interpSuffixVarName, []];
                                if (!isset($classNameTransformTemplates[$interpSuffixOwnerClass])) {
                                    $classNameTransformTemplates[$interpSuffixOwnerClass] = [];
                                }
                                $classNameTransformTemplates[$interpSuffixOwnerClass][] = [$interpSuffixPrefix, $interpSuffixChain[1], $interpSuffixSuffix];
                            }
                        }
                    }
                }
                continue;
            }

            [$type, $value, $tokenLine] = $token;
            $line = $tokenLine;

            if ($type === T_WHITESPACE) {
                continue;
            }

            // T_CURLY_OPEN ({$var}) and T_DOLLAR_OPEN_CURLY_BRACES (${var}) start a string
            // interpolation block closed by the next STRING "}" — don't count that } as a
            // code-level brace.
            if ($type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interpolationDepth++;
                continue;
            }

            if ($type === T_CLASS) {
                // T_CLASS shows up in three unrelated shapes: `class Foo {}` (a real
                // declaration — next token is the name), `Foo::class` (a class-const
                // reference — no name follows; "Foo" was already captured as a reference when
                // we processed that T_STRING, see below), and `new class {}` (anonymous — no
                // name, but it does open a body). Only declarations and anonymous classes open
                // a brace context; only declarations get a ClassDef.
                if ($this->nextMeaningfulIsIdentifier($tokens, $i)) {
                    $expectingClassOpen = true;
                    $def = $this->parseClassDef($tokens, $i, $file, 'class', $currentNamespace, $useImports);
                    if ($def !== null) {
                        $classDefs[] = $def;
                        $pendingClassName = $def->fqcn;
                        $pendingClassParent = $def->extends[0]->fqcn ?? null;
                    }
                } else {
                    $expectingClassOpen = $this->isPrecededByNew($tokens, $i);
                }
                continue;
            }

            if ($type === T_INTERFACE || $type === T_TRAIT || $type === T_ENUM) {
                // $pendingClassName IS set to the declaration's own name for all three, same as
                // T_CLASS below — every one of them opens a body whose methods need a real
                // ownerClass, not null:
                //  - trait: $this->method() calls made *within* the trait's own methods resolve
                //    precisely to that name (ScopedMethodCall) instead of silently falling
                //    through to the unscoped pool. That alone would make a trait method that's
                //    only ever called via $this-> from the consuming class (not the trait
                //    itself) look unused — a trait's methods are never called on the trait
                //    directly, only through whatever class `use`s it. ClassAnalyzer closes that
                //    gap using $traitUsages below (every `use TraitName;` paired with its
                //    enclosing class/trait) to widen a trait method's "used" check to every class
                //    that use()s it, transitively.
                //  - interface: method declarations have no body, but still parse as a
                //    FunctionDef (parseFunctionDef doesn't require a `{` to follow) — leaving
                //    ownerClass null made isContractMethod() short-circuit before ever checking
                //    whether the interface itself satisfies some other contract, and made every
                //    interface method universally "unused" since nothing could ever scope-match
                //    it. Now a call through a variable typed exactly as the interface (a
                //    type-hinted param, `Foo $x`) resolves to ScopedMethodCall($interfaceName,
                //    method) and correctly matches the declaration's own ownerClass. Concrete
                //    implementers still need their own separate call site to be seen as used —
                //    this doesn't (and can't, from tokens alone) credit every class that
                //    `implements` it.
                //  - enum: same reasoning as interfaces, for methods with real bodies (backed
                //    enums implementing e.g. JsonSerializable are common) — ownerClass null was
                //    blocking the interface-contract exemption from ever applying to them.
                $expectingClassOpen = true;
                $kind = match ($type) {
                    T_INTERFACE => 'interface',
                    T_TRAIT => 'trait',
                    default => 'enum',
                };
                $def = $this->parseClassDef($tokens, $i, $file, $kind, $currentNamespace, $useImports);
                if ($def !== null) {
                    $classDefs[] = $def;
                    $pendingClassName = $def->fqcn;
                }
                continue;
            }

            if ($type === T_IF) {
                // if ( ! function_exists( 'some_name' ) ) { ... } — sets $pendingFunctionExists
                // GuardName, consumed by the very next '{' this loop reaches (see the T_IF/'{'
                // push logic above). Deliberately narrow: only this exact shape (an optional
                // leading '!', a bare function_exists() call with a single string-literal
                // argument, nothing else combined via && / ||) is trusted — "don't guess" is the
                // same stance the rest of this parser takes everywhere else.
                $pendingFunctionExistsGuardName = $this->functionExistsGuardName($tokens, $i);
                continue;
            }

            if ($type === T_NAMESPACE) {
                // `namespace Foo\Bar;` — sets $currentNamespace for the rest of the file (or
                // until the next namespace statement; real-world code has at most one). The
                // braced form (`namespace Foo { ... }`) is a deliberate non-goal — zero
                // occurrences across a real 7-plugin, 8,651-file corpus justified not tracking
                // brace-scoped namespace resets; a file using it will have subsequent code
                // resolve as if still in the previous/global namespace.
                $nameIndex = $this->peekNextMeaningfulIndex($tokens, $i);
                $currentNamespace = ($nameIndex !== null && is_array($tokens[$nameIndex]) && in_array($tokens[$nameIndex][0], self::CLASS_NAME_TOKENS, true))
                    ? ltrim($tokens[$nameIndex][1], '\\')
                    : '';
                continue;
            }

            if ($type === T_USE && empty($classDepthStack) && $this->peekNextMeaningful($tokens, $i) !== '(') {
                // File-level `use Some\Namespace\Name [as Alias];` import — distinguished from a
                // closure's `function() use ($v)` by that guard (a closure's `use` is always
                // immediately followed by `(`) and from the in-class-body trait `use` below by
                // $classDepthStack being empty here. Recorded so VendorClassReflector can resolve
                // an extends/implements short name (PhpTokenParser only ever keeps short names,
                // see shortClassName) back to a real, autoloadable vendor class.
                [$classImports, $functionImports] = $this->parseUseImports($tokens, $i);
                foreach ($classImports as $alias => $fqcn) {
                    $useImports[$alias] = $fqcn;
                }
                foreach ($functionImports as $alias => $fqcn) {
                    $useFunctionImports[$alias] = $fqcn;
                }
                continue;
            }

            if ($type === T_USE && !empty($classDepthStack) && end($classDepthStack) === $braceDepth) {
                // `use TraitName;` directly inside a class/trait/enum body — a trait reference,
                // not the file-level `use Some\Namespace\Class;` import (guarded out: that's at
                // $braceDepth 0, outside any class body) nor a closure's `function() use ($v)`
                // (guarded out: that's nested inside a method body, one or more braces deeper
                // than the class body's own depth).
                $user = empty($classNameStack) ? null : end($classNameStack);
                foreach ($this->captureClassNameList($tokens, $i, $currentNamespace, $useImports) as $ref) {
                    $classReferences[] = $ref->short;
                    if ($user !== null) {
                        $traitUsages[] = new TraitUsage($user, $ref->fqcn);
                    }
                }
                continue;
            }

            if ($type === T_CONST && !empty($classDepthStack) && end($classDepthStack) === $braceDepth) {
                // const NAME = 'literal', OTHER = 'literal2'; directly inside a class/interface/
                // trait/enum body — same depth guard as the trait `use` case above, so a local
                // `const` inside a method body (impossible in real PHP, but this is a token
                // parser with no semantic validation) or a file-level `use function`/`use const`
                // import doesn't get mistaken for one.
                $owner = empty($classNameStack) ? null : end($classNameStack);
                if ($owner !== null) {
                    foreach ($this->parseClassConstants($tokens, $i) as $constName => $constValue) {
                        $classConstants[$owner][$constName] = $constValue;
                    }
                }
                continue;
            }

            if (
                in_array($type, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)
                && !empty($classDepthStack) && end($classDepthStack) === $braceDepth
            ) {
                // private static $talaveras = array( 'rectangular', 'square', ... ); — a
                // class-body property declaration with a flat literal array default, directly
                // inside a class body (same depth guard as the T_CONST case above). Real-world
                // shape (Jetpack tiled-gallery): feeds the same class-wide literal pool
                // $classArrayKeyLiterals already collects keys/values into (see its own
                // docblock) — later transformed into a dynamic class name by a sibling method.
                // Tolerates an optional `static` modifier in either order this parser has
                // evidence for; doesn't attempt a multi-property one-liner
                // (`private $a = [...], $b = [...];`) — no evidence for that shape yet.
                $owner = empty($classNameStack) ? null : end($classNameStack);
                if ($owner !== null) {
                    $propIndex = $this->peekNextMeaningfulIndex($tokens, $i);
                    if ($propIndex !== null && is_array($tokens[$propIndex]) && $tokens[$propIndex][0] === T_STATIC) {
                        $propIndex = $this->peekNextMeaningfulIndex($tokens, $propIndex);
                    }
                    if ($propIndex !== null && is_array($tokens[$propIndex]) && $tokens[$propIndex][0] === T_VARIABLE) {
                        $eqIndex = $this->peekNextMeaningfulIndex($tokens, $propIndex);
                        if ($eqIndex !== null && $tokens[$eqIndex] === '=') {
                            $propArrayValues = $this->parseAnyStringLiteralArray($tokens, $eqIndex);
                            if ($propArrayValues !== null) {
                                if (!isset($classArrayKeyLiterals[$owner])) {
                                    $classArrayKeyLiterals[$owner] = [];
                                }
                                array_push($classArrayKeyLiterals[$owner], ...$propArrayValues);
                            }
                            // protected $view_file_extension = '.php'; — the scalar-default
                            // sibling of the array-default case just above. Real-world shape
                            // (Wordfence's `wfView`): render() appends this fixed extension to a
                            // dynamically-named view file; see
                            // $literalPathPropertyLiteralDefaults' own declaration comment.
                            $propLiteralDefault = $this->singleStringLiteralRhs($tokens, $eqIndex);
                            if ($propLiteralDefault !== null) {
                                $literalPathPropertyLiteralDefaults[$owner][substr($tokens[$propIndex][1], 1)] = $propLiteralDefault;
                            }
                        }
                    }
                }
                continue;
            }

            if ($type === T_NEW || $type === T_INSTANCEOF) {
                $ref = $this->captureClassNameAfter($tokens, $i, $currentNamespace, $useImports);
                if ($ref !== null) {
                    $classReferences[] = $ref;
                    if ($type === T_NEW) {
                        // `( new Export() )->register_route(...)` — the inline, wrapped-`new`
                        // counterpart to `$var = new ClassName(); $var->method();` above; see
                        // findInlineNewChainedCallTarget's own doc comment.
                        $chainTarget = $this->findInlineNewChainedCallTarget($tokens, $i, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                        if ($chainTarget !== null) {
                            [$receiverFqcn, $methodName, $methodNameIndex] = $chainTarget;
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverFqcn, $methodName);
                            $i = $methodNameIndex;
                        }
                    }
                }
                if ($type === T_NEW) {
                    // `return new self( $view, $data );` — a static factory forwarding its own
                    // argument into the constructor it wraps, no assignment at all (unlike
                    // assignedNewClassName()'s `$var = new ClassName(...)` shape). Real-world
                    // shape (Wordfence's `wfView::create()`): the constructor stores that
                    // argument into a property (see captureLiteralPathPropertyAssignment()),
                    // later read back by a *different* method's own sink. Treated exactly like
                    // any other named/scoped wrapper call site — captureLiteralPathCall() doesn't
                    // care that the "call" is a `new` expression rather than a plain invocation,
                    // only that $nameIndex is immediately followed by `(`.
                    $newClassName = $this->resolveNewExpressionClassNameAt($tokens, $i, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                    if ($newClassName !== null) {
                        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $i);
                        if ($nameIndex !== null) {
                            $literalPathScopeTop = count($literalPathNodeStack) - 1;
                            $this->captureLiteralPathCall(
                                [$newClassName . '::__construct'],
                                $tokens,
                                $nameIndex,
                                $literalPathFunctionKeyStack[$literalPathScopeTop],
                                $literalPathNodeStack[$literalPathScopeTop],
                                false,
                                $literalPathPropagationLinks,
                                $literalPathInputs,
                            );
                        }
                    }
                }
                continue;
            }

            // `for ($i = 1; $i < 5; $i++) { ... }` — see parseForLoopBoundedRange's own docblock
            // for exactly which shapes are recognized. Only sets $expectingForLoopOpen (the push
            // itself happens at the loop's own next '{', mirroring $expectingFunctionOpen) when
            // the canonical bounded-ascending form is confirmed; anything else leaves the loop
            // variable untracked, same as before this mechanism existed.
            if ($type === T_FOR) {
                $forResult = $this->parseForLoopBoundedRange($tokens, $i);
                if ($forResult !== null) {
                    [$pendingForLoopVarName, $pendingForLoopVarValues] = $forResult;
                    $expectingForLoopOpen = true;
                }
                continue;
            }

            // foreach ($ability_files as $file) { ... require ...$file... ...; } — a bulk-include
            // driven by a plain array-of-literals instead of glob()/scandir(). Real-world example
            // (Astra theme): a 64-entry array of relative path fragments, each require_once'd in
            // turn. Doesn't verify the loop body actually contains an include/require — same
            // "cheap net" trade-off as the plain .php-suffixed-string-literal case just below
            // (T_CONSTANT_ENCAPSED_STRING), which isn't gated on hasIncludeStatement either; here
            // the array having already passed the string-literal-only + '/'-containing filters is
            // signal enough that this looks like a path list, not arbitrary config data.
            if ($type === T_FOREACH) {
                foreach ($this->resolveForeachArrayLiterals($tokens, $i, $arrayLiteralVars) as $literal) {
                    $phpPathStrings[] = $literal;
                    if (!str_ends_with($literal, '.php')) {
                        $phpPathStrings[] = $literal . '.php';
                    }
                }

                // foreach ($conditionals as $conditional) { ... 'add_crumbs_' . substr($conditional,
                // 3) ... } — the plain "as $var" counterpart to the bounded for-loop tracking
                // above, over a tracked literal-string array instead of an integer range. Only
                // sets $expectingForeachLoopOpen (the push itself happens at the loop's own next
                // '{', mirroring $expectingForLoopOpen) when parseForeachLiteralArrayLoop actually
                // recognized the plain form; anything else leaves the loop variable untracked.
                $foreachResult = $this->parseForeachLiteralArrayLoop($tokens, $i, $anyArrayLiteralVars);
                if ($foreachResult !== null) {
                    [$pendingForeachLoopVarName, $pendingForeachLoopVarValues] = $foreachResult;
                    $expectingForeachLoopOpen = true;
                }
            }

            // `return $template_path;` / `return apply_filters('tag', $template_path);` /
            // `return 'literal';` / `return self::CONST;` inside a top-level named function OR
            // a method — resolved against the current function scope's accumulated
            // $varLiteralAssignmentsStack entry and folded into $functionLiteralReturns, keyed
            // the same "Class::method" way $functionParamSuffixReturns is just below (a bare
            // name for a top-level function, since two unrelated classes' same-named method
            // must not collide). See resolveFunctionCallHelperArg()/the TEMPLATE_FUNCS branch
            // below for the other half: a get_template_part()-family call whose argument is a
            // bare call to this function/method.
            if ($type === T_RETURN) {
                // `return <ignored-prefix> . $param . 'suffix';` — e.g. wp-nested-pages'
                // `Helpers::view($file) { return dirname(__FILE__) . '/Views/' . $file . '.php';
                // }`. Uses $functionDefIndexForBodyStack (not $functionNameStack, which only
                // tracks bare top-level functions) since the real-world evidence for this shape
                // is a *method*; keyed to the owner class too so two unrelated classes' same-
                // named helper don't collide. See resolveReturnParamSuffixTemplate's own
                // docblock and $pendingParamSuffixCalls for the call-site half.
                $currentDefIndex = empty($functionDefIndexForBodyStack) ? null : end($functionDefIndexForBodyStack);
                if ($currentDefIndex !== null) {
                    $currentDef = $functionDefs[$currentDefIndex];
                    $returnKey = $currentDef->isMethod && $currentDef->ownerClass !== null
                        ? $currentDef->ownerClass . '::' . $currentDef->name
                        : $currentDef->name;

                    $varScopeTop = count($varLiteralAssignmentsStack) - 1;
                    $literals = $this->resolveReturnLiterals(
                        $tokens,
                        $i,
                        $varLiteralAssignmentsStack[$varScopeTop],
                        $classConstants,
                        empty($classNameStack) ? null : end($classNameStack),
                        empty($classParentStack) ? null : end($classParentStack),
                        $currentNamespace,
                        $useImports,
                    );
                    if ($literals !== []) {
                        if (!isset($functionLiteralReturns[$returnKey])) {
                            $functionLiteralReturns[$returnKey] = [];
                        }
                        array_push($functionLiteralReturns[$returnKey], ...$literals);
                    }

                    $suffix = $this->resolveReturnParamSuffixTemplate($tokens, $i);
                    if ($suffix !== null) {
                        if (!isset($functionParamSuffixReturns[$returnKey])) {
                            $functionParamSuffixReturns[$returnKey] = [];
                        }
                        $functionParamSuffixReturns[$returnKey][] = $suffix;
                    }

                    // `return $components;` / `return apply_filters('tag', $components, ...);`
                    // where $components was built as a flat literal array — real-world shape
                    // (Botiga): `botiga_get_default_single_product_components()` returns exactly
                    // this, later transformed element-by-element into dynamic function names
                    // (see $functionNameTransformTemplates' own docblock). Same key convention as
                    // $functionLiteralReturns just above, just array-valued instead of scalar.
                    $arrayLiterals = $this->resolveReturnArrayLiterals($tokens, $i, $anyArrayLiteralVars);
                    if ($arrayLiterals !== []) {
                        if (!isset($functionArrayReturns[$returnKey])) {
                            $functionArrayReturns[$returnKey] = [];
                        }
                        array_push($functionArrayReturns[$returnKey], ...$arrayLiterals);
                    }
                }

                // `return "botiga_quick_view_summary_$suffix";` inside an `array_map()` closure
                // — the procedural (no enclosing class) counterpart to
                // $classNameTransformTemplates, feeding FunctionAnalyzer instead of Class/
                // FileAnalyzer. No per-function "owner" to scope the cross-product by the way
                // $classNameTransformTemplates is (the domain-providing function and the
                // transforming closure are two unrelated functions, not methods sharing a
                // class) — $functionNameTransformTemplates is a flat, project-wide list instead,
                // cross-referenced against every function's own $functionArrayReturns entry, same
                // "coarse net" trade-off. Gated on both "inside an array_map() closure" and "the
                // literal looks like a snake_case function-name prefix" (`ends in '_', all
                // lowercase`) — WordPress's own near-universal naming convention for procedural
                // helper functions, the natural counterpart to the capitalized-identifier gate
                // $classNameTransformTemplates uses for class names.
                $insideArrayMapClosure = !empty($functionIsArrayMapClosureStack) && end($functionIsArrayMapClosureStack);
                if ($insideArrayMapClosure) {
                    $returnExprIndex = $this->peekNextMeaningfulIndex($tokens, $i);
                    $interpolated = $returnExprIndex !== null ? $this->interpolatedPrefixVarAt($tokens, $returnExprIndex) : null;
                    if ($interpolated !== null) {
                        [$interpPrefix, $interpVarName] = $interpolated;
                        if (
                            isset($transformChainVars[$interpVarName])
                            && preg_match('/^[a-z][a-z0-9_]*_$/', $interpPrefix) === 1
                        ) {
                            $functionNameTransformTemplates[] = [$interpPrefix, $transformChainVars[$interpVarName][1]];
                        }
                    }
                }

                // A wrapper's return value can be the next wrapper hop — e.g. WPForms'
                // Templates::locate() assigns its path to `$located` then returns
                // `apply_filters(..., $located, ...)`. Preserve that exact value-to-return link
                // so a caller which feeds it into load_template()/require can be joined after all
                // files have been parsed. Unlike arbitrary return expressions, this accepts only
                // a direct tracked node or apply_filters()'s documented value argument.
                $literalPathScopeTop = count($literalPathNodeStack) - 1;
                $literalPathFunctionKey = $literalPathFunctionKeyStack[$literalPathScopeTop];
                if ($literalPathFunctionKey !== null) {
                    $returnSource = $this->resolveLiteralPathReturnSource(
                        $tokens,
                        $i,
                        $literalPathNodeStack[$literalPathScopeTop],
                    );
                    if ($returnSource !== null) {
                        [$sourceNode, $prefix, $suffix] = $returnSource;
                        $literalPathPropagationLinks[] = new LiteralPathPropagationLink(
                            $sourceNode,
                            $this->literalPathReturnNode($literalPathFunctionKey),
                            $prefix,
                            $suffix,
                        );
                    }
                }

                // `return [ 'admin_email' => ..., 'field_id' => ..., ... ];` — a keyed array
                // literal returned directly (not built via repeated `$var['key'] = ...;`
                // assignment) establishes the same "every literal key this class ever declares"
                // domain $classArrayKeyLiterals feeds elsewhere — see its own docblock and
                // resolveReturnedKeyedArrayLiteralKeys()'s.
                $currentReturnOwnerClass = empty($classNameStack) ? null : end($classNameStack);
                if ($currentReturnOwnerClass !== null) {
                    $returnedKeys = $this->resolveReturnedKeyedArrayLiteralKeys($tokens, $i);
                    if ($returnedKeys !== null) {
                        if (!isset($classArrayKeyLiterals[$currentReturnOwnerClass])) {
                            $classArrayKeyLiterals[$currentReturnOwnerClass] = [];
                        }
                        array_push($classArrayKeyLiterals[$currentReturnOwnerClass], ...$returnedKeys);
                    }
                }
            }

            if ($type === T_EXTENDS || $type === T_IMPLEMENTS) {
                foreach ($this->captureClassNameList($tokens, $i, $currentNamespace, $useImports) as $ref) {
                    $classReferences[] = $ref->short;
                }
                continue;
            }

            // `if ( $tab == 'general' ) ... if ( $tab == 'posttypes' ) ...` — a variable's own
            // possible values, established not by assignment but by which literals it's compared
            // against elsewhere in the same scope (real-world shape: wp-nested-pages'
            // settings.php, feeding `include(Helpers::view('settings/settings-' . $tab))` once
            // $tab's domain is known). Accumulated into the same $varLiteralAssignmentsStack pool
            // as a literal assignment would be — this parser doesn't distinguish "ever assigned"
            // from "ever tested against" for the purpose of guessing a variable's possible
            // values, same coarse-net spirit as everywhere else here. Deliberately narrow:
            // `$var (==|===) 'literal'` order only, not the reversed `'literal' == $var` (no
            // evidence for it yet).
            if ($type === T_IS_EQUAL || $type === T_IS_IDENTICAL) {
                $prevIndex = $this->peekPrevMeaningfulIndex($tokens, $i);
                $nextIndex = $this->peekNextMeaningfulIndex($tokens, $i);
                if (
                    $prevIndex !== null && $nextIndex !== null
                    && is_array($tokens[$prevIndex]) && $tokens[$prevIndex][0] === T_VARIABLE
                    && is_array($tokens[$nextIndex]) && $tokens[$nextIndex][0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $comparedVarName = $tokens[$prevIndex][1];
                    $comparedLiteral = $this->stripQuotes($tokens[$nextIndex][1]);
                    $varScopeTop = count($varLiteralAssignmentsStack) - 1;
                    $this->accumulateVarLiteral($varLiteralAssignmentsStack, $varScopeTop, $comparedVarName, $comparedLiteral);
                }
            }

            if ($type === T_FUNCTION) {
                $insideClass = !empty($classDepthStack);
                $ownerClass = empty($classNameStack) ? null : end($classNameStack);
                $ownerParent = empty($classParentStack) ? null : end($classParentStack);
                $def = $this->parseFunctionDef($tokens, $i, $file, $insideClass, $ownerClass);
                if ($def !== null) {
                    $isGuarded = !empty($functionExistsGuardNameStack) && end($functionExistsGuardNameStack) === $def->name;
                    $fqcn = $currentNamespace === '' ? $def->name : $currentNamespace . '\\' . $def->name;
                    $def = new FunctionDef(
                        $def->name,
                        $def->line,
                        $def->file,
                        $def->isMethod,
                        $def->ownerClass,
                        $def->returnType,
                        guarded: $isGuarded,
                        fqcn: $fqcn,
                        parameters: $def->parameters,
                    );
                }
                // `$schedule = function ( $date, $hook ) use ( $product_id ) { ... };` — a
                // closure assigned directly to a plain local variable. See
                // $closureHookPassThroughParamStack's own declaration comment for why this is
                // tracked entirely inline rather than through the named-function machinery above
                // (which deliberately skips anonymous closures — $def stays null for them).
                if ($def === null) {
                    $closureParenIndex = $this->findParenAfterFunctionKeyword($tokens, $i);
                    $closureEqualsIndex = $this->peekPrevMeaningfulIndex($tokens, $i);
                    $closureVarIndex = $closureEqualsIndex !== null ? $this->peekPrevMeaningfulIndex($tokens, $closureEqualsIndex) : null;
                    if (
                        $closureParenIndex !== null
                        && $closureEqualsIndex !== null && $tokens[$closureEqualsIndex] === '='
                        && $closureVarIndex !== null && is_array($tokens[$closureVarIndex]) && $tokens[$closureVarIndex][0] === T_VARIABLE
                    ) {
                        $pendingClosureVarName = $tokens[$closureVarIndex][1];
                        $pendingClosureParamNames = $this->parseFunctionParameterNames($tokens, $closureParenIndex);
                        $expectingClosureOpen = true;
                    }
                }

                // Type-hinted parameters: `function foo(My_Class $x)` both references My_Class
                // and, same as `$x = new My_Class()`, tells us $x's type for the rest of the
                // body — applies equally to anonymous functions/closures, which is why this
                // runs unconditionally rather than only when $def !== null.
                $parenIndex = $this->findParenAfterFunctionKeyword($tokens, $i);
                if ($def !== null && $parenIndex !== null) {
                    // `: ReturnType` — same "single unambiguous type" resolution as the param
                    // hints just below, just consulted later (once every file is merged) rather
                    // than seeding local-variable tracking directly, since a factory method's
                    // caller is routinely in a different file (see PendingReturnTypedCall).
                    $closeParenIndex = $this->findMatchingCloseParen($tokens, $parenIndex);
                    $returnType = $closeParenIndex !== null
                        ? $this->parseReturnTypeHint($tokens, $closeParenIndex, $ownerClass, $ownerParent, $currentNamespace, $useImports)
                        : null;
                    if ($returnType !== null) {
                        $classReferences[] = $returnType->short;
                        $def = new FunctionDef(
                            $def->name,
                            $def->line,
                            $def->file,
                            $def->isMethod,
                            $def->ownerClass,
                            $returnType->fqcn,
                            guarded: $def->guarded,
                            fqcn: $def->fqcn,
                            parameters: $def->parameters,
                        );
                    }
                }
                if ($def !== null) {
                    $functionDefs[] = $def;
                }
                $pendingFunctionDefIndex = $def !== null ? count($functionDefs) - 1 : null;
                $pendingLiteralPathFunctionKey = $def !== null ? $this->literalPathFunctionKey($def) : null;
                $pendingLiteralPathNodes = $def !== null ? $this->literalPathParameterNodes($def) : [];
                if ($parenIndex !== null) {
                    [$hintClassRefs, $pendingParamTypes, $promotedPropertyTypes] = $this->parseParamTypeHints($tokens, $parenIndex, $ownerClass, $ownerParent, $currentNamespace, $useImports);
                    foreach ($hintClassRefs as $ref) {
                        $classReferences[] = $ref;
                    }
                    // Promotion auto-assigns $this->name = $name — same effect as an explicit
                    // property assignment elsewhere in the constructor body.
                    if ($ownerClass !== null) {
                        foreach ($promotedPropertyTypes as $propName => $className) {
                            $propertyAssignedClasses[$ownerClass][$propName] = $className;
                        }
                    }
                }
                // Only a *named* declaration has a name token to skip — `function foo(` has
                // "foo" right where a call's name would be, so without this the next T_STRING
                // token (wherever it falls) gets silently eaten instead. An anonymous
                // closure/arrow function has no such token ($def === null): `function () {
                // add_action('x', function () { my_helper(); }); }` was skipping the very next
                // T_STRING it saw — my_helper()'s own name — discarding a real call and making
                // my_helper() look unused despite being called right there.
                if ($def !== null) {
                    $skipNextString = true;
                }
                // `array_map( function ( $x ) { ... }, $arr )` — walk backward past an optional
                // `static` modifier to check whether this closure is array_map()'s own first
                // argument. See $functionIsArrayMapClosureStack's own declaration comment.
                $funcPrevIndex = $this->peekPrevMeaningfulIndex($tokens, $i);
                if ($funcPrevIndex !== null && is_array($tokens[$funcPrevIndex]) && $tokens[$funcPrevIndex][0] === T_STATIC) {
                    $funcPrevIndex = $this->peekPrevMeaningfulIndex($tokens, $funcPrevIndex);
                }
                if ($funcPrevIndex !== null && $tokens[$funcPrevIndex] === '(') {
                    $mapCallNameIndex = $this->peekPrevMeaningfulIndex($tokens, $funcPrevIndex);
                    $pendingFunctionIsArrayMapClosure = $mapCallNameIndex !== null
                        && is_array($tokens[$mapCallNameIndex])
                        && in_array($tokens[$mapCallNameIndex][0], self::CLASS_NAME_TOKENS, true)
                        && $this->shortClassName($tokens[$mapCallNameIndex][1]) === 'array_map';
                }
                // Every function/method/closure opens its own variable scope — including
                // anonymous ones ($def === null for those), which need this exactly as much.
                $expectingFunctionOpen = true;
                continue;
            }

            if ($type === T_VARIABLE) {
                $scopeTop = count($varTypesStack) - 1;
                $literalPathScopeTop = count($literalPathNodeStack) - 1;
                $literalPathFunctionKey = $literalPathFunctionKeyStack[$literalPathScopeTop];
                if ($literalPathFunctionKey !== null) {
                    $literalPathNodes = $literalPathNodeStack[$literalPathScopeTop];
                    $literalPathCurrentClass = empty($classNameStack) ? null : end($classNameStack);
                    $literalPathCurrentParent = empty($classParentStack) ? null : end($classParentStack);
                    $this->captureLiteralPathAssignment(
                        $tokens,
                        $i,
                        $literalPathFunctionKey,
                        $currentNamespace,
                        $useImports,
                        $literalPathCurrentClass,
                        $literalPathCurrentParent,
                        $literalPathNodes,
                        $literalPathFileExistenceGuards,
                        $literalPathNodeCounter,
                        $literalPathPropagationLinks,
                        $literalPathInputs,
                        $functionLiteralReturns,
                        $literalPathTrackedPropertyNodes,
                        $literalPathPropertyLiteralDefaults,
                    );
                    $literalPathNodeStack[$literalPathScopeTop] = $literalPathNodes;

                    $this->captureLiteralPathPropertyAssignment(
                        $tokens,
                        $i,
                        $literalPathFunctionKey,
                        $currentNamespace,
                        $useImports,
                        $literalPathCurrentClass,
                        $literalPathCurrentParent,
                        $literalPathNodes,
                        $literalPathFileExistenceGuards,
                        $literalPathTrackedPropertyNodes,
                        $literalPathPropagationLinks,
                        $functionLiteralReturns,
                        $literalPathPropertyLiteralDefaults,
                    );
                }

                // $anyVar['literal'] = ...; — real-world shape (WooCommerce):
                // `$show_columns['thumb'] = ...;` inside `define_columns()`. Applies to any
                // variable name, including (but not limited to) $this — see
                // $classArrayKeyLiterals' own declaration comment for why this is deliberately
                // class-wide rather than scoped to one variable.
                $currentArrayKeyOwnerClass = empty($classNameStack) ? null : end($classNameStack);
                if ($currentArrayKeyOwnerClass !== null) {
                    $arrayKeyLiteral = $this->arrayKeyLiteralAssignment($tokens, $i);
                    if ($arrayKeyLiteral !== null) {
                        if (!isset($classArrayKeyLiterals[$currentArrayKeyOwnerClass])) {
                            $classArrayKeyLiterals[$currentArrayKeyOwnerClass] = [];
                        }
                        $classArrayKeyLiterals[$currentArrayKeyOwnerClass][] = $arrayKeyLiteral;
                    }
                }

                if ($value === '$this') {
                    // The one case where an object's exact class is always known without any
                    // type inference: it's whichever class this code is physically inside.
                    $receiverClass = empty($classNameStack) ? null : end($classNameStack);

                    // $this->prop = new ClassName(...) / $this->prop->method() — property-type
                    // tracking, the class-scoped counterpart to $varTypesStack above. A property
                    // set in one method and read via $this->prop->method() in a different one
                    // (constructor + collaborator pattern) previously fell back to the unscoped
                    // pool entirely. propertyAccessTarget() only requires `$this->identifier`,
                    // not `(` right after it, so it fires for both the assignment shape and the
                    // method-call shape below — findScopedCallTarget() further down still
                    // independently (and correctly) skips this as a `$this->method()` call, since
                    // it requires '(' immediately after the identifier, which a property access
                    // never has.
                    if ($receiverClass !== null) {
                        $propTarget = $this->propertyAccessTarget($tokens, $i);
                        if ($propTarget !== null) {
                            [$propName, $propNameIndex] = $propTarget;
                            $afterPropIndex = $this->peekNextMeaningfulIndex($tokens, $propNameIndex);

                            if ($afterPropIndex !== null && $tokens[$afterPropIndex] === '=') {
                                $newClass = $this->assignedNewClassName($tokens, $afterPropIndex, $classNameStack, $classParentStack, $currentNamespace, $useImports)
                                    ?? $this->assignedStaticFactoryClassName($tokens, $afterPropIndex, $classNameStack, $classParentStack, $currentNamespace, $useImports)
                                    ?? $this->assignedVariableClassName($tokens, $afterPropIndex, $varTypesStack[$scopeTop]);
                                if ($newClass !== null) {
                                    $propertyAssignedClasses[$receiverClass][$propName] = $newClass;
                                } else {
                                    unset($propertyAssignedClasses[$receiverClass][$propName]);
                                }

                                // $this->actions = [ 'wp_ajax_npsort', 'admin_post_npBulkActions',
                                // ... ]; — real-world shape (wp-nested-pages): a property
                                // assigned a flat literal array feeds the same class-wide literal
                                // pool $classArrayKeyLiterals already collects keys into (see its
                                // own docblock) — every value here is later transformed into a
                                // dynamic class name by a sibling method.
                                $propArrayValues = $this->parseAnyStringLiteralArray($tokens, $afterPropIndex);
                                if ($propArrayValues !== null) {
                                    if (!isset($classArrayKeyLiterals[$receiverClass])) {
                                        $classArrayKeyLiterals[$receiverClass] = [];
                                    }
                                    array_push($classArrayKeyLiterals[$receiverClass], ...$propArrayValues);
                                }
                            } else {
                                $propCallTarget = $this->findScopedCallTarget($tokens, $propNameIndex);
                                if ($propCallTarget !== null) {
                                    [$propMethodName, $propMethodNameIndex] = $propCallTarget;
                                    $propertyMethodCalls[] = new PropertyMethodCall($receiverClass, $propName, $propMethodName);
                                    $i = $propMethodNameIndex;
                                }
                            }
                        }
                    }

                    $target = $this->findScopedCallTarget($tokens, $i);
                    if ($target !== null) {
                        if ($receiverClass !== null) {
                            [$methodName, $methodNameIndex] = $target;
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                            $i = $methodNameIndex;

                            $literalPathScopeTop = count($literalPathNodeStack) - 1;
                            $this->captureLiteralPathCall(
                                [$receiverClass . '::' . $methodName],
                                $tokens,
                                $methodNameIndex,
                                $literalPathFunctionKeyStack[$literalPathScopeTop],
                                $literalPathNodeStack[$literalPathScopeTop],
                                false,
                                $literalPathPropagationLinks,
                                $literalPathInputs,
                            );

                            // $this->get_section( 'colors' ) — a scoped call with a plain
                            // string-literal first argument. Recorded as a *candidate*
                            // self-dispatch call regardless of what get_section() actually does
                            // with it; resolved once every file's parse is merged, against
                            // whether get_section() itself matches the `call_user_func([$this,
                            // "{$param}_suffix"])` shape (see $selfDispatchSuffixes and
                            // PendingSelfDispatchCall's own docblock — Sydney theme's
                            // `get_section('colors')`/`('buttons')`/...).
                            $selfDispatchArgIndex = $this->firstStringArgIndex($tokens, $methodNameIndex);
                            if ($selfDispatchArgIndex !== null) {
                                $pendingSelfDispatchCalls[] = new PendingSelfDispatchCall(
                                    $receiverClass,
                                    $methodName,
                                    $this->stripQuotes($tokens[$selfDispatchArgIndex][1]),
                                );
                            }
                        }
                    }
                } elseif (($equalsIndex = $this->peekNextMeaningfulIndex($tokens, $i)) !== null && $tokens[$equalsIndex] === '=') {
                    // $var = new ClassName(...) — remember it for the rest of this scope. Any
                    // other RHS invalidates a previous tracked type rather than leaving it
                    // stale (e.g. $var = new Foo(); ... $var = some_call(); — $var's type is no
                    // longer known, so a later $var->method() must fall back to unscoped).
                    // No control-flow awareness: `if ($c) { $x = new A(); } else { $x = new B(); }`
                    // just tracks whichever assignment comes last in source order, not "could be
                    // either" — an approximation, same spirit as the rest of this parser, and
                    // still strictly more precise than the unscoped fallback it replaces.
                    $newClass = $this->assignedNewClassName($tokens, $equalsIndex, $classNameStack, $classParentStack, $currentNamespace, $useImports)
                        ?? $this->assignedStaticFactoryClassName($tokens, $equalsIndex, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                    if ($newClass !== null) {
                        $varTypesStack[$scopeTop][$value] = $newClass;
                        unset($varPendingCallStack[$scopeTop][$value]);
                    } else {
                        unset($varTypesStack[$scopeTop][$value]);

                        // $var = SomeFactory::make(); / $var = $this->make(); / $var =
                        // create_widget(); — mutually exclusive with $newClass above (a `new`
                        // RHS already handled it); resolved later against make()'s own declared
                        // return type, once every file's parse is merged — see
                        // PendingReturnTypedCall.
                        $pendingCall = $this->scopedOrBareCallRhs($tokens, $equalsIndex, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                        if ($pendingCall !== null) {
                            $varPendingCallStack[$scopeTop][$value] = $pendingCall;
                        } else {
                            unset($varPendingCallStack[$scopeTop][$value]);
                        }
                    }

                    // $var = array('a/b/c', 'd/e/f', ...) / $var = ['a/b/c', ...] — a plain
                    // sequential array of nothing but string literals. Tracked flat (not scoped
                    // to $varTypesStack's per-function stack) since the foreach loop that
                    // consumes it is almost always in the same function anyway, and reassignment
                    // simply overwrites the entry the same way $varTypesStack does. See the
                    // T_FOREACH handling below for what this feeds into.
                    $literalArray = $this->parseStringLiteralArray($tokens, $equalsIndex);
                    if ($literalArray !== null) {
                        $arrayLiteralVars[$value] = $literalArray;
                    } else {
                        unset($arrayLiteralVars[$value]);
                    }
                    $anyLiteralArray = $this->parseAnyStringLiteralArray($tokens, $equalsIndex);
                    if ($anyLiteralArray !== null) {
                        $anyArrayLiteralVars[$value] = $anyLiteralArray;
                    } else {
                        unset($anyArrayLiteralVars[$value]);
                    }

                    // $class = str_replace('admin_post_np', '', $action); / $class =
                    // ucfirst(str_replace('wp_ajax_np', '', $class)); — marks $value as holding
                    // a recognized transform chain's result, consumed by the concatenation check
                    // just below (and, for a chain spanning several statements, by this same
                    // check again on the next statement — see resolveTransformChainExpr()'s own
                    // docblock).
                    $transformChain = $this->resolveTransformChainRhs($tokens, $equalsIndex, $transformChainVars);
                    if ($transformChain !== null && $transformChain[1] !== []) {
                        $transformChainVars[$value] = $transformChain;
                    } elseif (!$this->rhsIsSelfReferentialLiteralConcat($tokens, $equalsIndex, $value)) {
                        unset($transformChainVars[$value]);
                    }
                    // else: `$class_name = __NAMESPACE__ . '\Widget_' . $class_name;` (Elementor)
                    // — reassigns $value using its own prior value, concatenated onto a literal.
                    // resolveTransformChainRhs() can't recognize this shape at all (it isn't a
                    // bare transform-function call), but literalConcatVarAt() — checked later in
                    // this same forward scan, once $i reaches the literal token itself — matches
                    // it directly, provided $transformChainVars[$value] still holds the chain the
                    // *previous* statement built. Leaving the old entry in place here (instead of
                    // wiping it just because this statement's own RHS shape isn't a bare chain
                    // call) is what makes that later lookup still see it.

                    // $var = 'literal'; — accumulated (not overwritten) into the current
                    // function scope's $varLiteralAssignmentsStack entry; see its own
                    // declaration comment above for why accumulation, not last-write-wins, is
                    // the right call here.
                    $singleLiteral = $this->singleStringLiteralRhs($tokens, $equalsIndex);
                    if ($singleLiteral !== null) {
                        $varScopeTop = count($varLiteralAssignmentsStack) - 1;
                        $this->accumulateVarLiteral($varLiteralAssignmentsStack, $varScopeTop, $value, $singleLiteral);
                        // $hook = 'my_plugin_loaded'; do_action($hook); — last-write-wins,
                        // consulted by classifyArgTokens() below when a hook/template-part
                        // argument is a bare variable instead of a literal directly.
                        $varLiteralValueStack[$varScopeTop][$value] = $singleLiteral;
                    } else {
                        $varScopeTop = count($varLiteralValueStack) - 1;
                        unset($varLiteralValueStack[$varScopeTop][$value]);
                    }

                    // $var = ( <cond> ) ? 'lit1' : 'lit2'; — both ternary branches accumulated
                    // into the same $varLiteralAssignmentsStack entry as the plain single-literal
                    // assignment above; the condition itself is never evaluated (which branch
                    // actually runs is exactly what can't be known statically — same "every
                    // possibility a literal-only assignment reveals" stance the whole map already
                    // takes). Real-world shape (wp-nested-pages): `$row_view = (
                    // $this->post->type !== 'np-redirect' ) ? 'partials/row' :
                    // 'partials/row-link';`.
                    $ternaryDomain = $this->parseTernaryLiteralDomain($tokens, $equalsIndex);
                    if ($ternaryDomain !== null) {
                        $varScopeTop = count($varLiteralAssignmentsStack) - 1;
                        $this->accumulateVarLiteral($varLiteralAssignmentsStack, $varScopeTop, $value, $ternaryDomain[0]);
                        $this->accumulateVarLiteral($varLiteralAssignmentsStack, $varScopeTop, $value, $ternaryDomain[1]);
                    }

                    // $var = helper_fn(); — last-write-wins, same scoping as $varTypesStack. See
                    // $varAssignedFromFunctionStack's own declaration comment for why (a single
                    // assignment, not a multi-branch accumulation).
                    $assignedFnScopeTop = count($varAssignedFromFunctionStack) - 1;
                    $assignedFn = $this->bareZeroArgFunctionCallRhs($tokens, $equalsIndex);
                    if ($assignedFn !== null) {
                        $varAssignedFromFunctionStack[$assignedFnScopeTop][$value] = $assignedFn;
                    } else {
                        unset($varAssignedFromFunctionStack[$assignedFnScopeTop][$value]);
                    }
                } else {
                    // $schedule( $date, 'wc_product_start_scheduled_sale' ) — a bare variable
                    // invocation of a closure previously assigned in this same function scope
                    // (see $closureHookPassThroughParamStack's own declaration comment: entirely
                    // local, resolved immediately rather than through a merge-later Pending*
                    // struct). Emits a HookInvocation directly into the same pool a literal
                    // argument to the sink itself already would.
                    $closureHookScopeTop = count($closureHookPassThroughParamStack) - 1;
                    $closureParamPosition = $closureHookPassThroughParamStack[$closureHookScopeTop][$value] ?? null;
                    if ($closureParamPosition !== null && $this->peekNextMeaningful($tokens, $i) === '(') {
                        $closureCallArg = $this->literalPathCallArgumentTokensAt($tokens, $i, $closureParamPosition);
                        $closureCallLiteral = $closureCallArg !== null ? $this->literalPathStringLiteral($closureCallArg) : null;
                        if ($closureCallLiteral !== null) {
                            $hookInvocations[] = new HookInvocation($closureCallLiteral, 'as_schedule_single_action', (int) $line, $file, false, $closureCallLiteral, '');
                        }
                    }

                    // $callback = array('WP_CLI', 'add_command'); if (is_callable($callback)) {
                    // $callback('circumflex-booking database', new DatabaseCommand(...)); } —
                    // the exact same WP-CLI reflection-dispatch shape
                    // recordWpCliAddCommandDispatch() already recognizes for a direct
                    // `WP_CLI::add_command(...)` call, just reached through a locally-aliased
                    // callable-array variable instead of a literal scoped call — a defensive
                    // idiom plugins use to guard a WP-CLI registration behind is_callable()
                    // rather than referencing the class name directly. $anyArrayLiteralVars
                    // already tracks any variable holding a flat array of plain string literals
                    // (see its own declaration comment above); a two-element hit, immediately
                    // followed by an invocation of that same variable, is routed through the
                    // exact same recognizer a literal call already uses —
                    // recordWpCliAddCommandDispatch() itself still gates on the receiver/method
                    // pair actually being ('WP_CLI', 'add_command'), so this is safe to try for
                    // any two-element aliased callable, not just a WP-CLI one. Real-world case
                    // (circumflex-booking).
                    $aliasedCallable = $anyArrayLiteralVars[$value] ?? null;
                    if (
                        $aliasedCallable !== null
                        && count($aliasedCallable) === 2
                        && $this->peekNextMeaningful($tokens, $i) === '('
                    ) {
                        $this->recordWpCliAddCommandDispatch(
                            $aliasedCallable[0],
                            $aliasedCallable[1],
                            $tokens,
                            $i,
                            $currentNamespace,
                            $useImports,
                            $reflectionDispatchedClassNames,
                        );
                    }

                    $trackedClass = $varTypesStack[$scopeTop][$value] ?? null;
                    if ($trackedClass !== null) {
                        $target = $this->findScopedCallTarget($tokens, $i);
                        if ($target !== null) {
                            [$methodName, $methodNameIndex] = $target;
                            $scopedMethodCalls[] = new ScopedMethodCall($trackedClass, $methodName);
                            $literalPathScopeTop = count($literalPathNodeStack) - 1;
                            $this->captureLiteralPathCall(
                                [$trackedClass . '::' . $methodName],
                                $tokens,
                                $methodNameIndex,
                                $literalPathFunctionKeyStack[$literalPathScopeTop],
                                $literalPathNodeStack[$literalPathScopeTop],
                                false,
                                $literalPathPropagationLinks,
                                $literalPathInputs,
                            );
                            $i = $methodNameIndex;
                        } else {
                            // $typedParam->prop = $this; — the exact inverse of $this->prop =
                            // new X() above: here the assignment TARGET is an external, type-
                            // hinted variable, and the VALUE stored is the current instance.
                            // Real-world shape (Wordfence's bundled Diff library):
                            // `function render(Diff_Renderer_Abstract $renderer) {
                            // $renderer->diff = $this; return $renderer->render(); }` — read back
                            // later inside a *subclass* of Diff_Renderer_Abstract as
                            // `$this->diff->getA()`, resolved by ClassAnalyzer's own ancestor-
                            // chain fallback (the mirror direction of its existing descendant
                            // fallback, since here the property is assigned against the exact
                            // declared type while the read happens in a concrete subclass of it).
                            // Written into the SAME $propertyAssignedClasses map the $this->prop
                            // branch above already populates, just keyed by the target's own
                            // tracked type instead of the current class — no separate merge step
                            // needed anywhere downstream.
                            $propTarget = $this->propertyAccessTarget($tokens, $i);
                            if ($propTarget !== null) {
                                [$propName, $propNameIndex] = $propTarget;
                                $afterPropIndex = $this->peekNextMeaningfulIndex($tokens, $propNameIndex);
                                if ($afterPropIndex !== null && $tokens[$afterPropIndex] === '=') {
                                    $rhsIndex = $this->peekNextMeaningfulIndex($tokens, $afterPropIndex);
                                    $afterRhsIndex = $rhsIndex !== null ? $this->peekNextMeaningfulIndex($tokens, $rhsIndex) : null;
                                    if (
                                        $rhsIndex !== null && is_array($tokens[$rhsIndex]) && $tokens[$rhsIndex][0] === T_VARIABLE && $tokens[$rhsIndex][1] === '$this'
                                        && $afterRhsIndex !== null && $tokens[$afterRhsIndex] === ';'
                                    ) {
                                        $currentClassFqcn = empty($classNameStack) ? null : end($classNameStack);
                                        if ($currentClassFqcn !== null) {
                                            $propertyAssignedClasses[$trackedClass][$propName] = $currentClassFqcn;
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $pendingCall = $varPendingCallStack[$scopeTop][$value] ?? null;
                        if ($pendingCall !== null) {
                            $target = $this->findScopedCallTarget($tokens, $i);
                            if ($target !== null) {
                                [$readMethod, $methodNameIndex] = $target;
                                [$sourceReceiverClass, $sourceMethod] = $pendingCall;
                                $pendingReturnTypedCalls[] = new PendingReturnTypedCall($sourceReceiverClass, $sourceMethod, $readMethod);
                                $i = $methodNameIndex;
                            }
                        }
                    }
                }
                // No `continue` — variables are used in plenty of other ways (property access,
                // passed as an argument, ...) that need no special handling here.
            }

            // static::method() — "static" is its own token (T_STATIC), never T_STRING, so it
            // can't be reached by the T_STRING branch below the way self:: and parent:: are.
            if ($type === T_STATIC) {
                $target = $this->findScopedCallTarget($tokens, $i);
                if ($target !== null) {
                    $receiverClass = empty($classNameStack) ? null : end($classNameStack);
                    if ($receiverClass !== null) {
                        [$methodName, $methodNameIndex] = $target;
                        $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                        $literalPathScopeTop = count($literalPathNodeStack) - 1;
                        $this->captureLiteralPathCall(
                            [$receiverClass . '::' . $methodName],
                            $tokens,
                            $methodNameIndex,
                            $literalPathFunctionKeyStack[$literalPathScopeTop],
                            $literalPathNodeStack[$literalPathScopeTop],
                            false,
                            $literalPathPropagationLinks,
                            $literalPathInputs,
                        );
                        $i = $methodNameIndex;
                    }
                }
                // No `continue` — "static" is also a method/property modifier and a local-variable
                // keyword ("static function", "static $var") that need no handling here either.
            }

            if ($type === T_STRING) {
                // Skip the function name token — it's a definition, not a call
                if ($skipNextString) {
                    $skipNextString = false;
                    continue;
                }
                $skipNextString = false;
                $name = $value;
                $nextNonWhitespace = $this->peekNextMeaningful($tokens, $i);

                if (
                    $nextNonWhitespace === '('
                    && in_array($name, self::FILE_EXISTENCE_GUARD_FUNCS, true)
                ) {
                    $literalPathScopeTop = count($literalPathNodeStack) - 1;
                    $this->captureLiteralPathFileExistenceGuard(
                        $tokens,
                        $i,
                        $literalPathFunctionKeyStack[$literalPathScopeTop],
                        $literalPathNodeStack[$literalPathScopeTop],
                        $literalPathFileExistenceGuards,
                    );
                }

                if ($nextNonWhitespace === '::') {
                    // Foo::method(), Foo::CONST, Foo::class, Foo::$prop — whatever comes after
                    // the '::', "Foo" itself is a class reference either way. Resolved through
                    // any use-import alias first (`use Foo\Bar as Alias; Alias::method()`) — same
                    // real-world shape as captureClassNameAfter()'s own fix above: previously
                    // recorded the alias itself, never matching the aliased class's own declared
                    // short name.
                    $receiverClass = $this->resolveClassNameToken($name, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                    $classReferences[] = $receiverClass !== null ? $this->shortClassName($receiverClass) : $name;

                    // self::method()/parent::method()/Foo::method() (but not Foo::CONST,
                    // Foo::class, Foo::$prop — findScopedCallTarget only matches an actual call).
                    $target = $this->findScopedCallTarget($tokens, $i);
                    if ($target !== null) {
                        [$methodName, $methodNameIndex] = $target;

                        $this->recordWpCliAddCommandDispatch($name, $methodName, $tokens, $methodNameIndex, $currentNamespace, $useImports, $reflectionDispatchedClassNames);

                        if ($receiverClass !== null) {
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                            $i = $methodNameIndex;

                            $literalPathScopeTop = count($literalPathNodeStack) - 1;
                            $this->captureLiteralPathCall(
                                [$receiverClass . '::' . $methodName],
                                $tokens,
                                $methodNameIndex,
                                $literalPathFunctionKeyStack[$literalPathScopeTop],
                                $literalPathNodeStack[$literalPathScopeTop],
                                false,
                                $literalPathPropagationLinks,
                                $literalPathInputs,
                            );

                            // Foo::bulkLoad('inc') — a scoped call with a plain string-literal
                            // first argument. Recorded as a *candidate* directory-loader call
                            // regardless of what the callee actually does with it; resolved once
                            // every file's parse is merged, against whether the callee method's
                            // own body turns out to contain a require/include keyword anywhere
                            // (see FunctionDef::$hasIncludeInBody and PendingDirectoryLoaderCall's
                            // own docblock for the real-world shape this covers — Flynt theme's
                            // `FileLoader::loadPhpFiles('inc')`).
                            $literalArgIndex = $this->firstStringArgIndex($tokens, $methodNameIndex);
                            if ($literalArgIndex !== null) {
                                $pendingDirectoryLoaderCalls[] = new PendingDirectoryLoaderCall(
                                    $receiverClass,
                                    $methodName,
                                    $this->stripQuotes($tokens[$literalArgIndex][1]),
                                );
                            }

                            // Foo::view($row_view) — a scoped call whose sole argument is a bare
                            // tracked variable (or 'literal' . $var) rather than a literal
                            // directly. Recorded as a *candidate* param-suffix call regardless of
                            // what the callee actually does with it; resolved once every file's
                            // parse is merged, against whether the callee's own body matches the
                            // `return <ignored> . $param . 'suffix';` shape (see
                            // $functionParamSuffixReturns and PendingParamSuffixCall's own
                            // docblock for the real-world shape this covers — wp-nested-pages'
                            // `Helpers::view($row_view)`/`Helpers::view('settings/settings-' .
                            // $tab)`).
                            $singleArgTokens = $this->argTokensAt($tokens, $methodNameIndex, 0);
                            if ($singleArgTokens !== null && $this->argTokensAt($tokens, $methodNameIndex, 1) === null) {
                                $argScopeTop = count($varLiteralAssignmentsStack) - 1;
                                $candidates = $this->resolveParamSuffixCallArgumentCandidates($singleArgTokens, $varLiteralAssignmentsStack[$argScopeTop]);
                                if ($candidates !== null) {
                                    $pendingParamSuffixCalls[] = new PendingParamSuffixCall($receiverClass, $methodName, $candidates);
                                }
                            }
                        }
                    }
                    continue;
                }

                if ($nextNonWhitespace !== '(') {
                    // Could be a string callback — handled below via T_CONSTANT_ENCAPSED_STRING
                    continue;
                }

                // `collect(['setup', 'filters'])->each(function ($file) { ... });` (Roots/Acorn's
                // Laravel-style fluent Collection iteration, real-world shape: Sage theme's own
                // functions.php) — the same bounded literal-domain loop $expectingForeachLoopOpen
                // already tracks for an ordinary `foreach ($arrayVar as $var) { ... }`, just
                // spelled the fluent-collection way. See parseCollectEachLoop()'s own docblock.
                if ($name === 'collect') {
                    $collectEach = $this->parseCollectEachLoop($tokens, $i);
                    if ($collectEach !== null) {
                        [$pendingForeachLoopVarName, $pendingForeachLoopVarValues] = $collectEach;
                        $expectingForeachLoopOpen = true;
                    }
                }

                $this->dispatchBareFunctionCall(
                    $name,
                    // A `use function`-imported name resolves deterministically (the import
                    // shadows the runtime fallback entirely — see $useFunctionImports' own
                    // declaration-site comment); otherwise a bare call inside a namespaced file
                    // is genuinely ambiguous at runtime (current-namespace tried first, falling
                    // back to global) — see FunctionCall::$extraCandidateFqcn's own docblock.
                    $useFunctionImports[$name] ?? ($currentNamespace === '' ? null : $currentNamespace . '\\' . $name),
                    $tokens,
                    $i,
                    $line,
                    $file,
                    end($varLiteralValueStack) ?: [],
                    $classConstants,
                    empty($classNameStack) ? null : end($classNameStack),
                    empty($classParentStack) ? null : end($classParentStack),
                    $currentNamespace,
                    $useImports,
                    $hookRegistrations,
                    $hookInvocations,
                    $templateRefs,
                    $pendingTemplateHelperCalls,
                    $varAssignedFromFunctionStack,
                    $globIncludeDirs,
                    $rootRelativeIncludeDirs,
                    $definedConstants,
                    $skipStringIndices,
                    $functionCalls,
                    $scopedMethodCallPrefixes,
                );
                $literalPathScopeTop = count($literalPathNodeStack) - 1;
                $this->captureLiteralPathCall(
                    $this->literalPathBareFunctionKeys($name, $useFunctionImports[$name] ?? ($currentNamespace === '' ? null : $currentNamespace . '\\' . $name)),
                    $tokens,
                    $i,
                    $literalPathFunctionKeyStack[$literalPathScopeTop],
                    $literalPathNodeStack[$literalPathScopeTop],
                    $this->isLiteralPathSinkFunction($name),
                    $literalPathPropagationLinks,
                    $literalPathInputs,
                );
                $this->captureHookPassThroughParam(
                    $name,
                    $tokens,
                    $i,
                    $literalPathFunctionKeyStack[$literalPathScopeTop],
                    $literalPathNodeStack[$literalPathScopeTop],
                    $hookPassThroughParams,
                );
                if (!empty($closureVarDepthStack)) {
                    $closureScopeTop = count($closureVarDepthStack) - 1;
                    $closureHookScopeTop = count($closureHookPassThroughParamStack) - 1;
                    $this->captureClosureHookPassThroughParam(
                        $name,
                        $tokens,
                        $i,
                        $closureVarNameStack[$closureScopeTop],
                        $closureParamNamesStack[$closureScopeTop],
                        $closureHookPassThroughParamStack[$closureHookScopeTop],
                    );
                }
                continue;
            }

            // \SwiftQueue\License_Bridge::initialize() — a namespaced/fully-qualified class name
            // used as a `::` receiver tokenizes as a single T_NAME_QUALIFIED/T_NAME_FULLY_QUALIFIED
            // token (PHP 8.0+), never T_STRING, so it fell straight through the T_STRING branch
            // above and the class looked permanently unused despite this being an ordinary static
            // call. Mirrors that branch's `::` handling only — `new`/`instanceof` already resolve
            // these tokens correctly via captureClassNameAfter()'s CLASS_NAME_TOKENS check.
            if ($type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $nextNonWhitespace = $this->peekNextMeaningful($tokens, $i);

                if ($nextNonWhitespace === '::') {
                    $receiverClass = $this->resolveClassNameToken($value, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                    if ($receiverClass !== null) {
                        $classReferences[] = $this->shortClassName($value);

                        $target = $this->findScopedCallTarget($tokens, $i);
                        if ($target !== null) {
                            [$methodName, $methodNameIndex] = $target;
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                            $i = $methodNameIndex;

                            $literalPathScopeTop = count($literalPathNodeStack) - 1;
                            $this->captureLiteralPathCall(
                                [$receiverClass . '::' . $methodName],
                                $tokens,
                                $methodNameIndex,
                                $literalPathFunctionKeyStack[$literalPathScopeTop],
                                $literalPathNodeStack[$literalPathScopeTop],
                                false,
                                $literalPathPropagationLinks,
                                $literalPathInputs,
                            );

                            // \WP_CLI::add_command(...) — the exact same reflection-dispatch
                            // shape the bare T_STRING branch above already recognizes, just
                            // reached via a fully-qualified/namespaced call instead. Real-world
                            // shape (Elementor): `\WP_CLI::add_command('elementor experiments',
                            // WP_CLI::class)`, explicitly opting out of the file's own namespace
                            // for the real global WP_CLI class — this whole branch previously had
                            // no equivalent to the T_STRING branch's WP_CLI-specific check at all,
                            // so Elementor's own `Wp_Cli` command classes looked permanently
                            // unused regardless of the `Foo::class` second-argument fix above.
                            $this->recordWpCliAddCommandDispatch($this->shortClassName($value), $methodName, $tokens, $methodNameIndex, $currentNamespace, $useImports, $reflectionDispatchedClassNames);

                            // NestedPages\Helpers::view($row_view) — the namespaced/fully-
                            // qualified counterpart to the bare T_STRING branch's own
                            // $pendingParamSuffixCalls handling above (see its docblock for the
                            // real-world shape; wp-nested-pages calls Helpers::view() exactly this
                            // way, `use`-imported and namespace-qualified rather than a bare name).
                            $singleArgTokens = $this->argTokensAt($tokens, $methodNameIndex, 0);
                            if ($singleArgTokens !== null && $this->argTokensAt($tokens, $methodNameIndex, 1) === null) {
                                $argScopeTop = count($varLiteralAssignmentsStack) - 1;
                                $candidates = $this->resolveParamSuffixCallArgumentCandidates($singleArgTokens, $varLiteralAssignmentsStack[$argScopeTop]);
                                if ($candidates !== null) {
                                    $pendingParamSuffixCalls[] = new PendingParamSuffixCall($receiverClass, $methodName, $candidates);
                                }
                            }
                        }
                    }
                    continue;
                }

                // Foo\Bar\my_helper() / \My\Ns\init() / \add_action(...) — a namespaced or fully-
                // qualified call, invisible before this: the only call-detection dispatch fired
                // on T_STRING, so this token (and the '('-lookahead that would otherwise reach
                // it) was silently skipped entirely — a real, called function looked unused, and
                // a WP core call like `\add_action(...)` (explicitly opting out of the current
                // namespace for a core global — a real, common pattern in namespaced WP code) had
                // its hook registration go unrecognized too. Resolved to its unqualified tail
                // (shortClassName — same trim already used for a qualified class name) since
                // FunctionDef itself is namespace-blind too: a function can only ever be
                // *declared* with a bare name in PHP (the enclosing `namespace` block carries the
                // namespace, never the declaration's own name token), so matching call and
                // definition on the trimmed tail is consistent, not merely convenient. Shares
                // dispatchBareFunctionCall with the T_STRING branch below so both call shapes
                // recognize the exact same set of hook/template/glob/define/existence-check names.
                if ($nextNonWhitespace === '(') {
                    $shortName = $this->shortClassName($value);
                    if (in_array($shortName, self::FILE_EXISTENCE_GUARD_FUNCS, true)) {
                        $literalPathScopeTop = count($literalPathNodeStack) - 1;
                        $this->captureLiteralPathFileExistenceGuard(
                            $tokens,
                            $i,
                            $literalPathFunctionKeyStack[$literalPathScopeTop],
                            $literalPathNodeStack[$literalPathScopeTop],
                            $literalPathFileExistenceGuards,
                        );
                    }
                    $this->dispatchBareFunctionCall(
                        $shortName,
                        // Qualified/fully-qualified calls resolve deterministically (no runtime
                        // fallback the way a bare call has) — the real resolveFqcn() target,
                        // same rule a class reference would use.
                        $this->resolveFqcn($value, $currentNamespace, $useImports),
                        $tokens,
                        $i,
                        $line,
                        $file,
                        end($varLiteralValueStack) ?: [],
                        $classConstants,
                        empty($classNameStack) ? null : end($classNameStack),
                        empty($classParentStack) ? null : end($classParentStack),
                        $currentNamespace,
                        $useImports,
                        $hookRegistrations,
                        $hookInvocations,
                        $templateRefs,
                        $pendingTemplateHelperCalls,
                        $varAssignedFromFunctionStack,
                        $globIncludeDirs,
                        $rootRelativeIncludeDirs,
                        $definedConstants,
                        $skipStringIndices,
                        $functionCalls,
                        $scopedMethodCallPrefixes,
                    );
                    $literalPathScopeTop = count($literalPathNodeStack) - 1;
                    $this->captureLiteralPathCall(
                        $this->literalPathBareFunctionKeys($shortName, $this->resolveFqcn($value, $currentNamespace, $useImports)),
                        $tokens,
                        $i,
                        $literalPathFunctionKeyStack[$literalPathScopeTop],
                        $literalPathNodeStack[$literalPathScopeTop],
                        $this->isLiteralPathSinkFunction($shortName),
                        $literalPathPropagationLinks,
                        $literalPathInputs,
                    );
                    $this->captureHookPassThroughParam(
                        $shortName,
                        $tokens,
                        $i,
                        $literalPathFunctionKeyStack[$literalPathScopeTop],
                        $literalPathNodeStack[$literalPathScopeTop],
                        $hookPassThroughParams,
                    );
                    if (!empty($closureVarDepthStack)) {
                        $closureScopeTop = count($closureVarDepthStack) - 1;
                        $closureHookScopeTop = count($closureHookPassThroughParamStack) - 1;
                        $this->captureClosureHookPassThroughParam(
                            $shortName,
                            $tokens,
                            $i,
                            $closureVarNameStack[$closureScopeTop],
                            $closureParamNamesStack[$closureScopeTop],
                            $closureHookPassThroughParamStack[$closureHookScopeTop],
                        );
                    }
                    continue;
                }
            }

            $skipNextString = false;

            // String callbacks e.g. add_action('init', 'my_callback')
            if ($type === T_CONSTANT_ENCAPSED_STRING) {
                if (isset($skipStringIndices[$i])) {
                    continue;
                }
                $stringVal = $this->stripQuotes($value);

                // `array( $this, 'render_' . $column . '_column' )` — the plain-concatenation
                // counterpart to isThisArrayCallbackReceiverAt's interpolated-string shape (real-
                // world case: WooCommerce's `render_columns()`/`render_column()` dispatchers).
                // Recorded against the CURRENT enclosing method the same way the interpolated
                // shape is — see $selfDispatchSuffixes' own docblock and
                // resolvePrefixVarSuffixSelfDispatchTemplate's for why prefix and suffix are both
                // captured here (unlike the interpolated shape, which has evidence for a suffix
                // only).
                $selfDispatchTemplate = $this->resolvePrefixVarSuffixSelfDispatchTemplate($tokens, $i);
                if ($selfDispatchTemplate !== null) {
                    $currentDefIndex = empty($functionDefIndexForBodyStack) ? null : end($functionDefIndexForBodyStack);
                    if ($currentDefIndex !== null) {
                        $currentDef = $functionDefs[$currentDefIndex];
                        if ($currentDef->isMethod && $currentDef->ownerClass !== null) {
                            $prefixSuffixKey = $currentDef->ownerClass . '::' . $currentDef->name;
                            if (!isset($selfDispatchPrefixSuffixTemplates[$prefixSuffixKey])) {
                                $selfDispatchPrefixSuffixTemplates[$prefixSuffixKey] = [];
                            }
                            $selfDispatchPrefixSuffixTemplates[$prefixSuffixKey][] = $selfDispatchTemplate;
                        }
                    }
                }

                // 'sales_by_date' => array( ... ); — a literal array key, at *any* nesting
                // depth, anywhere in a class body. Real-world shape (WooCommerce):
                // `WC_Admin_Reports::get_reports()` returns a 3-level-nested array literal
                // (`$reports['orders']['reports']['sales_by_date'] = [...]`) — deeper than
                // $classArrayKeyLiterals' own targeted captures (a top-level `return [...]`, a
                // `$var['key'] = ...;` assignment, a flat array assigned to `$this->prop`) can
                // reach. Rather than tracing the exact variable-assignment/mutation/return flow
                // through several conditionals and two `apply_filters()` hops, this simply
                // credits every `'literal' =>` pair textually found in the same class, the same
                // "coarse net" trade-off $classArrayKeyLiterals already makes elsewhere — noise
                // from unrelated keys (`'title'`, `'description'`, ...) is harmless on its own,
                // since a spurious candidate that matches no real class/function definition is
                // simply never looked up.
                $arrowIndex = $this->peekNextMeaningfulIndex($tokens, $i);
                if ($arrowIndex !== null && is_array($tokens[$arrowIndex]) && $tokens[$arrowIndex][0] === T_DOUBLE_ARROW) {
                    $arrayKeyOwnerClass = empty($classNameStack) ? null : end($classNameStack);
                    if ($arrayKeyOwnerClass !== null) {
                        if (!isset($classArrayKeyLiterals[$arrayKeyOwnerClass])) {
                            $classArrayKeyLiterals[$arrayKeyOwnerClass] = [];
                        }
                        $classArrayKeyLiterals[$arrayKeyOwnerClass][] = $stringVal;
                    }
                }

                // 'NestedPages\Form\Listeners\\' . $class; / 'Jetpack_Tiled_Gallery_Layout_' .
                // ucfirst( $this->atts['type'] ); — a literal concatenated with a resolved
                // transform chain (see literalConcatVarAt()'s own docblock for both real-world
                // shapes). Checked here (wherever this literal appears), not gated to a bare
                // `$var = ` assignment, since wp-nested-pages' own real assignment target is
                // `$this->handlers[$key]->class` — an lvalue far more complex than this parser
                // otherwise attempts to track. Two independent, alternative gates on the literal
                // itself (a namespace separator is unambiguous on its own; a capitalized
                // underscore-joined identifier is a *shape* heuristic, not proof — Jetpack's own
                // literal never reaches a `new $var(...)` this parser could cross-check against,
                // since the concatenation and the `new` are two separate statements) — see
                // $classNameTransformTemplates' own docblock for how ClassAnalyzer/FileAnalyzer
                // resolve either.
                $literalConcatVar = $this->literalConcatVarAt($tokens, $i, $transformChainVars);
                if ($literalConcatVar !== null) {
                    [$concatLiteral, $concatChain] = $literalConcatVar;
                    $transformOwnerClass = empty($classNameStack) ? null : end($classNameStack);
                    if (
                        $transformOwnerClass !== null
                        && $concatChain[1] !== []
                        && (str_ends_with($concatLiteral, '\\') || preg_match('/^\\\\?[A-Z][A-Za-z0-9_]*_$/', $concatLiteral) === 1)
                    ) {
                        if (!isset($classNameTransformTemplates[$transformOwnerClass])) {
                            $classNameTransformTemplates[$transformOwnerClass] = [];
                        }
                        $classNameTransformTemplates[$transformOwnerClass][] = [$concatLiteral, $concatChain[1], ''];

                        // Elementor's widget/control/element registries build the dynamic class
                        // name from a `foreach` loop variable, not a class-body array literal:
                        // `foreach ($build_widgets_filename as $widget_filename) { $class_name =
                        // str_replace('-', '_', $widget_filename); $class_name = __NAMESPACE__ .
                        // '\Widget_' . $class_name; new $class_name(); }` — $concatChain[0] is
                        // the transform's own source variable ("$widget_filename"), and when that
                        // name matches an actively-tracked foreach loop (see
                        // $foreachLoopVarNameStack/$foreachLoopVarValuesStack, populated from a
                        // local flat literal array by parseForeachLiteralArrayLoop), its concrete
                        // values ARE this class's domain — no unrelated class-body array literal
                        // capture is needed to cross-product against.
                        for ($k = count($foreachLoopVarNameStack) - 1; $k >= 0; $k--) {
                            if ($foreachLoopVarNameStack[$k] === $concatChain[0]) {
                                if (!isset($classArrayKeyLiterals[$transformOwnerClass])) {
                                    $classArrayKeyLiterals[$transformOwnerClass] = [];
                                }
                                array_push($classArrayKeyLiterals[$transformOwnerClass], ...$foreachLoopVarValuesStack[$k]);
                                break;
                            }
                        }
                    }
                }

                // A literal directly concatenated with a currently-tracked bounded for-loop
                // variable (`'prefix_' . $i` inside `for ($i = 1; $i < 5; $i++) { ... }`) —
                // computed once per literal and shared by both the callback-name and file-path
                // checks below, since exactly one of them ever consumes it for a given literal
                // (a callback-shaped identifier can't also end in '.php' — PHP identifiers don't
                // contain dots). See resolveForLoopConcatenatedLiteral's own docblock for the
                // real-world shapes this covers. Falls back to the foreach-over-literal-array
                // sibling (resolveForeachConcatenatedLiteral) when the for-loop mechanism doesn't
                // match — mutually exclusive in practice (a given variable name is tracked by at
                // most one of the two loop kinds at once), and both return the same shape, so
                // every consumer below treats the result identically regardless of which loop
                // kind actually produced it.
                $forLoopEnumeration = $this->resolveForLoopConcatenatedLiteral($tokens, $i, $stringVal, $forLoopVarNameStack, $forLoopVarValuesStack)
                    ?? $this->resolveForeachConcatenatedLiteral($tokens, $i, $stringVal, $foreachLoopVarNameStack, $foreachLoopVarValuesStack);
                // Namespaced/class-scoped code commonly builds a fully-qualified callback string
                // via concatenation — `__NAMESPACE__ . '\my_callback'` or `__CLASS__ .
                // '::my_method'` (or writes the FQ form out directly: '\My\Namespace\my_callback')
                // — the concatenation itself isn't tracked, but the literal's own trailing segment
                // after the last "\" or "::" is exactly the bare name FunctionDef/ScopedMethodCall
                // already match against everywhere else (this codebase treats functions/classes
                // as one flat, namespace-agnostic pool throughout, e.g. shortClassName()).
                // Stripping it here — rather than requiring a leading-non-separator match — is
                // what lets a call like `add_action('admin_init', __CLASS__ .
                // '::admin_updates')` still register as a use of that method, instead of the
                // leading "::" making the whole literal fail looksLikeCallback()'s identifier
                // regex and silently going unmatched. Whichever separator's occurrence ends
                // furthest right wins, so a mixed '\Name\Space\Foo::method' literal still resolves
                // to the true trailing segment ("method"), not just whichever separator happens
                // to be checked first.
                $sepEnd = null;
                $sepPos = null;
                $sepUsed = null;
                foreach (['\\', '::'] as $sep) {
                    $pos = strrpos($stringVal, $sep);
                    if ($pos !== false) {
                        $end = $pos + strlen($sep);
                        if ($sepEnd === null || $end > $sepEnd) {
                            $sepEnd = $end;
                            $sepPos = $pos;
                            $sepUsed = $sep;
                        }
                    }
                }
                $callbackName = $sepEnd !== null ? substr($stringVal, $sepEnd) : $stringVal;
                $isCallbackShaped = $this->looksLikeCallback($callbackName);
                // Only meaningful when there's no '\\'/'::' separator (the concatenation is with
                // the *whole* callback name, not a fully-qualified prefix built some other way —
                // that composite shape has no confirmed real-world case and isn't attempted) and
                // the prefix genuinely looks like a callback identifier in the first place —
                // tracked separately from $forLoopEnumeration itself so the ".php"-suffixed
                // file-path check further below can tell whether this literal's enumeration was
                // already consumed here, without re-deriving the same condition twice.
                $forLoopCallbackEnumeration = ($sepEnd === null && $isCallbackShaped) ? $forLoopEnumeration : null;
                // A `foreach`/for-loop concatenation whose own PREFIX literal already ends in a
                // namespace separator (real-world shape, litespeed-cache:
                // `add_action('litespeed_load_thirdparty', 'LiteSpeed\Thirdparty\\' . $cls .
                // '::detect');` inside `foreach ($third_cls as $cls) { ... }`) is a fully-
                // qualified string-callable whose *class* segment moves with the loop variable —
                // $sepEnd/$isCallbackShaped above are computed from $stringVal alone (just the
                // prefix, ending in "\\", so $callbackName there is empty and $isCallbackShaped
                // is false), so this needs each concrete enumerated string re-split on its own
                // rightmost '\\'/'::' independently, not the shared prefix-only split above.
                // Mutually exclusive with $forLoopCallbackEnumeration in practice: a prefix
                // ending in "\\"/"::" (required here) always makes $sepEnd non-null there.
                $forLoopClassMethodEnumeration = null;
                if ($forLoopCallbackEnumeration === null && $forLoopEnumeration !== null && $forLoopEnumeration[0] !== []) {
                    $candidateSplits = [];
                    foreach ($forLoopEnumeration[0] as $candidate) {
                        $candidateSepEnd = null;
                        $candidateSepPos = null;
                        foreach (['\\', '::'] as $csep) {
                            $cpos = strrpos($candidate, $csep);
                            if ($cpos !== false) {
                                $cend = $cpos + strlen($csep);
                                if ($candidateSepEnd === null || $cend > $candidateSepEnd) {
                                    $candidateSepEnd = $cend;
                                    $candidateSepPos = $cpos;
                                }
                            }
                        }
                        if (
                            $candidateSepEnd === null
                            || $candidateSepPos === 0
                            || !$this->looksLikeCallback(substr($candidate, $candidateSepEnd))
                            || !$this->looksLikeCallback($this->shortClassName(substr($candidate, 0, $candidateSepPos)))
                        ) {
                            $candidateSplits = null;
                            break;
                        }
                        // Real WordPress source can only end a single-quoted (or interpolation-
                        // free double-quoted) literal in a namespace separator by doubling the
                        // backslash (`'LiteSpeed\Thirdparty\\'` — an unescaped single trailing
                        // backslash would instead escape the closing quote itself), which decodes
                        // to one backslash at runtime but survives raw and doubled here, since
                        // stripQuotes() deliberately never processes PHP escape sequences (see
                        // its own docblock). Collapsed back to a single separator so the
                        // reconstructed FQCN matches the real, singly-separated one every
                        // ClassDef/$currentNamespace value in this parser already uses.
                        $rawClassPart = substr($candidate, 0, $candidateSepPos);
                        $classPart = preg_replace('/\\\\{2,}/', '\\', $rawClassPart) ?? $rawClassPart;
                        $candidateSplits[] = [$classPart, substr($candidate, $candidateSepEnd)];
                    }
                    $forLoopClassMethodEnumeration = $candidateSplits === null ? null : [$candidateSplits, $forLoopEnumeration[1]];
                }
                if ($forLoopClassMethodEnumeration !== null) {
                    [$classMethodSplits, $lastForLoopIndex] = $forLoopClassMethodEnumeration;
                    foreach ($classMethodSplits as [$classPart, $methodPart]) {
                        $classReferences[] = $this->shortClassName($classPart);
                        $scopedMethodCalls[] = new ScopedMethodCall(
                            $this->resolveFqcn($classPart, $currentNamespace, $useImports),
                            $methodPart,
                        );
                    }
                    $i = $lastForLoopIndex;
                } elseif ($forLoopCallbackEnumeration !== null) {
                    // Real-world shape (Sydney theme): `'render_callback' =>
                    // 'sydney_partial_slider_title_' . $i` inside `for ($i = 1; $i < 5; $i++)` —
                    // enumerate every concrete name the loop actually produces instead of the
                    // single truncated prefix a plain (), which would both fail to match any real
                    // declaration and — if $receiverClass ever resolves here — pollute
                    // scopedCalled with a name nothing declares.
                    [$enumeratedNames, $lastForLoopIndex] = $forLoopCallbackEnumeration;
                    $receiverClass = $this->arrayCallbackReceiverClass($tokens, $i, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                    foreach ($enumeratedNames as $enumeratedName) {
                        if ($receiverClass !== null) {
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $enumeratedName);
                        } else {
                            $extraCandidateFqcn = $useFunctionImports[$enumeratedName]
                                ?? ($currentNamespace === '' ? null : $currentNamespace . '\\' . $enumeratedName);
                            $functionCalls[] = new FunctionCall($enumeratedName, $line, $file, $extraCandidateFqcn);
                        }
                    }
                    $i = $lastForLoopIndex;
                } elseif ($isCallbackShaped) {
                    // [$this, 'method'] / [self::class, 'method'] / [Foo::class, 'method'] /
                    // ['Foo', 'method'] — the common add_action/add_filter array-callback shape
                    // — has a resolvable receiver often enough to be worth checking here, same
                    // as the $this->/self::/parent::/Foo:: call scoping above. Anything else
                    // (a plain variable receiver, or not an array callback at all) falls back to
                    // the existing name-only pool.
                    $receiverClass = $this->arrayCallbackReceiverClass($tokens, $i, $classNameStack, $classParentStack, $currentNamespace, $useImports);

                    // Not an array callback but still a resolvable receiver: a bare
                    // 'Some_Class::method' string, the WP customizer/REST-controller shape for
                    // 'render_callback' => 'Astra_Customizer_Partials::render_partial_site_title'
                    // (no array involved). The literal's own class segment (before the winning
                    // '::') is the receiver — same trailing-segment-wins logic as $callbackName,
                    // just keeping the piece before the separator instead of after. Skipped for
                    // self/parent/static since those are only meaningful inside an actual class
                    // body's scope, not resolvable from a bare string literal.
                    if ($receiverClass === null && $sepUsed === '::' && $sepPos > 0) {
                        $classPartRaw = substr($stringVal, 0, $sepPos);
                        // LiteSpeed Cache registers its uninstall callback as
                        // `__NAMESPACE__ . '\Activation::uninstall_litespeed_cache'`. The
                        // literal fragment's leading slash normally means an explicitly global
                        // class, but here it is only the separator following the namespace magic
                        // constant; combine that known prefix before FQCN resolution. Restricted
                        // to the immediately-adjacent token shape so an independently-written
                        // `'\Global_Class::method'` remains global.
                        $namespaceConcatenated = $this->isNamespaceConcatenatedClassCallback($tokens, $i, $classPartRaw);
                        $classPart = $this->shortClassName($classPartRaw);
                        if (!in_array($classPart, ['self', 'parent', 'static'], true)
                            && $this->looksLikeCallback($classPart)
                        ) {
                            $classReferences[] = $classPart;
                            $receiverClass = $namespaceConcatenated
                                ? ltrim($currentNamespace . $classPartRaw, '\\')
                                : $this->resolveFqcn($classPartRaw, $currentNamespace, $useImports);
                        }
                    }

                    // `array($this, 'footer_html_' . $index)` inside a `for` loop wiring N
                    // numbered component slots (real-world case: Astra theme's footer/header
                    // builder) — $callbackName here is only 'footer_html_', a truncated prefix,
                    // not the real method name; the suffix depends on a runtime loop counter this
                    // token-based parser can't evaluate. Recording it as an exact-match call would
                    // both miss the real methods (footer_html_1, footer_html_2, ...) and pollute
                    // scopedCalled with a name nothing ever declares. $sepEnd === null keeps this
                    // from firing on the already-handled '\\'/'::' concatenation shapes above,
                    // which build the *prefix* dynamically and know the *suffix* literally — the
                    // opposite direction from this one.
                    //
                    // Deliberately requires a resolved array-callback $receiverClass — without
                    // that, "is this string actually a callback name at all" has no signal beyond
                    // looksLikeCallback()'s bare identifier-shape regex, and a prefix match on an
                    // unscoped name is far riskier than an unscoped *exact* match: plain string-
                    // building unrelated to any callback (an option key, a CSS class, a hook TAG
                    // argument — 'astra_footer_html_' . $index as add_action()'s *first* arg,
                    // never a callback name) matches this same shape constantly, and a short
                    // incidental prefix ('menu', 'h', even '_') would str_starts_with() against
                    // huge swaths of unrelated real method/function names project-wide, hiding
                    // genuinely dead code. An array-callback's resolved receiver ($this, Foo::
                    // class, a literal 'Foo') is a much narrower, WP-idiomatic signal that this
                    // position really is a callback.
                    if ($sepEnd === null && $receiverClass !== null && $this->peekNextMeaningful($tokens, $i) === '.') {
                        $scopedMethodCallPrefixes[] = new ScopedMethodCallPrefix($receiverClass, $callbackName);
                    } elseif ($receiverClass !== null) {
                        $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $callbackName);
                    } else {
                        // Real-world shape (Sakurairo theme): `__NAMESPACE__ . '\my_handler'` —
                        // the whole point of the __NAMESPACE__ concatenation is resolving into
                        // the calling file's own namespace, so the same "extra candidate"
                        // treatment a bare call gets applies here too — including a `use
                        // function`-imported name taking priority, same as the plain bare-call
                        // dispatch site above.
                        $extraCandidateFqcn = $useFunctionImports[$callbackName]
                            ?? ($currentNamespace === '' ? null : $currentNamespace . '\\' . $callbackName);
                        $functionCalls[] = new FunctionCall($callbackName, $line, $file, $extraCandidateFqcn);
                    }
                }
                // Any ".php"-suffixed literal is a plausible file reference — e.g. ACF's
                // 'render_template' => get_template_directory() . '/blocks/foo.php', page
                // template registration arrays, or other config-driven includes that never
                // pass through include()/require(). Cheap net, no call-site required.
                //
                // A prefix concatenated with a tracked bounded for-loop variable
                // (`'icons-v6-' . $i . '.php'`) doesn't end in '.php' on its own — the plain
                // check below would never fire — but was already computed once above
                // ($forLoopEnumeration); only consumed here when the callback branch didn't
                // already claim it (a callback-shaped identifier can't also end in '.php', so in
                // practice these two are mutually exclusive per literal, never a double-count).
                // Same exclusion for $forLoopClassMethodEnumeration — a "Class::method" string
                // can't also end in '.php'.
                if ($forLoopEnumeration !== null && $forLoopCallbackEnumeration === null && $forLoopClassMethodEnumeration === null) {
                    [$enumeratedPaths, $lastForLoopIndex] = $forLoopEnumeration;
                    foreach ($enumeratedPaths as $enumeratedPath) {
                        if (str_ends_with($enumeratedPath, '.php')) {
                            $phpPathStrings[] = $enumeratedPath;
                        }
                    }
                    $i = $lastForLoopIndex;
                } elseif (str_ends_with($stringVal, '.php')) {
                    $phpPathStrings[] = $stringVal;
                }
                continue;
            }

            if (in_array($type, self::INCLUDE_KEYWORDS, true)) {
                $hasIncludeStatement = true;
                // Mark every currently-open function/method scope, not just the innermost — a
                // require inside a closure nested within a named method's body must still count
                // as that enclosing method's own body containing one (see
                // FunctionDef::$hasIncludeInBody's own docblock).
                foreach (array_keys($functionHasIncludeStack) as $depthIndex) {
                    $functionHasIncludeStack[$depthIndex] = true;
                }
                $ref = $this->parseIncludeRef($tokens, $i, $line, $file, token_name($type));
                if ($ref !== null) {
                    $templateRefs[] = $ref;
                }

                // A literal may reach this direct include through a wrapper parameter rather
                // than appearing in the statement itself. The resolver only accepts it after a
                // preceding fixed prefix/suffix path template, so `require $arbitrary_param`
                // alone does not turn every call to this function into a file reference.
                $literalPathScopeTop = count($literalPathNodeStack) - 1;
                $literalPathFunctionKey = $literalPathFunctionKeyStack[$literalPathScopeTop];
                if ($literalPathFunctionKey !== null) {
                    $includeSource = $this->resolveLiteralPathIncludeSource(
                        $tokens,
                        $i,
                        $literalPathNodeStack[$literalPathScopeTop],
                    );
                    if ($includeSource !== null) {
                        [$sourceNode, $prefix, $suffix] = $includeSource;
                        $literalPathPropagationLinks[] = new LiteralPathPropagationLink(
                            $sourceNode,
                            prefix: $prefix,
                            suffix: $suffix,
                            isSink: true,
                        );
                    }
                }

                // `require get_template_directory() . '/inc/options/' . $key . '-options.php';`
                // — a directory-literal prefix, then a dynamic middle segment, then a literal
                // suffix. findTrailingStringLiteral() above (feeding parseIncludeRef) would only
                // ever surface '-options.php' here — the *last* literal in the expression, "last
                // one wins" same as everywhere else in this parser — which is close to useless:
                // real-world example (Kadence theme), the meaningful signal is the *directory*
                // prefix, discarded because a suffix literal happens to come after the variable.
                // Same "one bootstrap statement bulk-loads a whole directory, no per-file
                // reference exists to find" shape as glob()/scandir(), just via string
                // concatenation with a loop variable instead of a directory-listing call.
                $dirPrefix = $this->findIncludeDirPrefixBeforeVariable($tokens, $i);
                if ($dirPrefix !== null) {
                    [$literal, $isRootRelative] = $dirPrefix;
                    if ($isRootRelative) {
                        $rootRelativeIncludeDirs[] = $literal;
                    } else {
                        $globIncludeDirs[] = $literal;
                    }
                }
            }
        }

        $dirnameAncestorUpLevels = $this->maxDirnameAncestorUpLevels($tokens);

        return new ParseResult(
            file: $file,
            // array_values(): $functionDefs is mutated by index (not just appended to) once a
            // function/method body turns out to contain an include — see FunctionDef::
            // $hasIncludeInBody. Still a genuine list at runtime (every index written back is
            // one already inside the array's own current bounds), just not something phpstan can
            // prove from a plain index-assignment; array_values() re-establishes that.
            functionDefs: array_values($functionDefs),
            functionCalls: $functionCalls,
            hookRegistrations: $hookRegistrations,
            hookInvocations: $hookInvocations,
            templateRefs: $templateRefs,
            phpPathStrings: $phpPathStrings,
            classDefs: $classDefs,
            classReferences: $classReferences,
            scopedMethodCalls: $scopedMethodCalls,
            scopedMethodCallPrefixes: $scopedMethodCallPrefixes,
            reflectionDispatchedClassNames: $reflectionDispatchedClassNames,
            propertyAssignedClasses: $propertyAssignedClasses,
            propertyMethodCalls: $propertyMethodCalls,
            pendingReturnTypedCalls: $pendingReturnTypedCalls,
            pendingDirectoryLoaderCalls: $pendingDirectoryLoaderCalls,
            traitUsages: $traitUsages,
            globIncludeDirs: $globIncludeDirs,
            rootRelativeIncludeDirs: $rootRelativeIncludeDirs,
            hasIncludeStatement: $hasIncludeStatement,
            useImports: $useImports,
            functionLiteralReturns: $functionLiteralReturns,
            pendingTemplateHelperCalls: $pendingTemplateHelperCalls,
            functionParamSuffixReturns: $functionParamSuffixReturns,
            pendingParamSuffixCalls: $pendingParamSuffixCalls,
            selfDispatchSuffixes: $selfDispatchSuffixes,
            pendingSelfDispatchCalls: $pendingSelfDispatchCalls,
            selfDispatchPrefixSuffixTemplates: $selfDispatchPrefixSuffixTemplates,
            classArrayKeyLiterals: array_map(fn(array $keys): array => array_values(array_unique($keys)), $classArrayKeyLiterals),
            literalPathPropagationLinks: $literalPathPropagationLinks,
            literalPathInputs: $literalPathInputs,
            literalPathFileExistenceGuards: array_keys($literalPathFileExistenceGuards),
            hookPassThroughParams: $hookPassThroughParams,
            classNameTransformTemplates: $classNameTransformTemplates,
            functionArrayReturns: $functionArrayReturns,
            functionNameTransformTemplates: $functionNameTransformTemplates,
            dirnameAncestorUpLevels: $dirnameAncestorUpLevels,
        );
    }

    /** @param list<Token> $tokens */
    private function parseFunctionDef(array $tokens, int $i, string $file, bool $isMethod = false, ?string $ownerClass = null): ?FunctionDef
    {
        // function [whitespace] <name> (
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        if (!isset($tokens[$j])) {
            return null;
        }

        $next = $tokens[$j];

        // Anonymous function or arrow function — skip
        if (is_string($next) && $next === '(') {
            return null;
        }

        // function &name(...) — skip reference markers
        if (is_string($next) && $next === '&') {
            $j++;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            $next = $tokens[$j] ?? null;
        }

        if (!is_array($next) || $next[0] !== T_STRING) {
            return null;
        }

        // A real declaration always has a parameter list, even an empty `()` — this is what
        // rejects `use function add_action;` / `use function pings_open;` file-level imports:
        // token-wise they're `function <name>` too (T_FUNCTION T_STRING), but followed by `;` or
        // `,` instead of `(`, so without this check they'd be misparsed as function definitions
        // nobody calls (the real add_action()/add_filter() calls elsewhere in the file don't
        // even land in functionCalls — they're diverted into hookRegistrations instead — so the
        // phantom definition would be reported as an unused function despite being called).
        $nameEndIndex = $j;
        $k = $nameEndIndex + 1;
        while (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
            $k++;
        }
        if (!isset($tokens[$k]) || $tokens[$k] !== '(') {
            return null;
        }

        return new FunctionDef(
            $next[1],
            $next[2],
            $file,
            $isMethod,
            $ownerClass,
            parameters: $this->parseFunctionParameterNames($tokens, $k),
        );
    }

    private function literalPathFunctionKey(FunctionDef $def): string
    {
        return $def->isMethod && $def->ownerClass !== null
            ? $def->ownerClass . '::' . $def->name
            : $def->fqcn;
    }

    /** @return array<string,string> */
    private function literalPathParameterNodes(FunctionDef $def): array
    {
        $nodes = [];
        $functionKey = $this->literalPathFunctionKey($def);
        foreach ($def->parameters as $position => $parameter) {
            $nodes[$parameter] = $this->literalPathParameterNode($functionKey, $position);
        }
        return $nodes;
    }

    private function literalPathParameterNode(string $functionKey, int $position): string
    {
        return $functionKey . '#param:' . $position;
    }

    private function literalPathReturnNode(string $functionKey): string
    {
        return $functionKey . '#return';
    }

    private function literalPathLocalNode(string $functionKey, int &$counter): string
    {
        $counter++;
        return $functionKey . '#local:' . $counter;
    }

    /**
     * A property node's identity is the (class, property) pair itself, not a per-function
     * counter like literalPathLocalNode() — it must resolve identically regardless of which
     * method reads it (a constructor writes it, an unrelated method reads it back).
     */
    private function literalPathPropertyNode(string $ownerClass, string $propName): string
    {
        return $ownerClass . '::$' . $propName;
    }

    /**
     * Captures assignment links only when the target is a plain local or literal-keyed array
     * element and the RHS can be traced to one existing graph node. The Blocksy paths use both
     * forms (`$path` and `$args['path']`), while allowing a completely arbitrary RHS here would
     * turn ordinary application transformations into fake file references.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     * @param array<string,true> $fileExistenceGuards
     * @param list<LiteralPathPropagationLink> $links
     * @param list<LiteralPathInput> $inputs
     * @param array<string,string> $useImports
     * @param array<string,list<string>> $functionLiteralReturns
     * @param array<string,array<string,true>> $trackedProperties
     * @param array<string,array<string,string>> $propertyLiteralDefaults
     */
    private function captureLiteralPathAssignment(
        array $tokens,
        int $variableIndex,
        string $functionKey,
        string $currentNamespace,
        array $useImports,
        ?string $currentClass,
        ?string $currentParent,
        array &$nodes,
        array $fileExistenceGuards,
        int &$counter,
        array &$links,
        array &$inputs,
        array $functionLiteralReturns = [],
        array $trackedProperties = [],
        array $propertyLiteralDefaults = [],
    ): void {
        $target = $this->literalPathAssignmentTarget($tokens, $variableIndex);
        if ($target === null) {
            return;
        }
        [$address, $operatorIndex, $isConcatAssignment] = $target;

        if ($isConcatAssignment) {
            $sourceNode = $nodes[$address] ?? null;
            $suffix = $this->literalPathSingleStringRhs($tokens, $operatorIndex);
            if ($sourceNode === null || $suffix === null) {
                $this->invalidateLiteralPathAddress($nodes, $address);
                return;
            }
            $targetNode = $this->literalPathLocalNode($functionKey, $counter);
            $links[] = new LiteralPathPropagationLink($sourceNode, $targetNode, suffix: $suffix);
            $nodes[$address] = $targetNode;
            return;
        }

        if ($this->isLiteralPathSameVariableWpParseArgs($tokens, $operatorIndex, $address, $nodes)) {
            // wp_parse_args($args, ...) preserves an explicitly supplied array key. Blocksy
            // normalizes its `$args` this way before reading `$args['name']`; retaining the
            // parameter node is both exact for literal supplied keys and much narrower than
            // treating arbitrary array-returning functions as pass-throughs.
            return;
        }

        $source = $this->resolveLiteralPathAssignmentSource(
            $tokens,
            $operatorIndex,
            $nodes,
            $currentNamespace,
            $useImports,
            $currentClass,
            $currentParent,
            $functionKey,
            $functionLiteralReturns,
            $trackedProperties,
            $propertyLiteralDefaults,
            $links,
            $inputs,
        );
        if ($source === null) {
            $this->invalidateLiteralPathAddress($nodes, $address);
            return;
        }

        [$sourceNode, $prefix, $suffix, $guardKey] = $source;
        if ($guardKey !== null && !isset($fileExistenceGuards[$guardKey])) {
            $this->invalidateLiteralPathAddress($nodes, $address);
            return;
        }
        $targetNode = $this->literalPathLocalNode($functionKey, $counter);
        $links[] = new LiteralPathPropagationLink(
            $sourceNode,
            $targetNode,
            $prefix,
            $suffix,
            fileExistenceGuardKeys: $guardKey === null ? [] : [$guardKey],
        );
        $nodes[$address] = $targetNode;
    }

    /**
     * $variableIndex points at (what might be) the `$this` of `$this->propName = <expr>;` — a
     * plain `=` only (no `.=`, no array-key target — no real-world evidence for either on a
     * property yet, unlike literalPathAssignmentTarget()'s local-variable counterpart).
     *
     * @param list<Token> $tokens
     * @return array{string,int}|null [property name, `=` token index]
     */
    private function literalPathThisPropertyAssignmentTarget(array $tokens, int $variableIndex): ?array
    {
        if (!is_array($tokens[$variableIndex]) || $tokens[$variableIndex][0] !== T_VARIABLE || $tokens[$variableIndex][1] !== '$this') {
            return null;
        }
        $arrowIndex = $this->peekNextMeaningfulIndex($tokens, $variableIndex);
        if ($arrowIndex === null || !is_array($tokens[$arrowIndex]) || $tokens[$arrowIndex][0] !== T_OBJECT_OPERATOR) {
            return null;
        }
        $propIndex = $this->peekNextMeaningfulIndex($tokens, $arrowIndex);
        if ($propIndex === null || !is_array($tokens[$propIndex]) || $tokens[$propIndex][0] !== T_STRING) {
            return null;
        }
        $equalsIndex = $this->peekNextMeaningfulIndex($tokens, $propIndex);
        if ($equalsIndex === null || $tokens[$equalsIndex] !== '=') {
            return null;
        }
        return [$tokens[$propIndex][1], $equalsIndex];
    }

    /**
     * `$this->propName = <expr>;` — the property-scoped counterpart to
     * captureLiteralPathAssignment()'s local-variable tracking just above, needed for a
     * constructor-stores-parameter-then-a-different-method-reads-it class. Real-world shape
     * (Wordfence's `wfView`): `public function __construct( $view, $data = array() ) { ...
     * $this->view = $view; ... }`, read back by a *different* method, `render()`. The property
     * node is a single, stable id per (class, property) — see literalPathPropertyNode()'s own
     * docblock — rather than a per-function counter-based local node, since it must resolve
     * identically no matter which method reads it. Only ever marks a property "tracked" (added
     * to $trackedProperties, consulted by literalPathNodeFromTokens()'s own `$this->` case) on a
     * *resolved* source — an unresolvable RHS (Wordfence's own `$this->view_path = WORDFENCE_PATH
     * . 'views';`, an opaque constant) leaves the property untracked, which
     * literalPathNodeFromTokens() then treats as an ignorable term rather than a broken chain —
     * see isLiteralPathIgnorablePropertyExpression()'s own docblock.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     * @param array<string,true> $fileExistenceGuards
     * @param array<string,array<string,true>> $trackedProperties
     * @param list<LiteralPathPropagationLink> $links
     * @param array<string,string> $useImports
     * @param array<string,list<string>> $functionLiteralReturns
     * @param array<string,array<string,string>> $propertyLiteralDefaults
     */
    private function captureLiteralPathPropertyAssignment(
        array $tokens,
        int $variableIndex,
        string $functionKey,
        string $currentNamespace,
        array $useImports,
        ?string $currentClass,
        ?string $currentParent,
        array $nodes,
        array $fileExistenceGuards,
        array &$trackedProperties,
        array &$links,
        array $functionLiteralReturns,
        array $propertyLiteralDefaults,
    ): void {
        if ($currentClass === null) {
            return;
        }
        $target = $this->literalPathThisPropertyAssignmentTarget($tokens, $variableIndex);
        if ($target === null) {
            return;
        }
        [$propName, $equalsIndex] = $target;

        $source = $this->resolveLiteralPathAssignmentSource(
            $tokens,
            $equalsIndex,
            $nodes,
            $currentNamespace,
            $useImports,
            $currentClass,
            $currentParent,
            $functionKey,
            $functionLiteralReturns,
            $trackedProperties,
            $propertyLiteralDefaults,
        );
        if ($source === null) {
            unset($trackedProperties[$currentClass][$propName]);
            return;
        }
        [$sourceNode, $prefix, $suffix, $guardKey] = $source;
        if ($guardKey !== null && !isset($fileExistenceGuards[$guardKey])) {
            unset($trackedProperties[$currentClass][$propName]);
            return;
        }
        $links[] = new LiteralPathPropagationLink(
            $sourceNode,
            $this->literalPathPropertyNode($currentClass, $propName),
            $prefix,
            $suffix,
            fileExistenceGuardKeys: $guardKey === null ? [] : [$guardKey],
        );
        $trackedProperties[$currentClass][$propName] = true;
    }

    /**
     * @param list<Token> $tokens
     * @return array{string,int,bool}|null [target address, operator index, is `.=`]
     */
    private function literalPathAssignmentTarget(array $tokens, int $variableIndex): ?array
    {
        if (!is_array($tokens[$variableIndex]) || $tokens[$variableIndex][0] !== T_VARIABLE) {
            return null;
        }
        $address = $tokens[$variableIndex][1];
        $nextIndex = $this->peekNextMeaningfulIndex($tokens, $variableIndex);
        if ($nextIndex === null) {
            return null;
        }
        if ($tokens[$nextIndex] === '=') {
            return [$address, $nextIndex, false];
        }
        if (is_array($tokens[$nextIndex]) && $tokens[$nextIndex][0] === T_CONCAT_EQUAL) {
            return [$address, $nextIndex, true];
        }
        if ($tokens[$nextIndex] !== '[') {
            return null;
        }
        $keyIndex = $this->peekNextMeaningfulIndex($tokens, $nextIndex);
        if ($keyIndex === null || !is_array($tokens[$keyIndex]) || $tokens[$keyIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $closeIndex = $this->peekNextMeaningfulIndex($tokens, $keyIndex);
        if ($closeIndex === null || $tokens[$closeIndex] !== ']') {
            return null;
        }
        $equalsIndex = $this->peekNextMeaningfulIndex($tokens, $closeIndex);
        if ($equalsIndex === null || $tokens[$equalsIndex] !== '=') {
            return null;
        }

        return [$address . '[' . $this->stripQuotes($tokens[$keyIndex][1]) . ']', $equalsIndex, false];
    }

    /** @param array<string,string> $nodes */
    private function invalidateLiteralPathAddress(array &$nodes, string $address): void
    {
        unset($nodes[$address]);
        if (!str_contains($address, '[')) {
            foreach (array_keys($nodes) as $key) {
                if (str_starts_with($key, $address . '[')) {
                    unset($nodes[$key]);
                }
            }
        }
    }

    /**
     * `$args = wp_parse_args($args, ...)` is a common WordPress normalization shape, not a
     * general transform. It preserves literal caller keys, so it is safe to leave the original
     * array parameter node in place for a later `$args['key']` read.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     */
    private function isLiteralPathSameVariableWpParseArgs(array $tokens, int $equalsIndex, string $address, array $nodes): bool
    {
        if (str_contains($address, '[')) {
            return false;
        }
        $rhsIndex = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($rhsIndex === null || !is_array($tokens[$rhsIndex]) || $this->shortClassName($tokens[$rhsIndex][1]) !== 'wp_parse_args') {
            return false;
        }
        $argTokens = $this->argTokensAt($tokens, $rhsIndex, 0);
        $sourceNode = $argTokens !== null ? $this->literalPathNodeFromTokens($argTokens, $nodes) : null;
        return $sourceNode !== null && $sourceNode === ($nodes[$address] ?? null);
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     * @param array<string,string> $useImports
     * @param array<string,list<string>> $functionLiteralReturns
     * @param array<string,array<string,true>> $trackedProperties
     * @param array<string,array<string,string>> $propertyLiteralDefaults
     * @param list<LiteralPathPropagationLink> $links
     * @param list<LiteralPathInput> $inputs
     * @return array{string,string,string,?string}|null [source node, fixed prefix, fixed suffix,
     *                                                   required file-existence guard key]
     */
    private function resolveLiteralPathAssignmentSource(
        array $tokens,
        int $equalsIndex,
        array $nodes,
        string $currentNamespace,
        array $useImports,
        ?string $currentClass,
        ?string $currentParent,
        string $functionKey,
        array $functionLiteralReturns = [],
        array $trackedProperties = [],
        array $propertyLiteralDefaults = [],
        array &$links = [],
        array &$inputs = [],
    ): ?array {
        $rhsIndex = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($rhsIndex === null) {
            return null;
        }
        $rhsTokens = $this->literalPathStatementTokens($tokens, $rhsIndex);
        if ($rhsTokens === []) {
            return null;
        }

        // `apply_filters($tag, self::locate($template_name), ...)` returns the value being
        // filtered. WPForms stores exactly that call's result in `$located` before
        // load_template()/require; only this documented value position is treated as a
        // pass-through, never an arbitrary function's incidental argument.
        if ($this->literalPathFunctionNameFromTokens($rhsTokens) === 'apply_filters') {
            $valueTokens = $this->literalPathCallArguments($rhsTokens)[1] ?? null;
            if ($valueTokens === null) {
                return null;
            }
            $source = $this->resolveLiteralPathExpressionTokens($valueTokens, $nodes, true, $functionKey, $functionLiteralReturns, $currentClass, $currentParent, $currentNamespace, $useImports, $trackedProperties, $propertyLiteralDefaults);
            if ($source !== null) {
                return $source;
            }
            $callee = $this->literalPathScopedCallTarget($valueTokens, $currentNamespace, $useImports, $currentClass, $currentParent);
            if ($callee !== null) {
                return [$this->literalPathReturnNode($callee), '', '', null];
            }
            $bareCallee = $this->literalPathBareCallTarget($valueTokens);
            return $bareCallee === null ? null : [$this->literalPathReturnNode($bareCallee), '', '', null];
        }

        $callee = $this->literalPathScopedCallTarget($rhsTokens, $currentNamespace, $useImports, $currentClass, $currentParent);
        if ($callee !== null) {
            return [$this->literalPathReturnNode($callee), '', '', null];
        }

        $resolved = $this->resolveLiteralPathExpressionTokens($rhsTokens, $nodes, true, $functionKey, $functionLiteralReturns, $currentClass, $currentParent, $currentNamespace, $useImports, $trackedProperties, $propertyLiteralDefaults);
        if ($resolved !== null) {
            return $resolved;
        }

        // `$view_path = acf_get_path("includes/admin/views/{$view_path}.php");` (Advanced Custom
        // Fields) — a bare (unscoped) wrapper call, tried only as a last resort once nothing more
        // precise above matched (an identity transform like ltrim(), a concatenation of literals/
        // known nodes, ...) — see literalPathBareCallTarget()'s own docblock.
        $bareCallee = $this->literalPathBareCallTarget($rhsTokens);
        if ($bareCallee === null) {
            return null;
        }

        // Synchronously link this call's own arguments into its parameter nodes using the
        // CURRENT $nodes snapshot, rather than leaving that solely to the main loop's later,
        // separate per-call-site capture of this exact same call (every bare call already gets
        // that treatment once the loop's forward scan reaches its own T_STRING token). That later
        // pass runs AFTER this assignment's caller commits `$nodes[$address] = $targetNode` below
        // — for a self-referential wrapper call (`$view_path = acf_get_path("...{$view_path}...")`,
        // the exact ACF shape), the address being reassigned is the SAME variable the call reads
        // as its own argument, so waiting for that later pass would resolve the argument against
        // its own new value instead of the value it actually held when this call was evaluated.
        $this->captureLiteralPathCall(
            $this->literalPathBareFunctionKeys($bareCallee, null),
            $tokens,
            $rhsIndex,
            $functionKey,
            $nodes,
            false,
            $links,
            $inputs,
        );

        return [$this->literalPathReturnNode($bareCallee), '', '', null];
    }

    /**
     * A bare (unscoped) `name(...)` call used as an assignment/return-value RHS — the fallback
     * counterpart to literalPathScopedCallTarget() for a plain top-level function instead of a
     * Class::/self::/parent::/static:: one. Real-world shape (Advanced Custom Fields):
     * `$view_path = acf_get_path("includes/admin/views/{$view_path}.php");` inside
     * `acf_get_view()` — `acf_get_path()` is an ordinary global function, not a class method, so
     * `literalPathScopedCallTarget()` never matches it and this assignment's RHS previously
     * resolved to nothing at all (an opaque function call is neither a known node, a literal, nor
     * a concatenation `resolveLiteralPathExpressionTokens()` can make sense of), breaking every
     * call site that reassigns its own parameter through this wrapper before the eventual
     * `include`/`file_exists()` guard.
     *
     * Deliberately tried only as the LAST fallback, after resolveLiteralPathExpressionTokens()
     * has already failed to resolve the RHS more precisely (a known identity transform like
     * ltrim(), a plain concatenation of literals/tracked nodes) — this is a strictly coarser,
     * opaque "trust the callee's own return" step, the exact same one already accepted for a
     * scoped call just above. The call's own arguments are separately linked into the callee's
     * parameter nodes by captureLiteralPathCall() at this same call site (every bare call already
     * gets that treatment); this method only resolves what the call AS A WHOLE evaluates to for
     * this assignment, not its own arguments.
     *
     * Scope limitation: resolves only to the bare, unqualified function name — unlike
     * literalPathBareFunctionKeys() elsewhere, it doesn't also try a current-namespace-prefixed
     * candidate (no real-world evidence yet of a namespaced project using this exact reassignment
     * shape; a namespaced callee simply fails to connect here, same as before this fix, not a
     * regression).
     *
     * Refuses any name PHP itself already knows as a real, loaded function
     * (`function_exists()` — every PHP/extension builtin, `str_replace()` included) — a builtin
     * never has a project-file body for resolveLiteralPathReturnSource() to ever populate its
     * return node from, so treating it as an opaque pass-through would only ever be a dead-end
     * link, never a real resolution; existing regression
     * (testLiteralPathPropagationDoesNotTreatArbitraryTransformAsAPathTemplate) requires that an
     * arbitrary transform like `str_replace()` stays completely unresolved, not merely
     * unreachable, so this must return null outright rather than rely on the dead link being
     * harmless at resolve time.
     *
     * @param list<Token> $tokens
     */
    private function literalPathBareCallTarget(array $tokens): ?string
    {
        if (
            count($tokens) < 2
            || !is_array($tokens[0])
            || $tokens[0][0] !== T_STRING
            || $tokens[1] !== '('
        ) {
            return null;
        }
        $name = $tokens[0][1];
        return function_exists($name) ? null : $name;
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     * @return array{string,string,string,?string}|null [source node, fixed prefix, fixed suffix,
     *                                                   required file-existence guard key]
     */
    private function resolveLiteralPathIncludeSource(array $tokens, int $includeIndex, array $nodes): ?array
    {
        $firstExpressionIndex = $this->peekNextMeaningfulIndex($tokens, $includeIndex);
        if ($firstExpressionIndex === null) {
            return null;
        }
        return $this->resolveLiteralPathExpressionTokens(
            $this->literalPathStatementTokens($tokens, $firstExpressionIndex),
            $nodes,
        );
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     * @return array{string,string,string,?string}|null [source node, fixed prefix, fixed suffix,
     *                                                   required file-existence guard key]
     */
    private function resolveLiteralPathReturnSource(array $tokens, int $returnIndex, array $nodes): ?array
    {
        $firstExpressionIndex = $this->peekNextMeaningfulIndex($tokens, $returnIndex);
        if ($firstExpressionIndex === null) {
            return null;
        }
        $expression = $this->literalPathStatementTokens($tokens, $firstExpressionIndex);
        if ($this->literalPathFunctionNameFromTokens($expression) === 'apply_filters') {
            $valueTokens = $this->literalPathCallArguments($expression)[1] ?? null;
            return $valueTokens === null ? null : $this->resolveLiteralPathExpressionTokens($valueTokens, $nodes);
        }
        return $this->resolveLiteralPathExpressionTokens($expression, $nodes);
    }

    /**
     * @param list<Token> $tokens
     * @return list<Token>
     */
    private function literalPathStatementTokens(array $tokens, int $startIndex): array
    {
        $expression = [];
        $depth = 0;
        // T_CURLY_OPEN ({$var} string interpolation) closes with a bare "}" that is NOT a
        // code-level brace — same distinction the main token loop's own $interpolationDepth
        // already makes (see its own comment there). Without this, a call argument containing
        // curly-brace interpolation (`acf_get_path("...{$view_path}.php")`) hits that "}" while
        // $depth is still 0 (T_CURLY_OPEN itself never incremented it, being an array token, not
        // the bare '{' this loop otherwise matches) and returns [] immediately, discarding the
        // whole statement.
        $interpDepth = 0;
        for ($j = $startIndex; isset($tokens[$j]); $j++) {
            $token = $tokens[$j];
            if ($this->isInterpolationCurlyOpen($token)) {
                $interpDepth++;
            } elseif ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === '}' && $interpDepth > 0) {
                $interpDepth--;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                if ($depth === 0) {
                    return [];
                }
                $depth--;
            } elseif ($depth === 0 && $token === ';') {
                break;
            }
            if (!is_array($token) || $token[0] !== T_WHITESPACE) {
                $expression[] = $token;
            }
        }
        return $expression;
    }

    /** @param Token $token */
    private function isInterpolationCurlyOpen(mixed $token): bool
    {
        return is_array($token) && ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES);
    }

    /**
     * @param list<Token> $expression
     * @param array<string,string> $nodes
     * @param array<string,list<string>> $functionLiteralReturns
     * @param array<string,string> $useImports
     * @param array<string,array<string,true>> $trackedProperties
     * @param array<string,array<string,string>> $propertyLiteralDefaults
     * @return array{string,string,string,?string}|null [source node, fixed prefix, fixed suffix,
     *                                                   required file-existence guard key]
     */
    private function resolveLiteralPathExpressionTokens(
        array $expression,
        array $nodes,
        bool $allowGuardedUnknownVariable = false,
        ?string $guardScope = null,
        array $functionLiteralReturns = [],
        ?string $currentClass = null,
        ?string $currentParent = null,
        string $currentNamespace = '',
        array $useImports = [],
        array $trackedProperties = [],
        array $propertyLiteralDefaults = [],
    ): ?array {
        $sourceNode = $this->literalPathNodeFromTokens($expression, $nodes, $currentClass, $trackedProperties);
        if ($sourceNode !== null) {
            return [$sourceNode, '', '', null];
        }

        // `"includes/admin/views/{$view_path}.php"` (ACF's own acf_get_view() call argument) —
        // curly-brace complex string interpolation, not `.`-concatenation at all, so
        // literalPathConcatenationTerms() below never applies to it (no top-level `.` token to
        // split on). Reuses the same interpolated-string parser
        // $classNameTransformTemplates/$functionNameTransformTemplates already rely on elsewhere
        // in this file, rather than teaching this mechanism its own separate interpolation
        // parsing. Only the exact "prefix{$var}suffix" shape is recognized (see
        // interpolatedPrefixCurlyVarSuffixAt's own docblock) — a bare `"$var"`/`"prefix$var"`
        // interpolation without curly braces isn't reachable here since interpolatedPrefixVarAt()
        // requires a trailing `;`, a statement-terminator constraint that never holds for a call
        // argument (no real-world evidence yet for that simpler shape appearing as one).
        if (($expression[0] ?? null) === '"') {
            $interpolated = $this->interpolatedPrefixCurlyVarSuffixAt($expression, 0);
            if ($interpolated !== null) {
                [$interpPrefix, $interpVarName, $interpSuffix] = $interpolated;
                $varNode = $nodes[$interpVarName] ?? null;
                if ($varNode !== null) {
                    return [$varNode, $interpPrefix, $interpSuffix, null];
                }
            }
        }

        // WPForms' locate() normalizes its input with `ltrim($template_name, '/')`. Removing
        // only a leading slash is already reflected by the analyzers' path normalization, so
        // this exact PHP-core call is an identity for the reference index. Other transforms
        // remain deliberately unresolved.
        if ($this->literalPathFunctionNameFromTokens($expression) === 'ltrim') {
            $args = $this->literalPathCallArguments($expression);
            $inputNode = isset($args[0]) ? $this->literalPathNodeFromTokens($args[0], $nodes, $currentClass, $trackedProperties) : null;
            if ($inputNode !== null && isset($args[1]) && $this->literalPathStringLiteral($args[1]) === '/') {
                return [$inputNode, '', '', null];
            }
        }

        $terms = $this->literalPathConcatenationTerms($expression);
        if ($terms === null) {
            return null;
        }
        $sourceTermIndex = null;
        $unknownVariableTerms = 0;
        foreach ($terms as $index => $term) {
            if ($this->literalPathNodeFromTokens($term, $nodes, $currentClass, $trackedProperties) === null) {
                if (
                    $this->literalPathStringLiteralOrMethodReturn($term, $functionLiteralReturns, $currentClass, $currentParent, $currentNamespace, $useImports, $propertyLiteralDefaults) === null
                    && !$this->isLiteralPathRootDirectoryExpression($term)
                    && !$this->isLiteralPathIgnorableConstantExpression($term)
                    && !$this->isLiteralPathIgnorablePropertyExpression($term)
                ) {
                    if (!$this->isLiteralPathDirectVariable($term)) {
                        return null;
                    }
                    $unknownVariableTerms++;
                }
                continue;
            }
            if ($sourceTermIndex !== null) {
                return null; // More than one dynamic value is not a bounded path template.
            }
            $sourceTermIndex = $index;
        }
        if ($sourceTermIndex === null) {
            return null;
        }

        $sourceNode = $this->literalPathNodeFromTokens($terms[$sourceTermIndex], $nodes, $currentClass, $trackedProperties);
        if ($sourceNode === null) {
            return null;
        }
        if (
            $unknownVariableTerms > 0
            && (!$allowGuardedUnknownVariable || $unknownVariableTerms !== 1 || $guardScope === null)
        ) {
            return null;
        }
        $guardKey = $unknownVariableTerms === 1
            ? $this->literalPathExpressionGuardKey($guardScope, $expression)
            : null;
        $prefix = '';
        for ($index = $sourceTermIndex - 1; $index >= 0; $index--) {
            $literal = $this->literalPathStringLiteralOrMethodReturn($terms[$index], $functionLiteralReturns, $currentClass, $currentParent, $currentNamespace, $useImports, $propertyLiteralDefaults);
            if ($literal === null) {
                break;
            }
            $prefix = $literal . $prefix;
        }
        $suffix = '';
        for ($index = $sourceTermIndex + 1, $count = count($terms); $index < $count; $index++) {
            $literal = $this->literalPathStringLiteralOrMethodReturn($terms[$index], $functionLiteralReturns, $currentClass, $currentParent, $currentNamespace, $useImports, $propertyLiteralDefaults);
            if ($literal === null) {
                break;
            }
            $suffix .= $literal;
        }

        return [
            $sourceNode,
            $prefix,
            $suffix,
            $guardKey,
        ];
    }

    /**
     * Extends literalPathStringLiteral() to also recognize a zero-arg `self::`/`static::`/
     * `parent::`/`Foo::`/`$this->` method call whose target method (looked up in
     * $functionLiteralReturns — the same single-pass, defined-before-use map class-constant
     * resolution already relies on elsewhere in this parser) resolved to EXACTLY one literal
     * return value, treating it as if that literal appeared directly in this position.
     * Real-world shape (WPForms): `'emails/' . $this->get_slug() . '-' . $name` inside
     * `General::get_full_template_name()`, where `General::get_slug()` (defined earlier in the
     * same file) does `return static::TEMPLATE_SLUG;`. A method with several possible literal
     * returns (multiple branches) is left unresolved here — this position needs one
     * deterministic value, not a set, unlike the position `resolveLiteralPathExpressionTokens`
     * actually tracks as the flowing dynamic node.
     *
     * @param list<Token> $term
     * @param array<string,list<string>> $functionLiteralReturns
     * @param array<string,string> $useImports
     * @param array<string,array<string,string>> $propertyLiteralDefaults
     */
    private function literalPathStringLiteralOrMethodReturn(array $term, array $functionLiteralReturns, ?string $currentClass, ?string $currentParent, string $currentNamespace, array $useImports, array $propertyLiteralDefaults = []): ?string
    {
        $literal = $this->literalPathStringLiteral($term);
        if ($literal !== null) {
            return $literal;
        }

        $propName = $this->literalPathThisPropertyName($term);
        if ($propName !== null) {
            return $currentClass === null ? null : ($propertyLiteralDefaults[$currentClass][$propName] ?? null);
        }

        $key = $this->literalPathZeroArgMethodCallKey($term, $currentClass, $currentParent, $currentNamespace, $useImports);
        if ($key === null) {
            return null;
        }
        $returns = $functionLiteralReturns[$key] ?? [];
        return count($returns) === 1 ? $returns[0] : null;
    }

    /**
     * Recognizes `self::method()` / `static::method()` / `parent::method()` / `Foo::method()` /
     * `$this->method()` — a zero-argument scoped or instance method call — and resolves it to
     * the same `"Class::method"` key $functionLiteralReturns/$functionParamSuffixReturns use.
     * `$this->` resolves against $currentClass, same as `self::`/`static::` — no late-static-
     * binding fan-out across subclass overrides for any of the three (see
     * resolveReturnLiterals()'s own scope-limitation note); `parent::` resolves against
     * $currentParent. Returns null for anything else (an argument present, a property access,
     * a chained call, ...).
     *
     * @param list<Token> $expression
     * @param array<string,string> $useImports
     */
    private function literalPathZeroArgMethodCallKey(array $expression, ?string $currentClass, ?string $currentParent, string $currentNamespace, array $useImports): ?string
    {
        if (
            count($expression) !== 5
            || !is_array($expression[2]) || $expression[2][0] !== T_STRING
            || $expression[3] !== '(' || $expression[4] !== ')'
        ) {
            return null;
        }
        $methodName = $expression[2][1];
        $receiver = $expression[0];

        if (
            is_array($receiver) && $receiver[0] === T_VARIABLE && $receiver[1] === '$this'
            && is_array($expression[1]) && $expression[1][0] === T_OBJECT_OPERATOR
        ) {
            return $currentClass === null ? null : $currentClass . '::' . $methodName;
        }

        if (
            is_array($receiver) && (in_array($receiver[0], self::CLASS_NAME_TOKENS, true) || $receiver[0] === T_STATIC)
            && $this->isLiteralPathScopeResolutionOperator($expression[1])
        ) {
            $receiverName = $receiver[0] === T_STATIC ? 'static' : $receiver[1];
            $receiverClass = match (strtolower($receiverName)) {
                'self', 'static' => $currentClass,
                'parent' => $currentParent,
                default => $this->resolveFqcn($receiverName, $currentNamespace, $useImports),
            };
            return $receiverClass === null ? null : $receiverClass . '::' . $methodName;
        }

        return null;
    }

    /**
     * A path assembled from a wrapper parameter may omit a known WordPress directory root, as in
     * Blocksy's `get_template_directory() . '/inc/options/' . $path . '.php'`. Unlike an
     * arbitrary local variable, these APIs deterministically name the current project's root;
     * their absolute portion is intentionally irrelevant to the project-relative reference index.
     * Every other dynamic concatenation term makes the output unknowable and is rejected above.
     *
     * @param list<Token> $expression
     */
    private function isLiteralPathRootDirectoryExpression(array $expression): bool
    {
        $name = $this->literalPathFunctionNameFromTokens($expression);
        if ($name === null || !in_array($name, ['get_template_directory', 'get_stylesheet_directory'], true)) {
            return false;
        }

        return $this->literalPathCallArguments($expression) === [];
    }

    /**
     * A bare, unresolvable named constant — e.g. Akismet's `AKISMET__PLUGIN_DIR . 'views/' .
     * basename( $name ) . '.php'` — is the same "deterministic absolute root this parser can't
     * (and doesn't need to) resolve" shape as isLiteralPathRootDirectoryExpression's curated
     * function calls, just for the equally-common WP-plugin-bootstrap convention of a `define()`d
     * PATH/DIR constant instead. A single identifier token with nothing else in its own term
     * (never a function call — that still requires the curated list above) is virtually always a
     * constant reference in concatenation position; genuinely unknowable and ignored for prefix/
     * suffix purposes, same as the curated root-directory calls.
     *
     * @param list<Token> $expression
     */
    private function isLiteralPathIgnorableConstantExpression(array $expression): bool
    {
        return count($expression) === 1
            && is_array($expression[0])
            && in_array($expression[0][0], self::CLASS_NAME_TOKENS, true);
    }

    /** @param list<Token> $expression */
    private function isLiteralPathDirectVariable(array $expression): bool
    {
        return count($expression) === 1
            && is_array($expression[0])
            && $expression[0][0] === T_VARIABLE;
    }

    /**
     * `$this->propName`, exactly — the 3-token shape shared by literalPathNodeFromTokens()'s own
     * property-node case, literalPathStringLiteralOrMethodReturn()'s property-default lookup, and
     * isLiteralPathIgnorablePropertyExpression() below, so all three recognize the identical
     * shape rather than three independent (and possibly drifting) token checks.
     *
     * @param list<Token> $expression
     */
    private function literalPathThisPropertyName(array $expression): ?string
    {
        if (
            count($expression) !== 3
            || !is_array($expression[0]) || $expression[0][0] !== T_VARIABLE || $expression[0][1] !== '$this'
            || !is_array($expression[1]) || $expression[1][0] !== T_OBJECT_OPERATOR
            || !is_array($expression[2]) || $expression[2][0] !== T_STRING
        ) {
            return null;
        }
        return $expression[2][1];
    }

    /**
     * A `$this->propName` term that literalPathNodeFromTokens() doesn't already resolve as a live
     * node (see its own docblock — only a property this same file saw `$this->propName = ...`
     * successfully resolve for is ever "live") and that has no known scalar literal default
     * either is the property-access counterpart to isLiteralPathIgnorableConstantExpression()
     * just above: a value this parser can't (and, since it's neither the flowing dynamic segment
     * nor a literal fragment, doesn't need to) resolve. Real-world shape (Wordfence's `wfView`):
     * `render()`'s own `$this->view_path . '/' . $view . $this->view_file_extension` — `$view` is
     * the one live node; `$this->view_path` was set from an opaque `WORDFENCE_PATH` constant this
     * parser never resolves, and must be ignored rather than aborting the whole expression.
     *
     * @param list<Token> $expression
     */
    private function isLiteralPathIgnorablePropertyExpression(array $expression): bool
    {
        return $this->literalPathThisPropertyName($expression) !== null;
    }

    /**
     * The key includes its wrapper scope to prevent two separate methods that happen to use
     * `$base . $name` from sharing a guard. Token IDs/text deliberately omit line numbers so the
     * same expression compares equal between the `file_exists()` condition and its assignment.
     *
     * @param list<Token> $expression
     */
    private function literalPathExpressionGuardKey(string $functionKey, array $expression): string
    {
        $parts = [$functionKey];
        foreach ($expression as $token) {
            $parts[] = is_string($token)
                ? 's:' . $token
                : 't:' . $token[0] . ':' . $token[1];
        }
        return implode("\x1F", $parts);
    }

    /**
     * @param list<Token> $expression
     * @param array<string,string> $nodes
     * @param array<string,array<string,true>> $trackedProperties
     */
    private function literalPathNodeFromTokens(array $expression, array $nodes, ?string $currentClass = null, array $trackedProperties = []): ?string
    {
        if (count($expression) === 1 && is_array($expression[0]) && $expression[0][0] === T_VARIABLE) {
            return $nodes[$expression[0][1]] ?? null;
        }
        if (
            count($expression) === 4
            && is_array($expression[0]) && $expression[0][0] === T_VARIABLE
            && $expression[1] === '['
            && is_array($expression[2]) && $expression[2][0] === T_CONSTANT_ENCAPSED_STRING
            && $expression[3] === ']'
        ) {
            $address = $expression[0][1] . '[' . $this->stripQuotes($expression[2][1]) . ']';
            if (isset($nodes[$address])) {
                return $nodes[$address];
            }
            $parameterNode = $nodes[$expression[0][1]] ?? null;
            if ($parameterNode !== null && (bool) preg_match('/#param:\d+$/', $parameterNode)) {
                return $parameterNode . ':key:' . $this->stripQuotes($expression[2][1]);
            }
        }

        // `$this->propName` — only once *this same file* saw `$this->propName = <resolved
        // source>;` succeed somewhere (captureLiteralPathPropertyAssignment(), tracked in
        // $trackedProperties; see its own docblock for why this is single-pass, defined-before-
        // use). Real-world shape (Wordfence's `wfView`): the constructor sets `$this->view =
        // $view`; a *different* method, `render()`, later reads `$this->view` to build the
        // include path. The node id is the property's own stable `Class::$prop` identity, not a
        // per-function counter, since it must resolve identically no matter which method reads
        // it. An untracked property falls through to return null here — see
        // isLiteralPathIgnorablePropertyExpression()'s own docblock for what happens to it next.
        $propName = $this->literalPathThisPropertyName($expression);
        if ($propName !== null) {
            return ($currentClass !== null && isset($trackedProperties[$currentClass][$propName]))
                ? $this->literalPathPropertyNode($currentClass, $propName)
                : null;
        }

        // `basename( $name )` — Akismet's `Akismet::view( $name )` builds `AKISMET__PLUGIN_DIR .
        // 'views/' . basename( $name ) . '.php'` before `include`. FileAnalyzer's own referenced-
        // index already matches by basename() regardless (see its own doc comment), so this
        // transform is a no-op for matching purposes: resolves to the exact same node its inner
        // expression would, letting the caller's literal argument still reach it.
        // `sanitize_file_name( $name )` — WPForms' own `General::get_full_template_name( $name )`
        // reassigns `$name = \sanitize_file_name( $name );` (its own code calls it backslash-
        // qualified, hence CLASS_NAME_TOKENS + shortClassName() below rather than a bare T_STRING
        // check) before building the template path from it. Every real template-part name this
        // parser ever sees here (`'header'`, `'body'`, `'style'`, ...) is already a plain
        // lowercase slug with nothing for `sanitize_file_name()` to actually strip — same
        // no-op-for-matching-purposes trade-off as `basename()`. Any other wrapping function is
        // left unresolved, same "don't guess" stance the rest of this mechanism takes.
        if (
            count($expression) >= 4
            && is_array($expression[0]) && in_array($expression[0][0], self::CLASS_NAME_TOKENS, true) && in_array($this->shortClassName($expression[0][1]), ['basename', 'sanitize_file_name'], true)
            && $expression[1] === '('
            && $expression[count($expression) - 1] === ')'
        ) {
            return $this->literalPathNodeFromTokens(array_slice($expression, 2, -1), $nodes, $currentClass, $trackedProperties);
        }

        // `ltrim( $filename, '/' )` — ACF's own `acf_get_path( $filename )` does `ACF_PATH .
        // ltrim( $filename, '/' )`, stripping a possible leading slash before prefixing its own
        // path constant. Stripping a leading slash is already reflected by the analyzers' own
        // path normalization, so this exact PHP-core call is an identity for matching purposes
        // here too — same no-op-for-matching-purposes trade-off as basename()/sanitize_file_name()
        // just above, but recognized as one TERM of a larger concatenation (this function is
        // called per-term by resolveLiteralPathExpressionTokens's own loop), not just when it's
        // the whole expression by itself (resolveLiteralPathExpressionTokens already has its own
        // narrower whole-expression-only version of this same special case).
        if (
            count($expression) >= 4
            && is_array($expression[0]) && $expression[0][0] === T_STRING && strtolower($expression[0][1]) === 'ltrim'
            && $expression[1] === '('
            && $expression[count($expression) - 1] === ')'
        ) {
            $args = $this->splitTopLevelCommaArgs(array_slice($expression, 2, -1));
            if (count($args) === 2 && $this->literalPathStringLiteral($args[1]) === '/') {
                return $this->literalPathNodeFromTokens($args[0], $nodes, $currentClass, $trackedProperties);
            }
        }

        // `preg_replace( '/\.{2,}/', '.', $name )` — Wordfence's `wfView::render()` collapses
        // runs of dots in the view slug before building the path. Every real view slug this
        // parser ever sees here is already a plain relative path with no consecutive dots to
        // collapse — same no-op-for-matching-purposes trade-off as `basename()` above, extended
        // to a 3-arg call: both the pattern and replacement must be plain literals (a computed
        // pattern isn't attempted), and only the 3rd argument (the subject) is recursed into.
        if (
            count($expression) >= 6
            && is_array($expression[0]) && in_array($expression[0][0], self::CLASS_NAME_TOKENS, true) && $this->shortClassName($expression[0][1]) === 'preg_replace'
            && $expression[1] === '('
            && $expression[count($expression) - 1] === ')'
        ) {
            $args = $this->splitTopLevelCommaArgs(array_slice($expression, 2, -1));
            if (
                count($args) === 3
                && count($args[0]) === 1 && is_array($args[0][0]) && $args[0][0][0] === T_CONSTANT_ENCAPSED_STRING
                && count($args[1]) === 1 && is_array($args[1][0]) && $args[1][0][0] === T_CONSTANT_ENCAPSED_STRING
            ) {
                return $this->literalPathNodeFromTokens($args[2], $nodes, $currentClass, $trackedProperties);
            }
        }

        return null;
    }

    /**
     * @param list<Token> $expression
     * @return list<list<Token>>|null
     */
    private function literalPathConcatenationTerms(array $expression): ?array
    {
        $terms = [];
        $term = [];
        $depth = 0;
        $sawConcatenation = false;
        foreach ($expression as $token) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
            }
            if ($depth === 0 && $token === '.') {
                if ($term === []) {
                    return null;
                }
                $terms[] = $term;
                $term = [];
                $sawConcatenation = true;
                continue;
            }
            $term[] = $token;
        }
        if (!$sawConcatenation || $term === [] || $depth !== 0) {
            return null;
        }
        $terms[] = $term;
        return $terms;
    }

    /** @param list<Token> $tokens */
    private function literalPathStringLiteral(array $tokens): ?string
    {
        return count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_CONSTANT_ENCAPSED_STRING
            ? $this->stripQuotes($tokens[0][1])
            : null;
    }

    /** @param list<Token> $tokens */
    private function literalPathSingleStringRhs(array $tokens, int $operatorIndex): ?string
    {
        $firstIndex = $this->peekNextMeaningfulIndex($tokens, $operatorIndex);
        if ($firstIndex === null || !is_array($tokens[$firstIndex]) || $tokens[$firstIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $afterIndex = $this->peekNextMeaningfulIndex($tokens, $firstIndex);
        return $afterIndex !== null && $tokens[$afterIndex] === ';'
            ? $this->stripQuotes($tokens[$firstIndex][1])
            : null;
    }

    /**
     * Records an exact PHP-core file-existence guard. A guarded concatenation may discard one
     * unknown direct variable (WPForms' loop-specific `$template_path`) only if this predicate
     * checked the same parameter-derived expression, or if it later checks the local node that
     * received that expression. This deliberately cannot bless `$base . $name . '.php'` without
     * the guard, arbitrary predicates, properties, function calls, or two unknown terms.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     * @param array<string,true> $guards
     */
    private function captureLiteralPathFileExistenceGuard(
        array $tokens,
        int $callNameIndex,
        ?string $functionKey,
        array $nodes,
        array &$guards,
    ): void {
        if ($functionKey === null || $this->literalPathCallArgumentTokensAt($tokens, $callNameIndex, 1) !== null) {
            return;
        }
        $previousIndex = $this->peekPrevMeaningfulIndex($tokens, $callNameIndex);
        if (
            $previousIndex !== null
            && (
                $tokens[$previousIndex] === '->'
                || $this->isLiteralPathScopeResolutionOperator($tokens[$previousIndex])
            )
        ) {
            return;
        }

        $argument = $this->literalPathCallArgumentTokensAt($tokens, $callNameIndex, 0);
        if ($argument === null) {
            return;
        }
        $source = $this->resolveLiteralPathExpressionTokens($argument, $nodes, true, $functionKey);
        if ($source === null) {
            return;
        }
        [, , , $expressionGuardKey] = $source;
        if ($expressionGuardKey !== null) {
            $guards[$expressionGuardKey] = true;
        }
    }

    /**
     * Captures only declaration variables at the outer level of a named function/method's
     * parameter list. LiteralPathPropagation later pairs a wrapper body variable with one of
     * these exact positions; defaults may contain arrays/calls of their own, so nested variables
     * are deliberately not considered parameters.
     *
     * @param list<Token> $tokens
     * @return list<string>
     */
    private function parseFunctionParameterNames(array $tokens, int $openParenIndex): array
    {
        $parameters = [];
        $depth = 0;
        for ($j = $openParenIndex; isset($tokens[$j]); $j++) {
            $token = $tokens[$j];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth === 1 && is_array($token) && $token[0] === T_VARIABLE) {
                $parameters[] = $token[1];
            }
        }

        return $parameters;
    }

    /**
     * `as_schedule_single_action( $timestamp, $action_name, $args, $group )` inside a wrapper
     * whose own declared parameter is `$action_name` — the hook name reaches Action Scheduler
     * unchanged, no concatenation, no transform. Records which of the enclosing function's own
     * parameter positions plays this role (see $hookPassThroughParams' own declaration comment),
     * reusing the SAME node map $literalPathNodeStack already builds (a bare variable that
     * resolves to a "...#param:N" node IS the enclosing function's own Nth parameter) — no new
     * per-function tracking needed beyond that lookup. Deliberately narrow: only a bare, direct
     * variable at the exact tag-argument position counts, same "don't guess" stance as
     * LiteralPathPropagation's own node resolution takes for everything else.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $nodes
     * @param array<string,int> $hookPassThroughParams
     */
    private function captureHookPassThroughParam(
        string $name,
        array $tokens,
        int $callNameIndex,
        ?string $functionKey,
        array $nodes,
        array &$hookPassThroughParams,
    ): void {
        if ($functionKey === null) {
            return;
        }
        $argIndex = match (true) {
            in_array($name, self::HOOK_INVOKE_FUNCS, true) => 0,
            array_key_exists($name, self::CRON_SCHEDULE_FUNCS) => self::CRON_SCHEDULE_FUNCS[$name],
            default => null,
        };
        if ($argIndex === null) {
            return;
        }
        $argument = $this->literalPathCallArgumentTokensAt($tokens, $callNameIndex, $argIndex);
        if (
            $argument === null
            || count($argument) !== 1
            || !is_array($argument[0])
            || $argument[0][0] !== T_VARIABLE
        ) {
            return;
        }
        $node = $nodes[$argument[0][1]] ?? null;
        if ($node === null || !(bool) preg_match('/#param:(\d+)$/', $node, $matches)) {
            return;
        }
        $hookPassThroughParams[$functionKey] = (int) $matches[1];
    }

    /**
     * The closure-local counterpart to captureHookPassThroughParam() above — same shape
     * (`as_schedule_single_action($t, $hook, ...)` using a bare variable matching one of the
     * *closure's* own declared parameters), just matched against a plain parameter-name list
     * instead of the named-function graph's node map, since an anonymous closure was never given
     * one (see $closureHookPassThroughParamStack's own declaration comment for why). Records the
     * closure's own parameter *position* (not the call site's), keyed by the variable it's
     * currently assigned to — resolved the moment that variable is later called as `$var(...)`.
     *
     * @param list<Token> $tokens
     * @param list<string> $closureParamNames
     * @param array<string,int> $closureHookPassThroughParams
     */
    private function captureClosureHookPassThroughParam(
        string $name,
        array $tokens,
        int $callNameIndex,
        ?string $closureVarName,
        array $closureParamNames,
        array &$closureHookPassThroughParams,
    ): void {
        if ($closureVarName === null || $closureVarName === '') {
            return;
        }
        $argIndex = match (true) {
            in_array($name, self::HOOK_INVOKE_FUNCS, true) => 0,
            array_key_exists($name, self::CRON_SCHEDULE_FUNCS) => self::CRON_SCHEDULE_FUNCS[$name],
            default => null,
        };
        if ($argIndex === null) {
            return;
        }
        $argument = $this->literalPathCallArgumentTokensAt($tokens, $callNameIndex, $argIndex);
        if (
            $argument === null
            || count($argument) !== 1
            || !is_array($argument[0])
            || $argument[0][0] !== T_VARIABLE
        ) {
            return;
        }
        $paramPosition = array_search($argument[0][1], $closureParamNames, true);
        if ($paramPosition === false) {
            return;
        }
        $closureHookPassThroughParams[$closureVarName] = $paramPosition;
    }

    /**
     * Records links for only explicit argument values: a currently-tracked parameter/local node
     * flowing into another named/scoped wrapper, or an exact literal starting a wrapper graph.
     * A static `load_template()`/get_template_part-family call is also a sink. This excludes
     * callback invocation, variable-as-function dispatch, and arbitrary expressions so the
     * eventual resolver cannot mistake ordinary transformations for a file loader.
     *
     * @param list<string> $targetFunctionKeys
     * @param list<Token> $tokens
     * @param array<string,string> $sourceNodes
     * @param list<LiteralPathPropagationLink> $links
     * @param list<LiteralPathInput> $inputs
     */
    private function captureLiteralPathCall(
        array $targetFunctionKeys,
        array $tokens,
        int $callNameIndex,
        ?string $sourceFunctionKey,
        array $sourceNodes,
        bool $isSink,
        array &$links,
        array &$inputs,
    ): void {
        for ($argumentPosition = 0; ; $argumentPosition++) {
            $argument = $this->literalPathCallArgumentTokensAt($tokens, $callNameIndex, $argumentPosition);
            if ($argument === null) {
                break;
            }
            $source = $this->resolveLiteralPathExpressionTokens($argument, $sourceNodes);
            $literal = $this->literalPathStringLiteral($argument);

            foreach ($targetFunctionKeys as $targetFunctionKey) {
                if ($source !== null && $sourceFunctionKey !== null) {
                    [$sourceNode, $prefix, $suffix] = $source;
                    $links[] = new LiteralPathPropagationLink(
                        $sourceNode,
                        $this->literalPathParameterNode($targetFunctionKey, $argumentPosition),
                        $prefix,
                        $suffix,
                    );
                }
                if ($literal !== null) {
                    $inputs[] = new LiteralPathInput(
                        $this->literalPathParameterNode($targetFunctionKey, $argumentPosition),
                        $literal,
                    );
                }
                foreach ($this->literalPathKeyedArrayLiterals($argument) as $key => $values) {
                    foreach ($values as $value) {
                        $inputs[] = new LiteralPathInput(
                            $this->literalPathParameterNode($targetFunctionKey, $argumentPosition) . ':key:' . $key,
                            $value,
                        );
                    }
                }
            }

            if ($isSink && $argumentPosition === 0 && $source !== null && $sourceFunctionKey !== null) {
                [$sourceNode, $prefix, $suffix] = $source;
                $links[] = new LiteralPathPropagationLink(
                    $sourceNode,
                    prefix: $prefix,
                    suffix: $suffix,
                    isSink: true,
                );
            }
        }
    }

    /**
     * argTokensAt() intentionally only needs to balance nested parentheses for its established
     * string-oriented callers. LiteralPathPropagation must also inspect a whole keyed array
     * argument, so it keeps square/curly nesting here rather than changing that older helper's
     * behavior.
     *
     * @param list<Token> $tokens
     * @return list<Token>|null
     */
    private function literalPathCallArgumentTokensAt(array $tokens, int $callNameIndex, int $argumentPosition): ?array
    {
        $openParenIndex = $this->skipInsignificant($tokens, $callNameIndex + 1);
        if (!isset($tokens[$openParenIndex]) || $tokens[$openParenIndex] !== '(') {
            return null;
        }

        $currentPosition = 0;
        $depth = 0;
        // See literalPathStatementTokens()'s identical $interpDepth comment — a curly-brace
        // string interpolation's T_CURLY_OPEN/closing "}" must not be mistaken for a code-level
        // bracket, or an argument containing one (`acf_get_path("...{$view_path}.php")`) hits the
        // "}" while $depth is still 0 and this returns null, discarding the whole argument.
        $interpDepth = 0;
        $argument = [];
        for ($index = $openParenIndex + 1; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];
            if ($this->isInterpolationCurlyOpen($token)) {
                $interpDepth++;
                $argument[] = $token;
                continue;
            }
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                $argument[] = $token;
                continue;
            }
            if ($token === '}' && $interpDepth > 0) {
                $interpDepth--;
                $argument[] = $token;
                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                if ($token === ')' && $depth === 0) {
                    break;
                }
                if ($depth === 0) {
                    return null;
                }
                $depth--;
                $argument[] = $token;
                continue;
            }
            if ($token === ',' && $depth === 0) {
                if ($currentPosition === $argumentPosition) {
                    return $argument;
                }
                $currentPosition++;
                $argument = [];
                continue;
            }
            // An inline `// phpcs:ignore ...`/doc comment directly after the opening `(` (or
            // between any two argument tokens) must not survive into $argument — real-world
            // regression (WPForms): `wpforms_render( // phpcs:ignore ...\n\t'education/admin/
            // did-you-know', [...] )` left a stray T_COMMENT token in front of the literal,
            // so literalPathStringLiteral()'s own `count($tokens) === 1` exact-match check
            // never matched and the whole chain silently failed to resolve.
            if (!is_array($token) || ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT)) {
                $argument[] = $token;
            }
        }

        return $currentPosition === $argumentPosition ? $argument : null;
    }

    /**
     * @param list<Token> $argument
     * @return array<string,list<string>>
     */
    private function literalPathKeyedArrayLiterals(array $argument): array
    {
        if ($argument === []) {
            return [];
        }
        $first = $argument[0];
        $last = $argument[count($argument) - 1];
        $openIndex = null;
        if ($first === '[' && $last === ']') {
            $openIndex = 0;
        } elseif (is_array($first) && $first[0] === T_ARRAY && ($argument[1] ?? null) === '(' && $last === ')') {
            $openIndex = 1;
        }
        if ($openIndex === null) {
            return [];
        }

        $entries = [];
        $entry = [];
        $depth = 0;
        $lastIndex = count($argument) - 1;
        for ($index = $openIndex + 1; $index < $lastIndex; $index++) {
            $token = $argument[$index];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                if ($depth === 0) {
                    return []; // The outer array did not close where expected.
                }
                $depth--;
            }
            if ($depth === 0 && $token === ',') {
                if ($entry !== []) {
                    $entries[] = $entry;
                }
                $entry = [];
                continue;
            }
            $entry[] = $token;
        }
        if ($depth !== 0) {
            return [];
        }
        if ($entry !== []) {
            $entries[] = $entry;
        }

        $literals = [];
        foreach ($entries as $entry) {
            $arrowIndex = null;
            $entryDepth = 0;
            foreach ($entry as $index => $token) {
                if ($token === '(' || $token === '[' || $token === '{') {
                    $entryDepth++;
                } elseif ($token === ')' || $token === ']' || $token === '}') {
                    $entryDepth--;
                } elseif ($entryDepth === 0 && is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                    $arrowIndex = $index;
                    break;
                }
            }
            if ($arrowIndex === null) {
                continue;
            }
            $key = $this->literalPathStringLiteral(array_slice($entry, 0, $arrowIndex));
            $value = $this->literalPathStringLiteral(array_slice($entry, $arrowIndex + 1));
            if ($key !== null && $value !== null) {
                $literals[$key][] = $value;
            }
        }

        return $literals;
    }

    /**
     * @param list<Token> $tokens
     * @return list<list<Token>>
     */
    private function literalPathCallArguments(array $tokens): array
    {
        $openIndex = null;
        foreach ($tokens as $index => $token) {
            if ($token === '(') {
                $openIndex = $index;
                break;
            }
        }
        if ($openIndex === null) {
            return [];
        }

        $arguments = [];
        $argument = [];
        $depth = 0;
        $lastIndex = count($tokens) - 1;
        for ($index = $openIndex + 1; $index <= $lastIndex; $index++) {
            $token = $tokens[$index];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                if ($depth === 0) {
                    if ($index !== $lastIndex) {
                        return [];
                    }
                    if ($argument !== []) {
                        $arguments[] = $argument;
                    }
                    return $arguments;
                }
                $depth--;
            }
            if ($depth === 0 && $token === ',') {
                $arguments[] = $argument;
                $argument = [];
                continue;
            }
            $argument[] = $token;
        }

        return [];
    }

    /** @param list<Token> $tokens */
    private function literalPathFunctionNameFromTokens(array $tokens): ?string
    {
        if (count($tokens) < 2 || !is_array($tokens[0]) || $tokens[1] !== '(') {
            return null;
        }
        if (!in_array($tokens[0][0], self::CLASS_NAME_TOKENS, true)) {
            return null;
        }
        return $this->shortClassName($tokens[0][1]);
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function literalPathScopedCallTarget(
        array $tokens,
        string $currentNamespace,
        array $useImports,
        ?string $currentClass,
        ?string $currentParent,
    ): ?string {
        if (
            count($tokens) < 4
            || !is_array($tokens[0])
            || !in_array($tokens[0][0], self::CLASS_NAME_TOKENS, true)
            || !$this->isLiteralPathScopeResolutionOperator($tokens[1])
            || !is_array($tokens[2])
            || $tokens[2][0] !== T_STRING
            || $tokens[3] !== '('
        ) {
            return null;
        }
        $arguments = $this->literalPathCallArguments($tokens);
        if ($arguments === [] && ($tokens[4] ?? null) !== ')') {
            return null;
        }
        $receiver = match ($tokens[0][1]) {
            'self', 'static' => $currentClass,
            'parent' => $currentParent,
            default => $this->resolveFqcn($tokens[0][1], $currentNamespace, $useImports),
        };
        return $receiver !== null ? $receiver . '::' . $tokens[2][1] : null;
    }

    /** @param Token $token */
    private function isLiteralPathScopeResolutionOperator(mixed $token): bool
    {
        return $token === '::' || (is_array($token) && $token[0] === T_DOUBLE_COLON);
    }

    /** @return list<string> */
    private function literalPathBareFunctionKeys(string $name, ?string $extraCandidateFqcn): array
    {
        $keys = [$name];
        if ($extraCandidateFqcn !== null && $extraCandidateFqcn !== $name) {
            $keys[] = $extraCandidateFqcn;
        }
        return $keys;
    }

    private function isLiteralPathSinkFunction(string $name): bool
    {
        // `load_template()` is the WordPress-core counterpart to a direct include. The
        // get_template_part family is already parsed as a direct template reference, but needs
        // the same sink role when its argument arrived through a wrapper parameter instead.
        return $name === 'load_template' || $this->isTemplateLoaderFunc($name);
    }

    /** @param list<Token> $tokens */
    private function findParenAfterFunctionKeyword(array $tokens, int $i): ?int
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            if (is_string($tokens[$j]) && $tokens[$j] === '(') {
                return $j;
            }
            $j++;
        }
        return null;
    }

    /**
     * $openIndex points at an opening '(' token. Returns the index of its matching ')' —
     * tracking paren depth only (nested '['/'{' inside an argument list don't affect it).
     *
     * @param list<Token> $tokens
     */
    private function findMatchingCloseParen(array $tokens, int $openIndex): ?int
    {
        $depth = 0;
        for ($j = $openIndex; isset($tokens[$j]); $j++) {
            if ($tokens[$j] === '(') {
                $depth++;
            } elseif ($tokens[$j] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        return null;
    }

    /**
     * $closeParenIndex points at the closing ')' of a function/method's parameter list. Resolves
     * an optional `: ReturnType` declaration to a concrete class name — same "only trust a
     * single unambiguous type" stance as collectParamTypeHint, but counts every type-like segment
     * (including `array`/`callable`, which can never resolve to a class themselves) toward
     * ambiguity, not just the ones that resolve — `int|My_Class` must not be mistaken for a
     * confident `My_Class` just because "int" doesn't produce a class reference. Nullable's
     * leading `?` isn't a segment of its own (a `?My_Class` return is still confidently My_Class
     * whenever it isn't null).
     *
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function parseReturnTypeHint(array $tokens, int $closeParenIndex, ?string $ownerClass, ?string $ownerParent, string $currentNamespace, array $useImports): ?ClassRef
    {
        $j = $this->peekNextMeaningfulIndex($tokens, $closeParenIndex);
        if ($j === null || $tokens[$j] !== ':') {
            return null;
        }

        $segmentCount = 0;
        $resolved = null;
        $j = $this->skipInsignificant($tokens, $j + 1);

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '{' || $t === ';') {
                    break;
                }
                $j++;
                continue;
            }

            if ($t[0] === T_ARRAY || $t[0] === T_CALLABLE) {
                $segmentCount++;
                $j++;
                continue;
            }

            if (in_array($t[0], self::CLASS_NAME_TOKENS, true) || $t[0] === T_STATIC) {
                $segmentCount++;
                $name = $t[0] === T_STATIC ? 'static' : $t[1];
                $resolved = match (strtolower($name)) {
                    'self', 'static' => $ownerClass === null ? null : new ClassRef($this->shortClassName($ownerClass), $ownerClass),
                    'parent' => $ownerParent === null ? null : new ClassRef($this->shortClassName($ownerParent), $ownerParent),
                    default => in_array(strtolower($name), self::PRIMITIVE_TYPE_NAMES, true)
                        ? null
                        : $this->newClassRefFor($name, $currentNamespace, $useImports),
                };
            }

            $j++;
        }

        return $segmentCount === 1 ? $resolved : null;
    }

    /**
     * $equalsIndex points at the `=` of `$var = <call>;`. Recognizes exactly one call as the
     * entire RHS — Foo::method(...), self::/parent::/static::method(...), $this->method(...), or
     * a bare helper_function(...) — and returns its receiver (null for the bare-function case)
     * and method/function name, for later resolution against that function/method's own declared
     * return type (see PendingReturnTypedCall). Bails (null) for anything else: a chained call, a
     * call embedded in a larger expression, a non-call RHS.
     *
     * @return array{?string, string}|null [receiverClassOrNull, methodOrFunctionName]
     * @param list<Token> $tokens
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param array<string,string> $useImports
     */
    private function scopedOrBareCallRhs(array $tokens, int $equalsIndex, array $classNameStack, array $classParentStack, string $currentNamespace, array $useImports): ?array
    {
        $j = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($j === null || !is_array($tokens[$j])) {
            return null;
        }
        $token = $tokens[$j];

        $receiverClass = null;
        $nameIndex = null;
        $methodName = null;

        if ($token[0] === T_VARIABLE && $token[1] === '$this') {
            $target = $this->findScopedCallTarget($tokens, $j);
            if ($target === null) {
                return null;
            }
            [$methodName, $nameIndex] = $target;
            $receiverClass = empty($classNameStack) ? null : end($classNameStack);
            if ($receiverClass === null) {
                return null;
            }
        } elseif (in_array($token[0], self::CLASS_NAME_TOKENS, true) || $token[0] === T_STATIC) {
            $next = $this->peekNextMeaningful($tokens, $j);
            if ($next === '::') {
                $target = $this->findScopedCallTarget($tokens, $j);
                if ($target === null) {
                    return null;
                }
                [$methodName, $nameIndex] = $target;
                $name = $token[0] === T_STATIC ? 'static' : $token[1];
                $receiverClass = $this->resolveClassNameToken($name, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                if ($receiverClass === null) {
                    return null;
                }
            } elseif ($next === '(' && $token[0] === T_STRING) {
                // Bare function call — helper_fn(...). Namespaced/fully-qualified bare calls
                // (\Foo\Bar\helper()) are the same documented blind spot as everywhere else in
                // this parser (see the "Namespaced/fully-qualified function calls" TODO item) —
                // deliberately not handled here either.
                $methodName = $token[1];
                $nameIndex = $j;
            } else {
                return null;
            }
        } else {
            return null;
        }

        $openParenIndex = $this->peekNextMeaningfulIndex($tokens, $nameIndex);
        if ($openParenIndex === null || $tokens[$openParenIndex] !== '(') {
            return null;
        }
        $closeParenIndex = $this->findMatchingCloseParen($tokens, $openParenIndex);
        if ($closeParenIndex === null) {
            return null;
        }
        $afterClose = $this->peekNextMeaningfulIndex($tokens, $closeParenIndex);
        if ($afterClose === null || $tokens[$afterClose] !== ';') {
            return null;
        }

        return [$receiverClass, $methodName];
    }

    /**
     * Walks a parameter list looking for class-like type hints — `TypeName $var`,
     * `?TypeName $var`, `self`/`static`/`parent`, and constructor-promoted properties
     * (`public readonly TypeName $var`) — resolving each to a concrete class name. Every
     * class-like type found is a genuine reference regardless of shape, so all of them are
     * returned as references; but only an unambiguous single type seeds $varTypesStack, since
     * a union (`A|B`) or intersection (`A&B`) type doesn't tell us which one $var actually is
     * at runtime — same "don't guess" stance as the rest of this parser's variable tracking.
     *
     * @return array{list<string>, array<string,string>, array<string,string>}  [classReferences,
     *   paramVar => FQCN, promoted property name => FQCN]
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function parseParamTypeHints(array $tokens, int $parenIndex, ?string $ownerClass, ?string $ownerParent, string $currentNamespace, array $useImports): array
    {
        $classRefs = [];
        $varTypes = [];
        $propertyTypes = [];

        $depth = 0;
        $paramTokens = [];
        $j = $parenIndex + 1;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                // #[Attribute] before a parameter — its matching close is a plain "]" token,
                // handled by the generic bracket-depth tracking below.
                $depth++;
                $j++;
                continue;
            }

            if (is_string($t) && ($t === '(' || $t === '[')) {
                $depth++;
                $j++;
                continue;
            }

            if (is_string($t) && ($t === ')' || $t === ']')) {
                if ($depth === 0) {
                    break; // end of parameter list
                }
                $depth--;
                $j++;
                continue;
            }

            if (is_string($t) && $t === ',' && $depth === 0) {
                $this->collectParamTypeHint($paramTokens, $ownerClass, $ownerParent, $currentNamespace, $useImports, $classRefs, $varTypes, $propertyTypes);
                $paramTokens = [];
                $j++;
                continue;
            }

            if ($depth === 0) {
                $paramTokens[] = $t;
            }
            $j++;
        }

        $this->collectParamTypeHint($paramTokens, $ownerClass, $ownerParent, $currentNamespace, $useImports, $classRefs, $varTypes, $propertyTypes);

        return [$classRefs, $varTypes, $propertyTypes];
    }

    /**
     * Reads one parameter's already-collected top-level tokens (type hint, name, promotion
     * modifiers — no default-value tokens, since those live at bracket depth > 0 and
     * parseParamTypeHints never collects them) and records its resolved type(s).
     *
     * @param list<Token> $paramTokens
     * @param array<string,string> $useImports
     * @param list<string> $classRefs
     * @param array<string,string> $varTypes
     * @param array<string,string> $propertyTypes Promoted-property name => FQCN, populated
     *   only when $paramTokens carries a visibility modifier (`public`/`protected`/`private`) —
     *   the PHP-required marker for constructor property promotion (`readonly` alone, without
     *   one of these, doesn't promote). Promotion auto-assigns `$this->name = $name`, the same
     *   effect as an explicit `$this->name = new ClassName()` in the constructor body — see
     *   ParseResult::$propertyAssignedClasses.
     */
    private function collectParamTypeHint(array $paramTokens, ?string $ownerClass, ?string $ownerParent, string $currentNamespace, array $useImports, array &$classRefs, array &$varTypes, array &$propertyTypes): void
    {
        $typeRefs = [];
        $varName = null;
        $isPromoted = false;

        foreach ($paramTokens as $t) {
            if (is_string($t)) {
                continue; // '?', '|', '&', '=', ... — none of these are type-name tokens
            }

            if (in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                $isPromoted = true;
                continue;
            }

            if ($t[0] === T_VARIABLE) {
                $varName = $t[1];
                break; // the parameter's own name; nothing after it is part of its type
            }

            if (in_array($t[0], self::CLASS_NAME_TOKENS, true) || $t[0] === T_STATIC) {
                $name = $t[0] === T_STATIC ? 'static' : $t[1];
                $resolved = match (strtolower($name)) {
                    'self', 'static' => $ownerClass === null ? null : new ClassRef($this->shortClassName($ownerClass), $ownerClass),
                    'parent' => $ownerParent === null ? null : new ClassRef($this->shortClassName($ownerParent), $ownerParent),
                    default => in_array(strtolower($name), self::PRIMITIVE_TYPE_NAMES, true)
                        ? null
                        : $this->newClassRefFor($name, $currentNamespace, $useImports),
                };
                if ($resolved !== null) {
                    $typeRefs[] = $resolved;
                }
            }
        }

        foreach ($typeRefs as $ref) {
            $classRefs[] = $ref->short;
        }

        if ($isPromoted && $varName !== null && count($typeRefs) === 1) {
            $propertyTypes[substr($varName, 1)] = $typeRefs[0]->fqcn;
        }

        if ($varName !== null && count($typeRefs) === 1) {
            $varTypes[$varName] = $typeRefs[0]->fqcn;
        }
    }

    /**
     * Dispatches a bare `name(...)` call already confirmed to have a `(` right after it — hook
     * registration/invocation, a WP-Cron scheduling call, a template-part-family call, `glob()`/
     * `scandir()`, `define()`, an existence-check guard, or (falling through all of those) an
     * ordinary function call. Shared by both the `T_STRING` call site and the namespaced/fully-
     * qualified one, so a WP core function called as `\add_action(...)` (explicitly opting out of
     * the current namespace) is recognized exactly the same way a bare `add_action(...)` already
     * is — $name only needs to already be resolved to whatever these hook/template constants and
     * `FunctionDef` are matched against (the bare value for a `T_STRING` call, the unqualified
     * tail via `shortClassName()` for a qualified one); this method itself doesn't care which.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $varLiteralValues
     * @param array<string,array<string,string>> $classConstants
     * @param array<string,string> $useImports
     * @param list<HookRegistration> $hookRegistrations
     * @param list<HookInvocation> $hookInvocations
     * @param list<TemplateRef> $templateRefs
     * @param list<PendingTemplateHelperCall> $pendingTemplateHelperCalls
     * @param list<array<string,string>> $varAssignedFromFunctionStack
     * @param list<string> $globIncludeDirs
     * @param list<string> $rootRelativeIncludeDirs
     * @param array<string,string> $definedConstants
     * @param array<int,bool> $skipStringIndices
     * @param list<FunctionCall> $functionCalls
     * @param list<ScopedMethodCallPrefix> $scopedMethodCallPrefixes
     */
    private function dispatchBareFunctionCall(
        string $name,
        ?string $extraCandidateFqcn,
        array $tokens,
        int $i,
        string|int $line,
        string $file,
        array $varLiteralValues,
        array $classConstants,
        ?string $currentClass,
        ?string $currentParent,
        string $currentNamespace,
        array $useImports,
        array &$hookRegistrations,
        array &$hookInvocations,
        array &$templateRefs,
        array &$pendingTemplateHelperCalls,
        array $varAssignedFromFunctionStack,
        array &$globIncludeDirs,
        array &$rootRelativeIncludeDirs,
        array &$definedConstants,
        array &$skipStringIndices,
        array &$functionCalls,
        array &$scopedMethodCallPrefixes,
    ): void {
        // `method_exists($this, 'generate_' . $type . '_html')` / `method_exists(__CLASS__,
        // "get_column_{$column_name}")` — a dynamic-dispatch registry, checking whether a
        // computed method name exists on a resolvable receiver before calling it, no array-
        // callback shape involved at all. Confirmed recurring across the corpus: WooCommerce
        // (5 call sites — generate_*_html, variation_bulk_action_*, format_*, set_*_query_args),
        // Wordfence (scan_*_init, filter*), WordPress SEO (retrieve_*), WPForms
        // (get_column_{$column_name}). method_exists()'s own second argument being built this
        // way is a stronger, more unambiguous signal than the array-callback prefix case below —
        // there's no "is this position even callback-related" question to answer, the function
        // name says so directly — so no receiver-resolution restriction beyond
        // methodExistsReceiverClass()'s own narrow, evidenced shapes is needed.
        if ($name === 'method_exists') {
            $receiverArg = $this->argTokensAt($tokens, $i, 0);
            $nameArg = $this->argTokensAt($tokens, $i, 1);
            if ($receiverArg !== null && $nameArg !== null) {
                $receiverClass = $this->methodExistsReceiverClass($receiverArg, $currentClass, $currentNamespace, $useImports);
                $prefixSuffix = $receiverClass !== null ? $this->methodExistsDynamicNamePrefixSuffix($nameArg) : null;
                if ($prefixSuffix !== null) {
                    [$prefix, $suffix] = $prefixSuffix;
                    $scopedMethodCallPrefixes[] = new ScopedMethodCallPrefix($receiverClass, $prefix, $suffix);
                }
            }
        }

        if (in_array($name, self::HOOK_REGISTER_FUNCS, true)) {
            $hookRegistrations[] = $this->parseHookRegistration($tokens, $i, $line, $file, $name, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
            return;
        }

        if (in_array($name, self::HOOK_INVOKE_FUNCS, true)) {
            $hookInvocations[] = $this->parseHookInvocation($tokens, $i, $line, $file, $name, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
            return;
        }

        if (array_key_exists($name, self::CRON_SCHEDULE_FUNCS)) {
            $hookInvocations[] = $this->parseCronScheduleHook($tokens, $i, $line, $file, $name, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
            return;
        }

        if ($this->isTemplateLoaderFunc($name)) {
            $templateRefs[] = $this->parseTemplateRef($tokens, $i, $line, $file, $name, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);

            // Recording a template ref doesn't preclude this also being a genuine function call —
            // a WP-core name (get_template_part, never itself project-declared) gains nothing from
            // this, but a project's own template-loader wrapper (wc_get_template_part,
            // sydney_get_template_part, ... — see isTemplateLoaderFunc()) is both called here AND
            // declared elsewhere in the same project, and deserves the same "is this used" credit
            // any other function call gives its own declaration. Real-world regression this
            // fixes: Sydney theme's own sydney_get_template_part() looked unused the moment its
            // real call sites started being recognized as template refs instead of ordinary calls
            // — the fix that exposed the gap shouldn't be the fix that creates a new one.
            $functionCalls[] = new FunctionCall($name, (int) $line, $file, $extraCandidateFqcn);

            // get_template_part( ocean_single_post_header_template() ) — the sole argument is a
            // bare call to a project helper, not a string at all, so parseTemplateRef() above
            // resolved to nothing useful. Left as a pending reference (see
            // PendingTemplateHelperCall's own doc comment) for whichever analyzer merges
            // $functionLiteralReturns across every scanned file.
            $helperFn = $this->bareZeroArgFunctionCallArg($tokens, $i);
            if ($helperFn === null) {
                // get_template_part( $template_part ) where `$template_part = helper_fn();` a
                // few lines earlier — one more level of indirection than the direct call-as-
                // argument shape above (real-world example, OceanWP's own
                // ocean_single_post_header_meta_template_part()).
                $argVar = $this->bareVariableArg($tokens, $i);
                if ($argVar !== null) {
                    $varScopeTop = count($varAssignedFromFunctionStack) - 1;
                    $helperFn = $varAssignedFromFunctionStack[$varScopeTop][$argVar] ?? null;
                }
            }
            if ($helperFn !== null) {
                $pendingTemplateHelperCalls[] = new PendingTemplateHelperCall($helperFn, $name, (int) $line, $file);
            }
            return;
        }

        if ($name === 'glob' || $name === 'scandir') {
            // scandir()'s single argument is a directory already (glob()'s is a filename
            // *pattern* that needs dirname() to strip the wildcard segment — see
            // parseGlobDirRef's own doc comment).
            $isPattern = $name === 'glob';

            // scandir(SOME_CONFIGS_DIR) — a bare constant, not a literal in this call at all.
            // Real-world example (Astra theme): `define('X_CONFIGS_DIR', X_THEME_DIR .
            // 'inc/.../configs/'); foreach (scandir(X_CONFIGS_DIR) as $f) { ... }`. Resolved via
            // $definedConstants (populated by the `define` branch below) rather than
            // findTrailingStringLiteral, which can't see past the call boundary into an earlier,
            // separate statement.
            $constRef = $this->bareConstantArg($tokens, $i);
            if ($constRef !== null && isset($definedConstants[$constRef])) {
                $rootRelativeIncludeDirs[] = rtrim($definedConstants[$constRef], '/');
                return;
            }

            $dir = $this->parseGlobDirRef($tokens, $i, $isPattern);
            if ($dir !== null) {
                $globIncludeDirs[] = $dir;
            }
            return;
        }

        // `path_join( WPCF7_PLUGIN_DIR . '/includes/swv/php/rules', $file )` where $file is built
        // per-iteration (`sprintf('%s.php', $rule)` over `array_keys()` of a configured rule
        // array, real-world shape: Contact Form 7's wpcf7_swv_load_rules()) — the same
        // "a directory gets enumerated into per-file includes with no single per-file reference
        // to find" shape glob()/scandir() already cover, just a WP-core path-joining helper
        // instead of a filesystem directory listing. Only trusted when the second argument is NOT
        // a plain literal (a fully-static path_join() call is an ordinary single reference,
        // already covered elsewhere, not a bulk loader) and the first argument resolves to a
        // fixed literal directory — see resolvePathJoinFixedPrefix()'s own docblock. Gated the
        // same way as glob() (FileAnalyzer only trusts this when `hasIncludeStatement` is also
        // true somewhere in the file), not on any narrower foreach/array_keys() detection — the
        // co-occurrence itself is the (deliberately coarse) signal.
        if ($name === 'path_join') {
            $secondArg = $this->argTokensAt($tokens, $i, 1);
            $firstArg = $this->argTokensAt($tokens, $i, 0);
            if (
                $firstArg !== null
                && $secondArg !== null
                && $this->literalPathStringLiteral($secondArg) === null
                && $this->containsVariable($secondArg)
            ) {
                $prefix = $this->resolvePathJoinFixedPrefix($firstArg);
                if ($prefix !== null) {
                    [$dirLiteral, $isRootRelative] = $prefix;
                    if ($isRootRelative) {
                        $rootRelativeIncludeDirs[] = rtrim($dirLiteral, '/');
                    } else {
                        $globIncludeDirs[] = rtrim($dirLiteral, '/');
                    }
                }
            }
            return;
        }

        if ($name === 'define') {
            $def = $this->parseDefineDirective($tokens, $i);
            if ($def !== null) {
                [$constName, $literal] = $def;
                $definedConstants[$constName] = $literal;
            }
            return;
        }

        if (in_array($name, self::EXISTENCE_CHECK_FUNCS, true)) {
            $argIndex = $this->firstStringArgIndex($tokens, $i);
            if ($argIndex !== null) {
                $skipStringIndices[$argIndex] = true;
            }
            return;
        }

        // Regular function call
        $functionCalls[] = new FunctionCall($name, (int) $line, $file, $extraCandidateFqcn);
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $varLiteralValues
     * @param array<string,array<string,string>> $classConstants
     * @param array<string,string> $useImports
     */
    private function parseHookRegistration(array $tokens, int $i, string|int $line, string $file, string $funcName, array $varLiteralValues = [], array $classConstants = [], ?string $currentClass = null, ?string $currentParent = null, string $currentNamespace = '', array $useImports = []): HookRegistration
    {
        // add_action( 'tag', callback )
        $arg = $this->extractStringArgAt($tokens, $i, 0, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
        if ($arg === null) {
            return new HookRegistration('', $funcName, (int) $line, $file, true);
        }
        [$tag, $isDynamic] = $arg;
        return new HookRegistration($tag, $funcName, (int) $line, $file, $isDynamic);
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $varLiteralValues
     * @param array<string,array<string,string>> $classConstants
     * @param array<string,string> $useImports
     */
    private function parseHookInvocation(array $tokens, int $i, string|int $line, string $file, string $funcName, array $varLiteralValues = [], array $classConstants = [], ?string $currentClass = null, ?string $currentParent = null, string $currentNamespace = '', array $useImports = []): HookInvocation
    {
        $arg = $this->extractStringArgAt($tokens, $i, 0, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
        if ($arg === null) {
            return new HookInvocation('', $funcName, (int) $line, $file, true, '', '');
        }
        [$tag, $isDynamic, $prefix, $suffix] = $arg;
        return new HookInvocation($tag, $funcName, (int) $line, $file, $isDynamic, $prefix, $suffix);
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $varLiteralValues
     * @param array<string,array<string,string>> $classConstants
     * @param array<string,string> $useImports
     */
    private function parseCronScheduleHook(array $tokens, int $i, string|int $line, string $file, string $funcName, array $varLiteralValues = [], array $classConstants = [], ?string $currentClass = null, ?string $currentParent = null, string $currentNamespace = '', array $useImports = []): HookInvocation
    {
        $argIndex = self::CRON_SCHEDULE_FUNCS[$funcName];
        $arg = $this->extractStringArgAt($tokens, $i, $argIndex, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
        if ($arg === null) {
            return new HookInvocation('', $funcName, (int) $line, $file, true, '', '');
        }
        [$tag, $isDynamic, $prefix, $suffix] = $arg;
        return new HookInvocation($tag, $funcName, (int) $line, $file, $isDynamic, $prefix, $suffix);
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,string> $varLiteralValues
     * @param array<string,array<string,string>> $classConstants
     * @param array<string,string> $useImports
     */
    private function parseTemplateRef(array $tokens, int $i, string|int $line, string $file, string $funcName, array $varLiteralValues = [], array $classConstants = [], ?string $currentClass = null, ?string $currentParent = null, string $currentNamespace = '', array $useImports = []): TemplateRef
    {
        // Unlike hook tags, a template ref keeps a usable value even when the arg is a dynamic
        // interpolated string — e.g. get_template_part("variants/$variant") still tells us
        // every "variants/*" file is reachable, even though the exact suffix isn't known.
        // The literal prefix doubles as the exact value when the arg isn't dynamic at all.
        [, $isDynamic, $path] = $this->extractStringArgAt($tokens, $i, 0, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports) ?? ['', true, ''];

        // locate_template() has no "slug-name" variant convention the way get_template_part()/
        // get_header()/get_footer()/get_sidebar() do — its argument is meant to be an exact
        // relative template path, not a slug combined with an unenumerable runtime-chosen
        // variant. Treating a merely-adjacent literal prefix as "anything under this directory
        // is reachable" is only justified by that documented WP convention; found via a fresh
        // gap-hunt (Sage theme's own functions.php, before its own dedicated fix): a genuinely
        // dynamic locate_template() argument with no other resolvable signal wrongly exempted
        // its entire containing directory. A fully dynamic locate_template() argument now stays
        // unresolved instead — the same conservative "no signal, don't guess" behavior
        // parseIncludeRef() already gives a fully dynamic include.
        if ($isDynamic && $funcName === 'locate_template') {
            $path = '';
        }

        // get_header('kiosk') loads header-kiosk.php; prefix the stem so matching works
        if ($path !== '') {
            $prefix = match ($funcName) {
                'get_header' => 'header',
                'get_footer' => 'footer',
                'get_sidebar' => 'sidebar',
                default => null,
            };
            if ($prefix !== null) {
                $path = $prefix . '-' . $path;
            }
        }

        return new TemplateRef($path, $funcName, (int) $line, $file);
    }

    /** @param list<Token> $tokens */
    private function parseIncludeRef(array $tokens, int $i, string|int $line, string $file, string $keyword): ?TemplateRef
    {
        // include 'path/to/file.php';
        // include dirname(__FILE__) . '/file.php';  — take the trailing literal segment
        // include $dynamic_var;                     — no literal anywhere, skip
        $lastString = $this->findTrailingStringLiteral($tokens, $i);
        if ($lastString === null) {
            return null; // fully dynamic include — skip
        }

        return new TemplateRef($lastString, strtolower($keyword), (int) $line, $file);
    }

    /**
     * glob()'s argument is a filename *pattern* ('inc/*.php'), not a directory — dirname()
     * strips the wildcard/filename segment, leaving the directory glob() actually scans. A
     * pattern with no directory component at all ('*.php', or the concatenated-literal-only
     * remainder '/*.php') collapses to '.' or '/' — both mean "this file's own directory",
     * which FileAnalyzer resolves against the calling file's own location, same as it does for
     * a proper subdirectory. scandir()'s argument is already a directory, not a pattern — pass
     * $isPattern: false to skip the dirname() stripping.
     *
     * @param list<Token> $tokens
     */
    private function parseGlobDirRef(array $tokens, int $i, bool $isPattern = true): ?string
    {
        $pattern = $this->findTrailingStringLiteral($tokens, $i);
        if ($pattern === null) {
            return null;
        }
        return $isPattern ? dirname($pattern) : rtrim($pattern, '/');
    }

    /** @param list<Token> $tokens */
    private function containsVariable(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves path_join()'s first argument to a fixed literal directory: every one of its
     * (possibly zero) `.`-concatenation terms must be either a plain string literal, a bare
     * unresolvable named constant (isLiteralPathIgnorableConstantExpression — e.g. Contact Form
     * 7's own `WPCF7_PLUGIN_DIR`), or get_template_directory()/get_stylesheet_directory()
     * (isLiteralPathRootDirectoryExpression) — never a variable, which would mean this isn't a
     * fixed prefix at all. Reuses the exact same three checks the literal-path propagation graph
     * already relies on for the identical "ignorable absolute anchor + literal path" shape,
     * rather than re-implementing them.
     *
     * A bare unresolvable constant is treated the SAME as a root-relative anchor, not the calling
     * file's own directory — matching those checks' own established precedent throughout this
     * parser (a `define()`d PLUGIN_DIR/THEME_DIR-style constant's value is always project-root-
     * relative, never relative to whichever file happens to reference it).
     *
     * @param list<Token> $argTokens
     * @return array{0:string,1:bool}|null [directory literal, is root-relative]
     */
    private function resolvePathJoinFixedPrefix(array $argTokens): ?array
    {
        $terms = $this->literalPathConcatenationTerms($argTokens) ?? [$argTokens];
        $literal = '';
        $isRootRelative = false;
        foreach ($terms as $term) {
            $str = $this->literalPathStringLiteral($term);
            if ($str !== null) {
                $literal .= $str;
                continue;
            }
            if ($this->isLiteralPathRootDirectoryExpression($term) || $this->isLiteralPathIgnorableConstantExpression($term)) {
                $isRootRelative = true;
                continue;
            }
            return null;
        }
        return $literal === '' ? null : [$literal, $isRootRelative];
    }

    /**
     * How many directory levels above __DIR__ a single argument-expression token list resolves
     * to, when it's exactly `__DIR__` (0), exactly `__FILE__` (-1 — one level "finer" than
     * __DIR__, since `dirname(__FILE__) === __DIR__`), or a `dirname(...)`/`plugin_dir_path(...)`
     * call climbing one level above whatever its own first argument resolves to (WP core's own
     * `plugin_dir_path()` is defined as `trailingslashit(dirname($file))` — same one-level climb,
     * plus formatting) — recursively, so `dirname(dirname(__DIR__))` resolves to 2. `dirname()`'s
     * own optional 2-arg literal-int form (`dirname($path, $levels)`, PHP 7.0+) climbs $levels
     * directly instead of just one. Returns null for anything else (a literal string, a variable,
     * a WP path constant, ...) — deliberately narrow, only the exact shapes real-world
     * spl_autoload_register() bootstrap files were found using. See
     * maxDirnameAncestorUpLevels()'s own docblock for why this is worth resolving at all.
     *
     * @param list<Token> $exprTokens
     */
    private function resolveDirAncestorUpLevel(array $exprTokens): ?int
    {
        if (count($exprTokens) === 1) {
            $t = $exprTokens[0];
            if (is_array($t) && $t[0] === T_DIR) {
                return 0;
            }
            if (is_array($t) && $t[0] === T_FILE) {
                return -1;
            }
            return null;
        }

        $first = $exprTokens[0];
        if (!is_array($first) || $first[0] !== T_STRING) {
            return null;
        }
        $calleeName = strtolower($first[1]);
        if ($calleeName !== 'dirname' && $calleeName !== 'plugin_dir_path') {
            return null;
        }

        $innerArg = $this->argTokensAt($exprTokens, 0, 0);
        if ($innerArg === null) {
            return null;
        }
        $innerLevel = $this->resolveDirAncestorUpLevel($innerArg);
        if ($innerLevel === null) {
            return null;
        }

        if ($calleeName === 'dirname') {
            $levelsArg = $this->argTokensAt($exprTokens, 0, 1);
            if ($levelsArg !== null && count($levelsArg) === 1 && is_array($levelsArg[0]) && $levelsArg[0][0] === T_LNUMBER) {
                return $innerLevel + (int) $levelsArg[0][1];
            }
        }

        return $innerLevel + 1;
    }

    /**
     * The deepest ancestor-directory climb any `dirname(...)`/`plugin_dir_path(...)` call
     * anywhere in this file's tokens computes from `__DIR__`/`__FILE__` (see
     * resolveDirAncestorUpLevel) — 0 when none is found (the common case). Deliberately
     * whole-file rather than scoped to one specific function's body: consulted only by
     * FileAnalyzer when the same file also registers a hand-rolled `spl_autoload_register()`
     * callback, whose own base-path computation commonly climbs above its own file's directory
     * before descending back into the class-name-derived subpath (real-world case: Broken Link
     * Checker's `core/utils/autoloader.php` does `plugin_dir_path(dirname(__DIR__))` to reach the
     * plugin root two levels up before resolving `WPMUDEV_BLC\...` class names against it) —
     * FileAnalyzer's default assumption (the autoloader's own directory IS the loaded code's
     * scope) undercounts exactly this shape. Real-world autoloader bootstrap files are small and
     * single-purpose, so a whole-file scan is the same "coarse but bounded" trade-off the rest of
     * that exemption mechanism already accepts, not a new precision loss.
     *
     * @param list<Token> $tokens
     */
    private function maxDirnameAncestorUpLevels(array $tokens): int
    {
        $max = 0;
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $calleeName = strtolower($token[1]);
            if ($calleeName !== 'dirname' && $calleeName !== 'plugin_dir_path') {
                continue;
            }
            if ($this->peekNextMeaningful($tokens, $i) !== '(') {
                continue;
            }

            $arg0 = $this->argTokensAt($tokens, $i, 0);
            if ($arg0 === null) {
                continue;
            }
            $innerLevel = $this->resolveDirAncestorUpLevel($arg0);
            if ($innerLevel === null) {
                continue;
            }

            $level = $innerLevel + 1;
            if ($calleeName === 'dirname') {
                $levelsArg = $this->argTokensAt($tokens, $i, 1);
                if ($levelsArg !== null && count($levelsArg) === 1 && is_array($levelsArg[0]) && $levelsArg[0][0] === T_LNUMBER) {
                    $level = $innerLevel + (int) $levelsArg[0][1];
                }
            }

            if ($level > $max) {
                $max = $level;
            }
        }
        return $max;
    }

    /**
     * Recognizes a call with exactly one bare-constant argument — `scandir(SOME_CONST)`, not
     * `scandir('literal')` (a T_CONSTANT_ENCAPSED_STRING, not T_STRING) nor
     * `scandir($var)`/`scandir(SOME_CONST . '/x')` (more than just the one bare name). Returns
     * the constant's name, for a $definedConstants lookup by the caller.
     *
     * @param list<Token> $tokens
     */
    private function bareConstantArg(array $tokens, int $i): ?string
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            return null;
        }
        $name = $tokens[$j][1];
        $after = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$after]) || $tokens[$after] !== ')') {
            return null;
        }
        return $name;
    }

    /**
     * Recognizes a call whose sole argument is a bare variable — `get_template_part( $x )`, not
     * `get_template_part( 'literal' )` nor `get_template_part( $x . '-suffix' )`. Returns the
     * variable's name.
     *
     * @param list<Token> $tokens
     */
    private function bareVariableArg(array $tokens, int $i): ?string
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
            return null;
        }
        $name = $tokens[$j][1];
        $after = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$after]) || $tokens[$after] !== ')') {
            return null;
        }
        return $name;
    }

    /**
     * Recognizes a call whose sole argument is itself a bare, zero-argument function call —
     * `get_template_part( helper_fn() )`, not `get_template_part( 'literal' )` nor
     * `get_template_part( helper_fn( $x ) )` nor `get_template_part( helper_fn() . '-suffix' )`.
     * Returns the inner function's name.
     *
     * @param list<Token> $tokens
     */
    private function bareZeroArgFunctionCallArg(array $tokens, int $i): ?string
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            return null;
        }
        $name = $tokens[$j][1];

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ')') {
            return null;
        }

        $after = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$after]) || $tokens[$after] !== ')') {
            return null;
        }

        return $name;
    }

    /**
     * $i points at a T_STRING call name (e.g. 'class_exists'), already confirmed followed by
     * '('. Returns the token index of its first argument when that argument is a plain string
     * literal — regardless of what (if anything) follows it — or null otherwise (a variable, a
     * concatenation, no arguments at all).
     *
     * @param list<Token> $tokens
     */
    private function firstStringArgIndex(array $tokens, int $i): ?int
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return $j;
    }

    /**
     * $ifIndex points at T_IF. Recognizes exactly `if ( ! function_exists ( 'name' ) )` —
     * the leading `!` REQUIRED (the only shape real code actually uses this for: the non-negated
     * form would define the function only once it already exists, an immediate redeclaration
     * fatal error, so no real code writes it) — everything else exact, no `&&`/`||` combined
     * with anything else, no `=== false`, immediately followed by `{`. Anything looser returns
     * null rather than guessing: this feeds a real-usage EXEMPTION (FunctionDef::$guarded), so a
     * false match here would wrongly hide genuinely dead code, not just miss a real polyfill.
     *
     * @param list<Token> $tokens
     */
    private function functionExistsGuardName(array $tokens, int $ifIndex): ?string
    {
        $j = $this->peekNextMeaningfulIndex($tokens, $ifIndex);
        if ($j === null || $tokens[$j] !== '(') {
            return null;
        }

        $j = $this->peekNextMeaningfulIndex($tokens, $j);
        if ($j === null || $tokens[$j] !== '!') {
            return null;
        }
        $j = $this->peekNextMeaningfulIndex($tokens, $j);
        if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== 'function_exists') {
            return null;
        }

        $openCallParen = $this->peekNextMeaningfulIndex($tokens, $j);
        if ($openCallParen === null || $tokens[$openCallParen] !== '(') {
            return null;
        }

        $strIndex = $this->peekNextMeaningfulIndex($tokens, $openCallParen);
        if ($strIndex === null || !is_array($tokens[$strIndex]) || $tokens[$strIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $closeCallParen = $this->peekNextMeaningfulIndex($tokens, $strIndex);
        if ($closeCallParen === null || $tokens[$closeCallParen] !== ')') {
            return null;
        }

        $closeIfParen = $this->peekNextMeaningfulIndex($tokens, $closeCallParen);
        if ($closeIfParen === null || $tokens[$closeIfParen] !== ')') {
            return null;
        }

        $braceIndex = $this->peekNextMeaningfulIndex($tokens, $closeIfParen);
        if ($braceIndex === null || $tokens[$braceIndex] !== '{') {
            return null;
        }

        return $this->stripQuotes($tokens[$strIndex][1]);
    }

    /**
     * $receiverName/$methodName are the already-resolved bare names of a scoped call this parser
     * just recognized (`Receiver::method(...)`), reached from either the bare-T_STRING branch or
     * the qualified/fully-qualified one — both funnel through here so the check only needs to
     * exist once. `WP_CLI::add_command('astra abilities', 'Astra_Abilities_CLI')` — WP-CLI
     * reflects over *every* public method of the given class and dispatches whichever one
     * matches the subcommand typed, not a fixed method name a curated contract-method list could
     * name up front (unlike WP_Widget's widget()/form()/update()). The class name is this call's
     * own second argument, not the receiver already being resolved at the call site.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     * @param list<string> $reflectionDispatchedClassNames
     */
    private function recordWpCliAddCommandDispatch(string $receiverName, string $methodName, array $tokens, int $methodNameIndex, string $currentNamespace, array $useImports, array &$reflectionDispatchedClassNames): void
    {
        if ($receiverName !== 'WP_CLI' || $methodName !== 'add_command') {
            return;
        }
        $commandClass = $this->secondArgStringLiteral($tokens, $methodNameIndex);
        if ($commandClass !== null) {
            $reflectionDispatchedClassNames[] = $this->resolveFqcn($commandClass, $currentNamespace, $useImports);
        }
    }

    /**
     * $nameIndex points at a T_STRING call name (e.g. 'add_command'), already confirmed followed
     * by '('. Returns its second argument's class name when the call's first argument is a plain
     * string literal and its second is one of three shapes: also a plain string literal —
     * `add_command('astra abilities', 'Astra_Abilities_CLI')` — the idiomatic modern-PHP
     * `Foo::class` form — `add_command('elementor experiments', WP_CLI::class)` (real-world
     * shape, Elementor) — or a fresh instance, `new Foo(...)` — `add_command('circumflex-booking
     * database', new DatabaseCommand($this->database))` (real-world shape, circumflex-booking):
     * equally idiomatic WP-CLI usage (WP-CLI's own docs show both the class-name and instance
     * forms), and the class name is a literal identifier right after `new` either way — the same
     * "only trust the simplest literal shape" stance as the other two branches, just not
     * resolving `new $var(...)`/`new (expr)()` (see the separate "Dynamic instantiation" TODO
     * item; that gap is structural to this token parser, not specific to this call site). Null
     * for anything else (fewer than two arguments, a non-literal first argument, or a second
     * argument that's none of these three shapes) — a concatenated or variable class name is left
     * unresolved rather than guessed at.
     *
     * @param list<Token> $tokens
     */
    private function secondArgStringLiteral(array $tokens, int $nameIndex): ?string
    {
        $j = $this->skipInsignificant($tokens, $nameIndex + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ',') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j])) {
            return null;
        }

        // Foo::class instead of a plain string literal — the idiomatic modern-PHP way to
        // reference a class by name. Real-world shape (Elementor):
        // `\WP_CLI::add_command('elementor experiments', WP_CLI::class)` — WP_CLI (no leading
        // backslash) resolves via the file's own namespace to a locally-declared `Wp_Cli`
        // class, not the real global `\WP_CLI` — the caller's own resolveFqcn() call handles
        // this exactly the same way it already does for a plain string literal, since the raw
        // class-name token text is returned unresolved either way. Returned as-is (not stripped
        // of quotes — there are none here), same contract as the string-literal branch below.
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], self::CLASS_NAME_TOKENS, true)) {
            $classNameToken = $tokens[$j][1];
            $k = $this->skipInsignificant($tokens, $j + 1);
            if (!isset($tokens[$k]) || !is_array($tokens[$k]) || $tokens[$k][0] !== T_DOUBLE_COLON) {
                return null;
            }
            $k = $this->skipInsignificant($tokens, $k + 1);
            if (!isset($tokens[$k]) || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING || $tokens[$k][1] !== 'class') {
                return null;
            }
            return $classNameToken;
        }

        // `new Foo(...)` / `new Foo\Bar(...)` — see this method's own docblock. Only a literal
        // identifier right after `new` counts, same as captureClassNameAfter() elsewhere in this
        // parser; the raw (unresolved) token text is returned, same contract as the other two
        // branches above — the caller always resolves it via resolveFqcn() itself.
        if (is_array($tokens[$j]) && $tokens[$j][0] === T_NEW) {
            $k = $this->skipInsignificant($tokens, $j + 1);
            if (isset($tokens[$k]) && is_array($tokens[$k]) && in_array($tokens[$k][0], self::CLASS_NAME_TOKENS, true)) {
                return $tokens[$k][1];
            }
            return null;
        }

        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return $this->stripQuotes($tokens[$j][1]);
    }

    /**
     * $i points at T_STRING 'define', already confirmed followed by '('. Extracts the constant
     * name (define()'s first argument, always a plain string literal in practice) and the
     * trailing string literal from the whole call — which, since the name argument's own literal
     * comes first in token order, naturally resolves to the *value* argument's trailing literal
     * (findTrailingStringLiteral keeps overwriting as it scans, "last one wins"). Bails (null)
     * when the value expression has no literal at all — the value-less edge case where the only
     * literal found is the name argument's own text, i.e. $trailingLiteral === $constName.
     *
     * @param list<Token> $tokens
     * @return array{string,string}|null
     */
    private function parseDefineDirective(array $tokens, int $i): ?array
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $constName = $this->stripQuotes($tokens[$j][1]);

        $trailingLiteral = $this->findTrailingStringLiteral($tokens, $i);
        if ($trailingLiteral === null || $trailingLiteral === $constName) {
            return null;
        }
        return [$constName, $trailingLiteral];
    }

    /**
     * Walks tokens starting just after $i — an include/require keyword, or a function-call
     * name like "glob" — tracking paren depth, and returns the last string literal seen before
     * the enclosing statement/call argument ends. Handles every shape a path expression takes
     * in practice: a bare literal, `dirname(__FILE__) . '/literal'`, or a longer concatenation
     * chain — the trailing literal segment is what carries usable path information regardless
     * of how much dynamic prefix comes before it. Returns null when there's no literal
     * anywhere (a fully dynamic value).
     *
     * @param list<Token> $tokens
     */
    private function findTrailingStringLiteral(array $tokens, int $i): ?string
    {
        $j = $i + 1;
        $depth = 0;
        $lastString = null;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '(') {
                    $depth++;
                } elseif ($t === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && ($t === ';' || $t === ',')) {
                    break;
                }
            } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $lastString = $this->stripQuotes($t[1]);
            }

            $j++;
        }

        return $lastString;
    }

    /**
     * Same paren-depth-tracked walk as findTrailingStringLiteral(), but for the opposite need:
     * `require get_template_directory() . '/inc/options/' . $key . '-options.php';` has a
     * dynamic *middle* segment (the loop variable) sandwiched between a meaningful directory-
     * prefix literal and a throwaway suffix literal — findTrailingStringLiteral's "last one
     * wins" rule would surface the suffix, which carries no usable directory information at all.
     * This instead freezes on the *last literal seen strictly before the first T_VARIABLE* and
     * ignores everything after. Also detects whether the prefix's own leading call is
     * get_template_directory()/get_stylesheet_directory() — a WP theme-root accessor, meaning
     * the literal is project-root-relative (define()'d THEME_DIR-style constants get the same
     * treatment via ParseResult::$rootRelativeIncludeDirs) rather than relative to whichever file
     * happens to contain this require statement, the way a bare __DIR__ concatenation would be.
     *
     * Returns null when there's no T_VARIABLE anywhere in the expression at all — that's not
     * this pattern; parseIncludeRef's own trailing-literal capture already covers a fully static
     * or fully dynamic (no literal anywhere) include target correctly on its own.
     *
     * @param list<Token> $tokens
     * @return array{0:string,1:bool}|null [directory prefix literal, is theme-root-relative]
     */
    private function findIncludeDirPrefixBeforeVariable(array $tokens, int $i): ?array
    {
        $j = $i + 1;
        $depth = 0;
        $lastLiteral = null;
        $isRootRelative = false;
        $sawVariable = false;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '(') {
                    $depth++;
                } elseif ($t === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && ($t === ';' || $t === ',')) {
                    break;
                }
            } elseif (!$sawVariable && $t[0] === T_STRING
                && in_array($t[1], ['get_template_directory', 'get_stylesheet_directory'], true)
                && $this->peekNextMeaningful($tokens, $j) === '('
            ) {
                $isRootRelative = true;
            } elseif (!$sawVariable && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $lastLiteral = $this->stripQuotes($t[1]);
            } elseif ($t[0] === T_VARIABLE) {
                $sawVariable = true;
            }

            $j++;
        }

        if ($lastLiteral === null || !$sawVariable) {
            return null;
        }

        // The captured literal is whatever string segment sits between the last quote before
        // the variable and the variable itself — for `'.../inc/options/' . $key . ...` that's
        // already a clean directory (ends in '/'), matching a real bulk-directory-load exactly
        // the way it's treated below. But `'.../inc/dashboard/html-' . $tab_id . ...` (real-world
        // regression, Sydney theme) mashes a directory together with a filename *prefix* —
        // treating "html-" itself as a subdirectory can never match a real file, silently
        // defeating the whole exemption for this shape. Trimmed back to the last real '/'
        // boundary so it's treated as the directory it actually names.
        if (!str_ends_with($lastLiteral, '/')) {
            $slashPos = strrpos($lastLiteral, '/');
            $lastLiteral = $slashPos === false ? '' : substr($lastLiteral, 0, $slashPos + 1);
        }

        // No '/' at all in the literal (e.g. just a bare filename-prefix with no directory
        // component) leaves nothing meaningful to exempt — bailing here matters more than usual,
        // since an empty string reaching $rootRelativeIncludeDirs/$globIncludeDirs would be
        // misread downstream as "exempt the whole project" (FileAnalyzer's own empty-string
        // convention for a root-level bulk-include caller), not "no signal."
        if ($lastLiteral === '') {
            return null;
        }

        return [$lastLiteral, $isRootRelative];
    }

    /**
     * Skip to the '(' after the current function name, then read the argument at $argIndex
     * (0-indexed), splitting on top-level commas. Returns null if the call doesn't have that
     * many arguments.
     *
     * @return array{string, bool, string, string}|null  [exact tag or '', isDynamic, literal prefix, literal suffix]
     * @param list<Token> $tokens
     * @param array<string,string> $varLiteralValues Current scope's variable name => its last-
     *   known literal string value (see $varLiteralValueStack) — lets a bare-variable argument
     *   resolve the same way a literal directly in the call would.
     * @param array<string,array<string,string>> $classConstants Class name => constant name =>
     *   literal value (see $classConstants / parseClassConstants) — lets a `self::CONST`/
     *   `static::CONST`/`Foo::CONST` argument resolve the same way.
     * @param ?string $currentClass Whichever class this call is physically inside, for
     *   `self`/`static::CONST` resolution — null outside any class body.
     * @param ?string $currentParent That class's own `extends` target, for `parent::CONST`.
     * @param array<string,string> $useImports
     */
    private function extractStringArgAt(array $tokens, int $i, int $argIndex, array $varLiteralValues = [], array $classConstants = [], ?string $currentClass = null, ?string $currentParent = null, string $currentNamespace = '', array $useImports = []): ?array
    {
        $argTokens = $this->argTokensAt($tokens, $i, $argIndex);
        return $argTokens === null ? null : $this->classifyArgTokens($argTokens, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
    }

    /**
     * Skip to the '(' after the current function-name token, then collect the raw (whitespace-
     * stripped) tokens of the argument at $argIndex (0-indexed), splitting on top-level commas.
     * Returns null if the call doesn't have that many arguments. Shared by extractStringArgAt()
     * (which classifies the result as a string shape) and resolveReturnLiterals() (which instead
     * checks for a bare variable).
     *
     * @param list<Token> $tokens
     * @return list<Token>|null
     */
    private function argTokensAt(array $tokens, int $i, int $argIndex): ?array
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            $t = $tokens[$j];
            if (is_string($t) && $t === '(') {
                $j++;
                break;
            }
            $j++;
        }

        $currentIndex = 0;
        $depth = 0;
        $argTokens = [];

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t) && $t === '(') {
                $depth++;
                $argTokens[] = $t;
            } elseif (is_string($t) && $t === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                $argTokens[] = $t;
            } elseif (is_string($t) && $t === ',' && $depth === 0) {
                if ($currentIndex === $argIndex) {
                    return $argTokens;
                }
                $currentIndex++;
                $argTokens = [];
            } elseif (is_array($t) && $t[0] === T_WHITESPACE) {
                // skip
            } else {
                $argTokens[] = $t;
            }

            $j++;
        }

        return $currentIndex === $argIndex ? $argTokens : null;
    }

    /**
     * Resolves a scoped call's sole already-collected argument (see argTokensAt) to candidate
     * literal strings when it's either a bare tracked variable (`Foo::view($row_view)`) or
     * `'literal' . $var` (`Foo::view('settings/settings-' . $tab)`) — the two real-world shapes
     * $pendingParamSuffixCalls covers. Returns null for anything else (a transform, more than one
     * concatenation segment, an untracked/empty-domain variable).
     *
     * @param list<Token> $argTokens
     * @param array<string,list<string>> $varLiteralAssignments
     * @return list<string>|null
     */
    private function resolveParamSuffixCallArgumentCandidates(array $argTokens, array $varLiteralAssignments): ?array
    {
        if (count($argTokens) === 1 && is_array($argTokens[0]) && $argTokens[0][0] === T_VARIABLE) {
            $domain = $varLiteralAssignments[$argTokens[0][1]] ?? [];
            return $domain !== [] ? $domain : null;
        }

        if (
            count($argTokens) === 3
            && is_array($argTokens[0]) && $argTokens[0][0] === T_CONSTANT_ENCAPSED_STRING
            && $argTokens[1] === '.'
            && is_array($argTokens[2]) && $argTokens[2][0] === T_VARIABLE
        ) {
            $domain = $varLiteralAssignments[$argTokens[2][1]] ?? [];
            if ($domain === []) {
                return null;
            }
            $prefix = $this->stripQuotes($argTokens[0][1]);

            return array_map(static fn(string $v) => $prefix . $v, $domain);
        }

        return null;
    }

    /**
     * Classifies a single already-collected argument as one of three shapes:
     *  - a fully literal string, nothing else: exact tag known, not dynamic.
     *  - a string with a resolvable literal *prefix* — an interpolated double-quoted string
     *    ("acf/settings/{$name}") or a literal segment followed by concatenation
     *    ('acf/settings/' . $name) — exact tag unknown, but everything up to the first
     *    variable/expression is. This is what turns something like ACF's single dynamic
     *    dispatcher (every acf/settings/* hook fires through one apply_filters call) into a
     *    still-useful signal instead of a total blind spot.
     *  - a string with a resolvable literal *suffix* instead — dynamic *first*, literal *last*
     *    ("{$this->id_base}_widget_updated" or $dynamic . '_widget_updated') — rarer than the
     *    prefix shape in WP code (convention overwhelmingly puts the static/plugin-specific part
     *    first and the dynamic per-instance part last), but real (per-widget-ID or per-post-type
     *    hook naming). Checked only once none of the prefix shapes above already matched, so a
     *    string with *both* a literal prefix and suffix (rare) still credits the prefix, the same
     *    "first match wins, don't over-engineer" stance the rest of this method already takes.
     *  - anything else (bare variable, function call, array, ...): no literal information at
     *    all — unless the argument is exactly one bare variable *and* $varLiteralValues has a
     *    known literal value for it (`$hook = 'my_plugin_loaded'; do_action($hook);`), or exactly
     *    a `self`/`static`/`parent`/`Foo::CONST` reference resolvable against $classConstants
     *    (`do_action(self::HOOK_NAME)`), in which case it resolves exactly the same way a literal
     *    directly in the call already would.
     *
     * @param list<mixed> $argTokens
     * @param array<string,string> $varLiteralValues
     * @param array<string,array<string,string>> $classConstants
     * @return array{string, bool, string, string} [exact tag or '', isDynamic, literal prefix, literal suffix]
     * @param array<string,string> $useImports
     */
    private function classifyArgTokens(array $argTokens, array $varLiteralValues = [], array $classConstants = [], ?string $currentClass = null, ?string $currentParent = null, string $currentNamespace = '', array $useImports = []): array
    {
        if (count($argTokens) === 1 && is_array($argTokens[0]) && $argTokens[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = $this->stripQuotes($argTokens[0][1]);
            return [$value, false, $value, ''];
        }

        if (count($argTokens) === 1 && is_array($argTokens[0]) && $argTokens[0][0] === T_VARIABLE) {
            $value = $varLiteralValues[$argTokens[0][1]] ?? null;
            if ($value !== null) {
                return [$value, false, $value, ''];
            }
        }

        if (
            count($argTokens) === 3
            && is_array($argTokens[0]) && (in_array($argTokens[0][0], self::CLASS_NAME_TOKENS, true) || $argTokens[0][0] === T_STATIC)
            && is_array($argTokens[1]) && $argTokens[1][0] === T_DOUBLE_COLON
            && is_array($argTokens[2]) && $argTokens[2][0] === T_STRING
        ) {
            $value = $this->resolveScopedClassConstant($argTokens[0], $argTokens[2][1], $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
            if ($value !== null) {
                return [$value, false, $value, ''];
            }
        }

        if (
            isset($argTokens[0], $argTokens[1])
            && is_array($argTokens[0]) && $argTokens[0][0] === T_CONSTANT_ENCAPSED_STRING
            && is_string($argTokens[1]) && $argTokens[1] === '.'
        ) {
            return ['', true, $this->stripQuotes($argTokens[0][1]), ''];
        }

        if (
            isset($argTokens[0], $argTokens[1])
            && is_string($argTokens[0]) && $argTokens[0] === '"'
            && is_array($argTokens[1]) && $argTokens[1][0] === T_ENCAPSED_AND_WHITESPACE
        ) {
            return ['', true, $argTokens[1][1], ''];
        }

        $lastIndex = count($argTokens) - 1;
        if ($lastIndex >= 1) {
            $last = $argTokens[$lastIndex];
            $secondLast = $argTokens[$lastIndex - 1];

            // $dynamic . 'literal_suffix' — the mirror of the concatenation-prefix case above,
            // checking the *last* two tokens instead of the first two.
            if (is_array($last) && $last[0] === T_CONSTANT_ENCAPSED_STRING && is_string($secondLast) && $secondLast === '.') {
                return ['', true, '', $this->stripQuotes($last[1])];
            }

            // "{$this->id_base}_widget_updated" — an interpolated string ending in a literal
            // segment right before the closing quote, the mirror of the interpolated-prefix case
            // above. Doesn't need to understand what precedes the trailing literal (a property
            // access, a function call, several concatenated variables, ...) — only that it isn't
            // itself a literal, or the prefix case earlier would already have matched it.
            if (is_string($last) && $last === '"' && is_array($secondLast) && $secondLast[0] === T_ENCAPSED_AND_WHITESPACE) {
                return ['', true, '', $secondLast[1]];
            }
        }

        return ['', true, '', ''];
    }

    /**
     * $receiverToken is the `self`/`static`/`parent`/class-name token immediately before `::` in
     * a `Receiver::CONST_NAME` expression; $constName is the constant's own bare name. Resolves
     * `self`/`static` to $currentClass, `parent` to $currentParent, and any other identifier
     * through `resolveFqcn` against the current namespace/imports, then looks up the resolved
     * class's literal constant value. Shared by classifyArgTokens() (a hook/template-part
     * argument) and resolveReturnLiterals() (a bare `return self::CONST;`-style method body) so
     * both recognize the exact same set of constant-reference shapes.
     *
     * @param array<string,array<string,string>> $classConstants
     * @param array<string,string> $useImports
     */
    private function resolveScopedClassConstant(mixed $receiverToken, string $constName, array $classConstants, ?string $currentClass, ?string $currentParent, string $currentNamespace, array $useImports): ?string
    {
        $receiverName = is_array($receiverToken) && $receiverToken[0] === T_STATIC ? 'static' : (is_array($receiverToken) ? $receiverToken[1] : '');
        $receiverClass = match (strtolower($receiverName)) {
            'self', 'static' => $currentClass,
            'parent' => $currentParent,
            default => $this->resolveFqcn($receiverName, $currentNamespace, $useImports),
        };
        return $receiverClass !== null ? ($classConstants[$receiverClass][$constName] ?? null) : null;
    }

    /** @param list<Token> $tokens */
    private function nextMeaningfulIsIdentifier(array $tokens, int $i): bool
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        return isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING;
    }

    /**
     * $constIndex points at the `const` keyword directly inside a class/interface/trait/enum
     * body. Parses one or more `NAME = 'literal'` pairs (PHP allows several per `const`
     * statement, comma-separated) and returns only the ones whose value is a bare string literal
     * — same "don't guess" stance as everywhere else in this parser: a value built from
     * concatenation, another constant, an expression, etc. is silently skipped rather than
     * resolved wrong. Deliberately doesn't attempt PHP 8.3+ typed constants (`const string NAME
     * = ...`) — rare enough in current WP code that guessing which token is the type versus the
     * name isn't worth the risk of misparsing an untyped one by mistake.
     *
     * @return array<string,string>
     * @param list<Token> $tokens
     */
    private function parseClassConstants(array $tokens, int $constIndex): array
    {
        $constants = [];
        $j = $this->skipInsignificant($tokens, $constIndex + 1);

        while (isset($tokens[$j])) {
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                break;
            }
            $name = $tokens[$j][1];

            $j = $this->skipInsignificant($tokens, $j + 1);
            if (!isset($tokens[$j]) || $tokens[$j] !== '=') {
                break;
            }

            $valueIndex = $this->skipInsignificant($tokens, $j + 1);
            if (isset($tokens[$valueIndex]) && is_array($tokens[$valueIndex]) && $tokens[$valueIndex][0] === T_CONSTANT_ENCAPSED_STRING) {
                $afterValue = $this->skipInsignificant($tokens, $valueIndex + 1);
                if (isset($tokens[$afterValue]) && ($tokens[$afterValue] === ',' || $tokens[$afterValue] === ';')) {
                    // A bare literal, nothing appended (no concatenation) — trust it.
                    $constants[$name] = $this->stripQuotes($tokens[$valueIndex][1]);
                }
            }

            // Advance to the next top-level ',' (another constant in this same statement) or
            // ';' (end) regardless of whether this constant's own value was a trusted literal —
            // depth-tracked so a nested array/call value's own commas aren't mistaken for the
            // constant-list separator.
            $depth = 0;
            $j = $valueIndex;
            while (isset($tokens[$j])) {
                $t = $tokens[$j];
                if ($t === '(' || $t === '[') {
                    $depth++;
                } elseif ($t === ')' || $t === ']') {
                    $depth--;
                } elseif ($depth === 0 && ($t === ',' || $t === ';')) {
                    break;
                }
                $j++;
            }
            if (!isset($tokens[$j]) || $tokens[$j] === ';') {
                break;
            }
            $j = $this->skipInsignificant($tokens, $j + 1);
        }

        return $constants;
    }

    /**
     * @param 'class'|'interface'|'trait'|'enum' $kind
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function parseClassDef(array $tokens, int $i, string $file, string $kind, string $currentNamespace, array $useImports): ?ClassDef
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        $next = $tokens[$j] ?? null;
        if (!is_array($next) || $next[0] !== T_STRING) {
            return null;
        }

        [$extends, $implements] = $this->findClassHierarchy($tokens, $j, $currentNamespace, $useImports);

        $fqcn = $currentNamespace === '' ? $next[1] : $currentNamespace . '\\' . $next[1];

        return new ClassDef($next[1], $fqcn, $next[2], $file, $extends, $implements, $kind);
    }

    /**
     * Looks ahead from the class-name token, past any `extends`/`implements` clauses, to the
     * declaration's opening brace — used to know which known interfaces/base classes a class
     * commits to, e.g. so `implements Iterator` can exempt current()/next()/etc. from being
     * flagged as unused methods. Doesn't advance the main token loop; the main loop's own
     * T_EXTENDS/T_IMPLEMENTS handling (which feeds the flat classReferences list) still sees
     * these same tokens afterwards, same as parseFunctionDef's lookahead does for T_STRING.
     *
     * @return array{list<ClassRef>, list<ClassRef>}
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function findClassHierarchy(array $tokens, int $nameIndex, string $currentNamespace, array $useImports): array
    {
        $extends = [];
        $implements = [];
        $j = $nameIndex + 1;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '{' || $t === ';') {
                    break;
                }
                $j++;
                continue;
            }

            if ($t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }

            if ($t[0] === T_EXTENDS) {
                $extends = $this->captureClassNameList($tokens, $j, $currentNamespace, $useImports);
            } elseif ($t[0] === T_IMPLEMENTS) {
                $implements = $this->captureClassNameList($tokens, $j, $currentNamespace, $useImports);
            }

            $j++;
        }

        return [$extends, $implements];
    }

    /** @param list<Token> $tokens */
    private function isPrecededByNew(array $tokens, int $i): bool
    {
        $j = $i - 1;
        while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j--;
        }
        return $j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_NEW;
    }

    /**
     * Reads the class name following `new` or `instanceof`. Skips non-name cases such as
     * `new class {}` (anonymous — next token is T_CLASS) and `new static()` (T_STATIC, a
     * late-static-binding placeholder, not a literal class name). Resolved through any
     * use-import alias first (`use Foo\Bar as Alias; new Alias()`) — real-world case (Elementor):
     * `use Some\Namespace\Loader as Assets_Loader; new Assets_Loader()` previously recorded the
     * alias itself as the class reference, never matching `Loader`'s own declared short name, so
     * the class looked permanently unused despite the real, resolvable `new` right there.
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function captureClassNameAfter(array $tokens, int $i, string $currentNamespace, array $useImports): ?string
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        $next = $tokens[$j] ?? null;
        if (!is_array($next) || !in_array($next[0], self::CLASS_NAME_TOKENS, true)) {
            return null;
        }

        return $this->shortClassName($this->resolveFqcn($next[1], $currentNamespace, $useImports));
    }

    /**
     * Reads a comma-separated list of class/interface names after `extends` or `implements`,
     * e.g. `class A extends B implements C, D`. Stops at the class body's opening brace, the
     * end of an interface-only declaration, or a following `implements` clause.
     *
     * @return list<ClassRef>
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function captureClassNameList(array $tokens, int $i, string $currentNamespace, array $useImports): array
    {
        $names = [];
        $j = $i + 1;

        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === '{' || $t === ';') {
                    break;
                }
                $j++;
                continue;
            }

            if ($t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }

            if ($t[0] === T_IMPLEMENTS) {
                break;
            }

            if (in_array($t[0], self::CLASS_NAME_TOKENS, true)) {
                // newClassRefFor() derives $short from the resolved FQCN (not the raw token
                // text) so an aliased reference (`use Some\Widget as Base; class Foo extends
                // Base`) still yields "Widget" — its real declared short name — for both of
                // $short's consumers: findUnusedClasses()'s $classReferences match and
                // ClassAnalyzer's curated-table (BASE_CLASS_CONTRACT_METHODS/interface) lookup,
                // which is keyed by the class's real bare name, not whatever local alias a
                // particular file happened to import it under.
                $names[] = $this->newClassRefFor($t[1], $currentNamespace, $useImports);
            }

            $j++;
        }

        return $names;
    }

    /**
     * Parses a file-level `use Name\Space\Foo [as Alias], Other\Bar;` or
     * `use function Name\Space\foo [as alias], Other\bar;` statement starting at the T_USE token,
     * returning `[$classImports, $functionImports]` (each alias/short-name => fully-qualified
     * name). Handles multiple comma-separated imports on one line and `as` aliasing. Deliberately
     * does NOT support group-use (`use App\{Foo, Bar as B};`) — bails out (returning whatever it
     * already collected) the moment it sees `{`, rather than guessing; the main token loop's own
     * generic brace-depth tracking still balances that `{`/`}` pair correctly on its own since
     * they're real, matched tokens, so bailing here doesn't corrupt anything downstream.
     * `use const ...` imports affect neither classes nor function-call resolution, so they're
     * skipped entirely (empty tuple).
     *
     * A `use function`-imported name isn't just another class-import lookalike: unlike
     * $classImports (which only ever disambiguates a class *reference*), it changes what a later
     * BARE call actually resolves to — see $useFunctionImports' own declaration-site comment in
     * parse() for why a matching entry there takes priority over the usual current-namespace-
     * then-global runtime fallback a bare call would otherwise get.
     *
     * @param list<Token> $tokens
     * @return array{array<string,string>,array<string,string>}
     */
    private function parseUseImports(array $tokens, int $i): array
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_CONST) {
            return [[], []];
        }

        $isFunctionImport = isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION;
        if ($isFunctionImport) {
            $j++;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
        }

        $imports = [];
        while (isset($tokens[$j])) {
            $t = $tokens[$j];

            if (is_string($t)) {
                if ($t === ';' || $t === '{') {
                    break;
                }
                $j++;
                continue;
            }

            if ($t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }

            if (in_array($t[0], self::CLASS_NAME_TOKENS, true)) {
                $fqcn = ltrim($t[1], '\\');
                $alias = $this->shortClassName($fqcn);

                $k = $j + 1;
                while (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    $k++;
                }
                if (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_AS) {
                    $k++;
                    while (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k++;
                    }
                    if (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                        $alias = $tokens[$k][1];
                        $j = $k;
                    }
                }

                $imports[$alias] = $fqcn;
            }

            $j++;
        }

        return $isFunctionImport ? [[], $imports] : [$imports, []];
    }

    private function shortClassName(string $name): string
    {
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /**
     * Resolves a class-name token's raw text to its real fully-qualified name, per PHP's own
     * unqualified/qualified/fully-qualified name-resolution rules: a leading `\` is already
     * fully qualified (strip it and use as-is); otherwise, if the name's first segment matches a
     * `use`-imported alias, substitute that import's FQCN for that first segment; otherwise the
     * name resolves relative to the current namespace (unchanged if there is none — the common
     * case for un-namespaced WP code, where this reduces to exactly $name).
     *
     * @param array<string,string> $useImports
     */
    private function resolveFqcn(string $name, string $currentNamespace, array $useImports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $sepPos = strpos($name, '\\');
        $firstSegment = $sepPos === false ? $name : substr($name, 0, $sepPos);

        if (isset($useImports[$firstSegment])) {
            $imported = $useImports[$firstSegment];
            return $sepPos === false ? $imported : $imported . substr($name, $sepPos);
        }

        return $currentNamespace === '' ? $name : $currentNamespace . '\\' . $name;
    }

    /**
     * Builds a ClassRef for a bare (non self/parent/static) class-name token, resolving through
     * any use-import alias first so `$short` reflects the class's real declared name rather than
     * whatever local alias it was referenced under — see ClassRef's own docblock.
     *
     * @param array<string,string> $useImports
     */
    private function newClassRefFor(string $name, string $currentNamespace, array $useImports): ClassRef
    {
        $fqcn = $this->resolveFqcn($name, $currentNamespace, $useImports);

        return new ClassRef($this->shortClassName($fqcn), $fqcn);
    }

    /**
     * $i points at T_FOR. Recognizes only the clean canonical bounded-ascending form —
     * `for ($var = <int>; $var <op> <int>; $var++)` where <op> is '<' or '<=', both bounds plain
     * non-negative decimal integer literals — and returns null for anything else (a non-literal
     * bound, a mismatched/different variable in the condition or increment, a decrementing loop,
     * a step other than 1, ...). Same "don't guess" stance every other literal-resolution
     * mechanism in this parser takes: the payoff (enumerating a handful of concrete suffix
     * values from a *provably* finite, literal range — see resolveForLoopConcatenatedLiteral)
     * isn't worth guessing wrong on a shape a naive reader couldn't already tell terminates.
     * Bounded to a sane max range size as a defensive cap, not a realistic real-world limit.
     *
     * @param list<Token> $tokens
     * @return array{string, list<int>}|null [loopVarName, every value the loop actually assigns]
     */
    private function parseForLoopBoundedRange(array $tokens, int $i): ?array
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
            return null;
        }
        $varName = $tokens[$j][1];

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '=') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_LNUMBER || !ctype_digit($tokens[$j][1])) {
            return null;
        }
        $start = (int) $tokens[$j][1];

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ';') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE || $tokens[$j][1] !== $varName) {
            return null;
        }

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j])) {
            return null;
        }
        if ($tokens[$j] === '<') {
            $inclusive = false;
        } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_IS_SMALLER_OR_EQUAL) {
            $inclusive = true;
        } else {
            return null;
        }

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_LNUMBER || !ctype_digit($tokens[$j][1])) {
            return null;
        }
        $bound = (int) $tokens[$j][1];

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ';') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE || $tokens[$j][1] !== $varName) {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_INC) {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ')') {
            return null;
        }

        $end = $inclusive ? $bound : $bound - 1;
        if ($end < $start || ($end - $start) > 10000) {
            return null;
        }

        return [$varName, range($start, $end)];
    }

    /**
     * $i points at a T_CONSTANT_ENCAPSED_STRING token already read as $prefixValue. Real-world
     * shapes this covers: Sydney theme's customizer partials (`'render_callback' =>
     * 'sydney_partial_slider_title_' . $i` inside `for ($i = 1; $i < 5; $i++)`) and Astra
     * theme's numbered icon files (`'icons-v6-' . $i . '.php'`-shaped concatenation, inside a
     * similar bounded loop) — a callback name or file-path literal whose real identity is split
     * across a loop-counter concatenation this parser otherwise can't evaluate at all, silently
     * missing every one of the N real declarations/files the loop actually produces.
     *
     * If $prefixValue is immediately followed by `. $loopVar` where $loopVar matches a
     * currently-open, tracked bounded for-loop variable (innermost/most-recently-opened wins on
     * a name collision across nested loops — the same "flat stack, last match wins" convention
     * every other per-scope stack in this parser already uses), optionally followed by
     * `. 'literal-suffix'`, returns one concrete `"{$prefixValue}{value}{suffix}"` string per
     * value the loop variable actually takes across its known range, plus the index of the last
     * token consumed (so the caller can sync $i past the variable/suffix instead of
     * re-processing them as anything else). Returns null when the shape doesn't match — no `.`
     * follows, the variable isn't tracked, or nothing looks like this pattern at all — the same
     * safe fallback as returning no enumeration ever did before this mechanism existed.
     *
     * @param list<Token> $tokens
     * @param list<string> $forLoopVarNameStack
     * @param list<list<int>> $forLoopVarValuesStack
     * @return array{list<string>, int}|null [enumerated strings, last consumed token index]
     */
    private function resolveForLoopConcatenatedLiteral(
        array $tokens,
        int $i,
        string $prefixValue,
        array $forLoopVarNameStack,
        array $forLoopVarValuesStack,
    ): ?array {
        $dotIndex = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$dotIndex]) || $tokens[$dotIndex] !== '.') {
            return null;
        }
        $varIndex = $this->skipInsignificant($tokens, $dotIndex + 1);
        if (!isset($tokens[$varIndex]) || !is_array($tokens[$varIndex]) || $tokens[$varIndex][0] !== T_VARIABLE) {
            return null;
        }
        $varName = $tokens[$varIndex][1];

        $values = null;
        for ($k = count($forLoopVarNameStack) - 1; $k >= 0; $k--) {
            if ($forLoopVarNameStack[$k] === $varName) {
                $values = $forLoopVarValuesStack[$k];
                break;
            }
        }
        if ($values === null) {
            return null;
        }

        $lastIndex = $varIndex;
        $suffix = '';
        $afterVarIndex = $this->skipInsignificant($tokens, $varIndex + 1);
        if (isset($tokens[$afterVarIndex]) && $tokens[$afterVarIndex] === '.') {
            $suffixIndex = $this->skipInsignificant($tokens, $afterVarIndex + 1);
            if (isset($tokens[$suffixIndex]) && is_array($tokens[$suffixIndex]) && $tokens[$suffixIndex][0] === T_CONSTANT_ENCAPSED_STRING) {
                $suffix = $this->stripQuotes($tokens[$suffixIndex][1]);
                $lastIndex = $suffixIndex;
            }
        }

        $results = [];
        foreach ($values as $value) {
            $results[] = $prefixValue . $value . $suffix;
        }

        return [$results, $lastIndex];
    }

    /**
     * The string-valued sibling of resolveForLoopConcatenatedLiteral just above, for a `foreach`
     * over a tracked literal-string array (see parseForeachLiteralArrayLoop) instead of a bounded
     * integer range. Also recognizes one additional shape the for-loop case has no evidence for:
     * `. substr($loopVar, N)` — a fixed non-negative literal offset applied to the loop variable
     * before concatenation. Real-world shape (WooCommerce's breadcrumb conditional dispatch,
     * includes/class-wc-breadcrumb.php): `foreach ($conditionals as $conditional) { ...
     * call_user_func([$this, 'add_crumbs_' . substr($conditional, 3)]); ... }` where
     * $conditionals is a literal array of strings like 'is_404', 'is_attachment' — substr(...,3)
     * strips the common "is_" prefix each one shares. No length argument, no non-literal offset,
     * no other transform function — the one shape with confirmed real-world evidence; anything
     * else (a bare, untransformed variable still goes through the normal path below; any other
     * function call) returns null, same "don't guess" stance as everywhere else in this parser.
     *
     * @param list<Token> $tokens
     * @param list<string> $foreachLoopVarNameStack
     * @param list<list<string>> $foreachLoopVarValuesStack
     * @return array{list<string>, int}|null [enumerated strings, last consumed token index]
     */
    private function resolveForeachConcatenatedLiteral(
        array $tokens,
        int $i,
        string $prefixValue,
        array $foreachLoopVarNameStack,
        array $foreachLoopVarValuesStack,
    ): ?array {
        $dotIndex = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$dotIndex]) || $tokens[$dotIndex] !== '.') {
            return null;
        }
        $exprIndex = $this->skipInsignificant($tokens, $dotIndex + 1);
        if (!isset($tokens[$exprIndex]) || !is_array($tokens[$exprIndex])) {
            return null;
        }

        $transformOffset = null;
        if ($tokens[$exprIndex][0] === T_VARIABLE) {
            $varIndex = $exprIndex;
            $lastIndex = $exprIndex;
        } elseif ($tokens[$exprIndex][0] === T_STRING && strtolower($tokens[$exprIndex][1]) === 'substr') {
            $openParenIndex = $this->skipInsignificant($tokens, $exprIndex + 1);
            if (!isset($tokens[$openParenIndex]) || $tokens[$openParenIndex] !== '(') {
                return null;
            }
            $varIndex = $this->skipInsignificant($tokens, $openParenIndex + 1);
            if (!isset($tokens[$varIndex]) || !is_array($tokens[$varIndex]) || $tokens[$varIndex][0] !== T_VARIABLE) {
                return null;
            }
            $commaIndex = $this->skipInsignificant($tokens, $varIndex + 1);
            if (!isset($tokens[$commaIndex]) || $tokens[$commaIndex] !== ',') {
                return null;
            }
            $offsetIndex = $this->skipInsignificant($tokens, $commaIndex + 1);
            if (!isset($tokens[$offsetIndex]) || !is_array($tokens[$offsetIndex]) || $tokens[$offsetIndex][0] !== T_LNUMBER) {
                return null;
            }
            $closeParenIndex = $this->skipInsignificant($tokens, $offsetIndex + 1);
            if (!isset($tokens[$closeParenIndex]) || $tokens[$closeParenIndex] !== ')') {
                return null; // a 3rd (length) argument or anything else — not attempted
            }
            $transformOffset = (int) $tokens[$offsetIndex][1];
            $lastIndex = $closeParenIndex;
        } else {
            return null;
        }

        $varName = $tokens[$varIndex][1];
        $values = null;
        for ($k = count($foreachLoopVarNameStack) - 1; $k >= 0; $k--) {
            if ($foreachLoopVarNameStack[$k] === $varName) {
                $values = $foreachLoopVarValuesStack[$k];
                break;
            }
        }
        if ($values === null) {
            return null;
        }

        $suffix = '';
        $afterIndex = $this->skipInsignificant($tokens, $lastIndex + 1);
        if (isset($tokens[$afterIndex]) && $tokens[$afterIndex] === '.') {
            $suffixIndex = $this->skipInsignificant($tokens, $afterIndex + 1);
            if (isset($tokens[$suffixIndex]) && is_array($tokens[$suffixIndex]) && $tokens[$suffixIndex][0] === T_CONSTANT_ENCAPSED_STRING) {
                $suffix = $this->stripQuotes($tokens[$suffixIndex][1]);
                $lastIndex = $suffixIndex;
            }
        }

        $results = [];
        foreach ($values as $value) {
            $transformed = $transformOffset !== null ? substr($value, $transformOffset) : $value;
            $results[] = $prefixValue . $transformed . $suffix;
        }

        return [$results, $lastIndex];
    }

    /**
     * $i points at the bare `"` token that opens a genuinely-interpolated double-quoted string
     * (PHP tokenizes a non-interpolated one as a single T_CONSTANT_ENCAPSED_STRING instead, so
     * reaching this bare token at all already means at least one variable is embedded). Walks its
     * segments, recognizing exactly two shapes — a plain literal run
     * (T_ENCAPSED_AND_WHITESPACE) or a single interpolated variable (`$var` or `{$var}`, never
     * `$var[0]`/`$var->prop`/`{$obj->prop}`/`${expr}`) — bailing (null) on anything else (a
     * genuinely dynamic sub-expression this parser can't evaluate, or a malformed/unterminated
     * string). Shared by every consumer that needs to inspect an interpolated string's pieces
     * without evaluating the whole thing (resolveInterpolatedLoopSuffixPath below,
     * resolveCallUserFuncSelfDispatchSuffix).
     *
     * @param list<Token> $tokens
     * @return array{list<array{string,string}>, int}|null [segments (each `['literal', text]` or
     *   `['var', varName]`, in order), the closing '"' token's own index]
     */
    private function collectInterpolatedStringSegments(array $tokens, int $i): ?array
    {
        $segments = [];
        $j = $i + 1;

        while (true) {
            if (!isset($tokens[$j])) {
                return null; // ran off the end without a closing quote — malformed, bail
            }
            $t = $tokens[$j];

            if ($t === '"') {
                break;
            }

            if (is_array($t) && $t[0] === T_ENCAPSED_AND_WHITESPACE) {
                $segments[] = ['literal', $t[1]];
                $j++;
                continue;
            }

            if (is_array($t) && $t[0] === T_VARIABLE) {
                // Simple `$var` interpolation — array/property access right after it ($var[0],
                // $var->prop) isn't attempted; the next loop iteration will simply fail to
                // recognize whatever token follows and bail, same as any other unrecognized shape.
                $segments[] = ['var', $t[1]];
                $j++;
                continue;
            }

            if (is_array($t) && $t[0] === T_CURLY_OPEN) {
                if (!isset($tokens[$j + 1]) || !is_array($tokens[$j + 1]) || $tokens[$j + 1][0] !== T_VARIABLE) {
                    return null; // {$obj->prop}, {$arr['key']}, ... — not a bare variable
                }
                if (!isset($tokens[$j + 2]) || $tokens[$j + 2] !== '}') {
                    return null;
                }
                $segments[] = ['var', $tokens[$j + 1][1]];
                $j += 3;
                continue;
            }

            // T_DOLLAR_OPEN_CURLY_BRACES (${expr}), or anything else unrecognized inside the
            // interpolation — bail rather than guess.
            return null;
        }

        return [$segments, $j];
    }

    /**
     * $i points at the bare `"` token that opens a genuinely-interpolated double-quoted string
     * (PHP tokenizes a non-interpolated one as a single T_CONSTANT_ENCAPSED_STRING instead, so
     * reaching this bare token at all already means at least one variable is embedded). Real-
     * world shape this covers (Astra theme): `"{$icons_dir}/icons-v6-{$i}.php"` inside
     * `for ($i = 0; $i < 4; $i++)` — `$icons_dir` is derived from a WP-core `plugin_dir_path()`
     * call this parser has no way to resolve (and, being an absolute filesystem path, wouldn't
     * even be a meaningful project-relative reference if it could be). This deliberately never
     * attempts to resolve the *whole* interpolated string: it keeps only the literal segment
     * directly adjacent to the matched loop variable on each side ("/icons-v6-" and ".php" here)
     * and discards anything further out (`$icons_dir` itself, and whatever came before it, if
     * anything) — an unresolved, non-adjacent leading segment is irrelevant once matching happens
     * by basename alone anyway (see phpPathStrings' own basename-indexing in
     * FileAnalyzer::buildReferencedIndex()), and a leading '/' left over from the adjacent
     * literal (as here) doesn't change what basename()/pathinfo() extract from it either.
     *
     * Bails (returns null) unless every segment is one of exactly two recognized shapes — a
     * plain literal run (T_ENCAPSED_AND_WHITESPACE) or a single interpolated variable (`$var` or
     * `{$var}`, never `$var[0]`/`$var->prop`/`{$obj->prop}`/`${expr}`) — and the LAST variable
     * segment matches a currently-tracked bounded for-loop variable with nothing but a single
     * trailing literal segment (ending in '.php' — the only consumption context with any
     * real-world evidence) after it. Any other shape (two different loop variables, a variable
     * after the last recognized one that isn't tracked, a non-'.php' suffix, ...) is left
     * completely unresolved, same as before this mechanism existed — deliberately narrow rather
     * than a general interpolated-string evaluator.
     *
     * @param list<Token> $tokens
     * @param list<string> $forLoopVarNameStack
     * @param list<list<int|string>> $forLoopVarValuesStack Bounded numeric for-loop values are
     *   ints; a foreach/collect()->each() string-array domain (see parseCollectEachLoop) is
     *   strings — plain concatenation below treats both identically.
     * @return array{list<string>, int}|null [enumerated basenames, last consumed token index —
     *   the closing '"']
     */
    private function resolveInterpolatedLoopSuffixPath(
        array $tokens,
        int $i,
        array $forLoopVarNameStack,
        array $forLoopVarValuesStack,
    ): ?array {
        $collected = $this->collectInterpolatedStringSegments($tokens, $i);
        if ($collected === null) {
            return null;
        }
        [$segments, $closingQuoteIndex] = $collected;

        $lastVarSegmentIndex = null;
        foreach ($segments as $idx => $segment) {
            if ($segment[0] === 'var') {
                $lastVarSegmentIndex = $idx;
            }
        }
        if ($lastVarSegmentIndex === null) {
            return null;
        }
        for ($idx = $lastVarSegmentIndex + 1; $idx < count($segments); $idx++) {
            if ($segments[$idx][0] !== 'literal') {
                return null; // another variable after the last recognized one — too complex
            }
        }

        $varName = $segments[$lastVarSegmentIndex][1];
        $values = null;
        for ($k = count($forLoopVarNameStack) - 1; $k >= 0; $k--) {
            if ($forLoopVarNameStack[$k] === $varName) {
                $values = $forLoopVarValuesStack[$k];
                break;
            }
        }
        if ($values === null) {
            return null; // not a tracked bounded loop variable
        }

        // The literal segment directly abutting the matched variable (on either side) is the
        // meaningful filename text ("icons-v6-" / ".php" in Astra's shape) — kept even though
        // whatever came *before* that prefix segment (an earlier, unrelated/unresolved variable,
        // or nothing at all) is discarded. Only a literal immediately adjacent counts as prefix;
        // if the preceding segment is itself another variable (no literal directly touching the
        // matched one), there's no stable filename text to anchor on, so prefix stays empty.
        $prefix = ($lastVarSegmentIndex > 0 && $segments[$lastVarSegmentIndex - 1][0] === 'literal')
            ? $segments[$lastVarSegmentIndex - 1][1]
            : '';
        $suffix = '';
        for ($idx = $lastVarSegmentIndex + 1; $idx < count($segments); $idx++) {
            $suffix .= $segments[$idx][1];
        }
        if (!str_ends_with($suffix, '.php')) {
            return null;
        }

        $results = [];
        foreach ($values as $value) {
            $results[] = $prefix . $value . $suffix;
        }

        return [$results, $closingQuoteIndex];
    }

    /**
     * Given a set of interpolated-string segments (see collectInterpolatedStringSegments),
     * returns the literal text trailing the LAST embedded variable — nothing else about the
     * string is resolved (whatever came before that variable, or between two variables, is
     * discarded). Returns null when there's no variable segment at all, or when the segment
     * right after it isn't a plain literal run (another variable — too complex to resolve), or
     * when the trailing literal is empty (`"{$var}"` alone carries no suffix to anchor on).
     *
     * @param list<array{string,string}> $segments
     */
    private function extractTrailingVarSuffix(array $segments): ?string
    {
        $lastVarSegmentIndex = null;
        foreach ($segments as $idx => $segment) {
            if ($segment[0] === 'var') {
                $lastVarSegmentIndex = $idx;
            }
        }
        if ($lastVarSegmentIndex === null) {
            return null;
        }

        $suffix = '';
        for ($idx = $lastVarSegmentIndex + 1; $idx < count($segments); $idx++) {
            if ($segments[$idx][0] !== 'literal') {
                return null; // another variable after the last recognized one — too complex
            }
            $suffix .= $segments[$idx][1];
        }

        return $suffix !== '' ? $suffix : null;
    }

    /**
     * $quoteIndex points at the interpolated string's opening `"`, $closingQuoteIndex at its
     * closing one (see collectInterpolatedStringSegments). True when it's the second element of
     * a `[$this, "..."]` / `array($this, "...")` array-callback literal — the one real-world
     * shape a self-dispatch suffix template (resolveSelfDispatchSuffix's own caller) is built
     * from. Same backward-walk idea as arrayCallbackReceiverClass, just narrowed to the `$this`
     * receiver only (no evidence yet for `self::class`/`Foo::class`/literal-string receivers in
     * this specific shape) and a forward check that the string is immediately followed by the
     * array's own closing `)`/`]` (not a 3rd+ element — same false-positive risk
     * arrayCallbackReceiverClass's own docblock describes for a plain list of unrelated strings).
     *
     * @param list<Token> $tokens
     */
    private function isThisArrayCallbackReceiverAt(array $tokens, int $quoteIndex, int $closingQuoteIndex): bool
    {
        $afterIndex = $this->peekNextMeaningfulIndex($tokens, $closingQuoteIndex);
        if ($afterIndex === null || ($tokens[$afterIndex] !== ')' && $tokens[$afterIndex] !== ']')) {
            return false;
        }

        return $this->isPrecededByThisArrayCallbackComma($tokens, $quoteIndex);
    }

    /**
     * True when $index is immediately preceded by `$this ,` and that `,` sits right after a
     * `[`/`array(` array-callback open — i.e. $index is the second element of `[$this, ...]` /
     * `array($this, ...)`. The shared backward half of isThisArrayCallbackReceiverAt and
     * resolvePrefixVarSuffixSelfDispatchTemplate, which differ only in how far forward they walk
     * to confirm the element they found is also the array's last one.
     *
     * @param list<Token> $tokens
     */
    private function isPrecededByThisArrayCallbackComma(array $tokens, int $index): bool
    {
        $commaIndex = $this->peekPrevMeaningfulIndex($tokens, $index);
        if ($commaIndex === null || $tokens[$commaIndex] !== ',') {
            return false;
        }
        $thisIndex = $this->peekPrevMeaningfulIndex($tokens, $commaIndex);
        if ($thisIndex === null || !is_array($tokens[$thisIndex]) || $tokens[$thisIndex][0] !== T_VARIABLE || $tokens[$thisIndex][1] !== '$this') {
            return false;
        }
        $openIndex = $this->peekPrevMeaningfulIndex($tokens, $thisIndex);

        return $openIndex !== null && $this->isArrayOpenAt($tokens, $openIndex);
    }

    /**
     * $prefixIndex points at a T_CONSTANT_ENCAPSED_STRING token. Recognizes `array( $this,
     * 'prefix' . $param . 'suffix' )` / `[ $this, 'prefix' . $param . 'suffix' ]` — the plain-
     * concatenation counterpart to isThisArrayCallbackReceiverAt's interpolated-string shape.
     * Real-world shape (WooCommerce, abstract-class-wc-admin-list-table.php /
     * Internal/Admin/Orders/ListTable.php): `is_callable( array( $this, 'render_' . $column .
     * '_column' ) )`. Doesn't verify $param is actually the enclosing method's own declared
     * parameter — same small "coarse net" concession resolveReturnParamSuffixTemplate makes.
     * Returns null for anything else (no `$this`-receiver array-callback, more than one
     * concatenated variable, no literal suffix, not the array's last element).
     *
     * @param list<Token> $tokens
     * @return array{string, string}|null [prefix, suffix]
     */
    private function resolvePrefixVarSuffixSelfDispatchTemplate(array $tokens, int $prefixIndex): ?array
    {
        if (!$this->isPrecededByThisArrayCallbackComma($tokens, $prefixIndex)) {
            return null;
        }

        $dot1Index = $this->peekNextMeaningfulIndex($tokens, $prefixIndex);
        if ($dot1Index === null || $tokens[$dot1Index] !== '.') {
            return null;
        }
        $varIndex = $this->peekNextMeaningfulIndex($tokens, $dot1Index);
        if ($varIndex === null || !is_array($tokens[$varIndex]) || $tokens[$varIndex][0] !== T_VARIABLE) {
            return null;
        }
        $dot2Index = $this->peekNextMeaningfulIndex($tokens, $varIndex);
        if ($dot2Index === null || $tokens[$dot2Index] !== '.') {
            return null;
        }
        $suffixIndex = $this->peekNextMeaningfulIndex($tokens, $dot2Index);
        if ($suffixIndex === null || !is_array($tokens[$suffixIndex]) || $tokens[$suffixIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $afterIndex = $this->peekNextMeaningfulIndex($tokens, $suffixIndex);
        if ($afterIndex === null || ($tokens[$afterIndex] !== ')' && $tokens[$afterIndex] !== ']')) {
            return null; // not the array's last element
        }

        return [$this->stripQuotes($tokens[$prefixIndex][1]), $this->stripQuotes($tokens[$suffixIndex][1])];
    }

    /**
     * $varIndex points at a T_VARIABLE token. Recognizes `$var['literal'] = ...;` — a literal
     * string array-key assignment — regardless of the variable's own name (see
     * $classArrayKeyLiterals' own declaration comment for the real-world shape this covers).
     * Returns the literal key, or null for anything else (a non-literal/dynamic key, a
     * comparison instead of assignment, no array access at all).
     *
     * @param list<Token> $tokens
     */
    private function arrayKeyLiteralAssignment(array $tokens, int $varIndex): ?string
    {
        $openIndex = $this->peekNextMeaningfulIndex($tokens, $varIndex);
        if ($openIndex === null || $tokens[$openIndex] !== '[') {
            return null;
        }
        $keyIndex = $this->peekNextMeaningfulIndex($tokens, $openIndex);
        if ($keyIndex === null || !is_array($tokens[$keyIndex]) || $tokens[$keyIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $closeIndex = $this->peekNextMeaningfulIndex($tokens, $keyIndex);
        if ($closeIndex === null || $tokens[$closeIndex] !== ']') {
            return null;
        }
        $eqIndex = $this->peekNextMeaningfulIndex($tokens, $closeIndex);
        if ($eqIndex === null || $tokens[$eqIndex] !== '=') {
            return null;
        }

        return $this->stripQuotes($tokens[$keyIndex][1]);
    }

    /**
     * $i points at T_RETURN. Recognizes `return array( 'key1' => ..., 'key2' => ..., ... );` /
     * `return [ 'key1' => ..., ... ];` — every top-level element keyed by a plain string
     * literal, each value's own shape left completely unconstrained (real-world case: WPForms'
     * `SmartTags::smart_tags_list()`, whose values are `esc_html__(...)` calls, not literals
     * themselves — only the keys matter to $classArrayKeyLiterals, see its own docblock).
     * Bails (returns null) the moment any top-level element's key isn't a plain string literal
     * (a computed/dynamic key, or an unkeyed element mixed in), or there are fewer than two
     * elements — same "bail rather than guess" stance parseAnyStringLiteralArray takes just
     * below.
     *
     * @param list<Token> $tokens
     * @return list<string>|null
     */
    private function resolveReturnedKeyedArrayLiteralKeys(array $tokens, int $i): ?array
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j])) {
            return null;
        }

        if (is_array($tokens[$j]) && $tokens[$j][0] === T_ARRAY) {
            $j = $this->skipInsignificant($tokens, $j + 1);
            if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
                return null;
            }
            $close = ')';
        } elseif ($tokens[$j] === '[') {
            $close = ']';
        } else {
            return null;
        }

        $j++;
        $keys = [];
        $expectingKey = true;
        $depth = 0;

        while (isset($tokens[$j])) {
            if ($expectingKey) {
                $j = $this->skipInsignificant($tokens, $j);
                if (!isset($tokens[$j])) {
                    return null;
                }
                $t = $tokens[$j];

                if ($t === $close) {
                    return count($keys) < 2 ? null : $keys;
                }

                if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    return null;
                }

                $arrowIndex = $this->skipInsignificant($tokens, $j + 1);
                if (!isset($tokens[$arrowIndex]) || !is_array($tokens[$arrowIndex]) || $tokens[$arrowIndex][0] !== T_DOUBLE_ARROW) {
                    return null; // not a keyed element — bail entirely rather than guess
                }

                $keys[] = $this->stripQuotes($t[1]);
                $j = $arrowIndex + 1;
                $expectingKey = false;
                continue;
            }

            // Skip the value, tracking nested bracket depth, until this element's own top-level
            // comma or the array's own closing bracket.
            $t = $tokens[$j];
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                if ($depth === 0) {
                    return $t === $close && count($keys) >= 2 ? $keys : null;
                }
                $depth--;
            } elseif ($depth === 0 && $t === ',') {
                $expectingKey = true;
            }
            $j++;
        }

        return null;
    }

    /**
     * $i points at T_FOREACH. Resolves `foreach ( $var as ... )`'s collection expression to
     * $var's tracked literal-array contents, or [] if it isn't a bare tracked variable (any other
     * shape — a method call, a property access, an inline array literal — falls through
     * unresolved, same "only trust the simplest unambiguous shape" stance as
     * parseStringLiteralArray below).
     *
     * @param list<Token> $tokens
     * @param array<string,list<string>> $arrayLiteralVars
     * @return list<string>
     */
    private function resolveForeachArrayLiterals(array $tokens, int $i, array $arrayLiteralVars): array
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return [];
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
            return [];
        }
        return $arrayLiteralVars[$tokens[$j][1]] ?? [];
    }

    /**
     * $i points at T_FOREACH. Resolves `foreach ( $arrayVar as $loopVar )` when $arrayVar is a
     * currently-tracked plain literal-string array (see $arrayLiteralVars) and the loop uses the
     * plain "as $var" form — not "as $k => $v", not by-reference (`as &$var`). Real-world shape
     * this covers (WooCommerce's breadcrumb conditional dispatch,
     * includes/class-wc-breadcrumb.php): `foreach ($conditionals as $conditional) { if
     * (call_user_func($conditional)) { call_user_func([$this, 'add_crumbs_' .
     * substr($conditional, 3)]); break; } }` — feeds resolveForeachConcatenatedLiteral. Any other
     * shape returns null, same "only trust the simplest unambiguous shape" stance as
     * resolveForeachArrayLiterals just above (which this deliberately doesn't merge with, since
     * that one only needs the collection, not the loop variable's own name).
     *
     * @param list<Token> $tokens
     * @param array<string,list<string>> $arrayLiteralVars
     * @return array{string, list<string>}|null [loop variable name, its enumerated literal values]
     */
    private function parseForeachLiteralArrayLoop(array $tokens, int $i, array $arrayLiteralVars): ?array
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
            return null;
        }
        $values = $arrayLiteralVars[$tokens[$j][1]] ?? null;
        if ($values === null) {
            return null;
        }

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_AS) {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
            return null; // by-reference (&$var) or key=>value form — not attempted
        }
        $loopVarName = $tokens[$j][1];

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ')') {
            return null; // "as $k => $v" or anything else after the loop variable
        }

        return [$loopVarName, $values];
    }

    /**
     * Recognizes `collect([...])->each(function ($param) { ... })` (Roots/Acorn's Laravel-style
     * fluent Collection iteration) as a bounded literal-domain loop over a plain array of string
     * literals — the same domain $foreachLoopVarValuesStack already tracks for an ordinary
     * `foreach ($arrayVar as $var) { ... }`, just spelled the fluent-collection way. Real-world
     * shape (Sage theme's own functions.php): `collect(['setup', 'filters'])->each(function
     * ($file) { locate_template("app/{$file}.php", true, true); });` — WordPress's own
     * `locate_template()` genuinely loads both `app/setup.php` and `app/filters.php`, but neither
     * had any per-file reference this parser could previously find (the tool had no code
     * recognizing `Collection`/`->each(` at all).
     *
     * Deliberately narrow, bailing rather than guessing at anything more complex: `collect(`
     * followed by exactly one argument, a plain string-literal array
     * (parseStringLiteralArrayAt — no keys, no concatenation, at least two elements); `->each(`;
     * a bare `function ($singleParam)` closure with exactly one plain parameter (no type hint, no
     * default value, no `use (...)`, no by-reference — no real-world evidence yet for any of
     * those). Returns [param name, literal values] on a match, to be pushed onto the SAME
     * $foreachLoopVarNameStack/$foreachLoopVarValuesStack an ordinary foreach uses, scoped to the
     * closure's own body the same way (see $expectingForeachLoopOpen's own push-on-'{' site) —
     * every downstream consumer of that domain (resolveForeachConcatenatedLiteral,
     * resolveInterpolatedLoopSuffixPath, the classArrayKeyLiterals cross-product) works
     * identically regardless of which loop shape actually seeded it.
     *
     * @param list<Token> $tokens
     * @return array{string,list<string>}|null
     */
    private function parseCollectEachLoop(array $tokens, int $collectNameIndex): ?array
    {
        $openParenIndex = $this->skipInsignificant($tokens, $collectNameIndex + 1);
        if (!isset($tokens[$openParenIndex]) || $tokens[$openParenIndex] !== '(') {
            return null;
        }

        // argTokensAt() only balances '('/')' (see literalPathCallArgumentTokensAt's own
        // docblock) — collect()'s own argument is a `[...]` array literal, whose internal
        // element-separating commas would otherwise be mistaken for top-level argument
        // separators, splitting one array argument into several bogus ones.
        $argTokens = $this->literalPathCallArgumentTokensAt($tokens, $collectNameIndex, 0);
        if ($argTokens === null || $this->literalPathCallArgumentTokensAt($tokens, $collectNameIndex, 1) !== null) {
            return null;
        }
        $values = $this->parseStringLiteralArrayAt($argTokens, 0);
        if ($values === null) {
            return null;
        }

        $closeParenIndex = $this->findMatchingCloseParen($tokens, $openParenIndex);
        if ($closeParenIndex === null) {
            return null;
        }

        $j = $this->skipInsignificant($tokens, $closeParenIndex + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || strtolower($tokens[$j][1]) !== 'each') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_FUNCTION) {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
            return null;
        }
        $paramName = $tokens[$j][1];
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ')') {
            return null; // a type hint, default value, or more than one parameter — not attempted
        }

        return [$paramName, $values];
    }

    /**
     * The single write path for $varLiteralAssignmentsStack, used by every population site
     * (plain `$var = 'literal';`, a ternary's two branches, a sibling equality-comparison) —
     * routing every append through one textual `[]=` site, rather than each caller appending
     * inline, is what lets PHPStan keep proving the accumulated value stays a `list<string>`;
     * multiple independent inline `[]=` sites against the same nested array confused that
     * inference even though each one is individually the same shape.
     *
     * @param list<array<string,list<string>>> $varLiteralAssignmentsStack
     */
    private function accumulateVarLiteral(array &$varLiteralAssignmentsStack, int $scopeTop, string $varName, string $literal): void
    {
        $varLiteralAssignmentsStack[$scopeTop][$varName][] = $literal;
    }

    /**
     * $i points at T_RETURN. Resolves what it hands back to a list of literals when possible:
     *  - `return 'literal';` — the literal itself.
     *  - `return $var;` — whatever's accumulated for $var in $varLiteralAssignments (the current
     *    function scope's own copy).
     *  - `return self::CONST;` / `return static::CONST;` / `return Foo::CONST;` — the resolved
     *    class constant's own literal value (real-world shape, WPForms: `General::get_slug()`
     *    does `return static::TEMPLATE_SLUG;`, late-bound per subclass — only the physical
     *    class's own value is resolved here, no descendant fan-out for `static::`, see
     *    $classNameTransformTemplates' sibling scope-limitation note for why that's deliberate).
     *  - `return apply_filters('tag', $var_or_literal, ...);` — the same, read from the filter's
     *    second argument (its "default value" position) — an extremely common WP idiom for
     *    exactly this "return a value, but let a filter override it" shape.
     * Anything else (any other expression) resolves to [] — no guessing beyond these shapes.
     * Called for both a top-level function's body and a method's — see $functionLiteralReturns'
     * own docblock for the "Class::method" keying a method uses.
     *
     * @param list<Token> $tokens
     * @param array<string,list<string>> $varLiteralAssignments
     * @param array<string,array<string,string>> $classConstants
     * @param array<string,string> $useImports
     * @return list<string>
     */
    private function resolveReturnLiterals(array $tokens, int $i, array $varLiteralAssignments, array $classConstants = [], ?string $currentClass = null, ?string $currentParent = null, string $currentNamespace = '', array $useImports = []): array
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j])) {
            return [];
        }
        $t = $tokens[$j];

        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $after = $this->skipInsignificant($tokens, $j + 1);
            return isset($tokens[$after]) && $tokens[$after] === ';' ? [$this->stripQuotes($t[1])] : [];
        }

        if ($t[0] === T_VARIABLE) {
            $after = $this->skipInsignificant($tokens, $j + 1);
            return isset($tokens[$after]) && $tokens[$after] === ';' ? ($varLiteralAssignments[$t[1]] ?? []) : [];
        }

        if (in_array($t[0], self::CLASS_NAME_TOKENS, true) || $t[0] === T_STATIC) {
            $doubleColonIndex = $this->peekNextMeaningfulIndex($tokens, $j);
            if ($doubleColonIndex !== null && is_array($tokens[$doubleColonIndex]) && $tokens[$doubleColonIndex][0] === T_DOUBLE_COLON) {
                $constIndex = $this->peekNextMeaningfulIndex($tokens, $doubleColonIndex);
                if ($constIndex !== null && is_array($tokens[$constIndex]) && $tokens[$constIndex][0] === T_STRING) {
                    $after = $this->peekNextMeaningfulIndex($tokens, $constIndex);
                    if ($after !== null && $tokens[$after] === ';') {
                        $value = $this->resolveScopedClassConstant($t, $tokens[$constIndex][1], $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports);
                        if ($value !== null) {
                            return [$value];
                        }
                    }
                }
            }
        }

        if ($t[0] === T_STRING && $t[1] === 'apply_filters' && $this->peekNextMeaningful($tokens, $j) === '(') {
            $argTokens = $this->argTokensAt($tokens, $j, 1);
            if ($argTokens !== null && count($argTokens) === 1) {
                $arg = $argTokens[0];
                if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
                    return [$this->stripQuotes($arg[1])];
                }
                if (is_array($arg) && $arg[0] === T_VARIABLE) {
                    return $varLiteralAssignments[$arg[1]] ?? [];
                }
            }
        }

        return [];
    }

    /**
     * $i points at T_RETURN. Resolves what it hands back to a *list* of literals (an array,
     * not a scalar) when possible:
     *  - `return $var;` — $var's own tracked flat literal-array contents (see
     *    $anyArrayLiteralVars/parseAnyStringLiteralArray).
     *  - `return apply_filters('tag', $var, ...);` — same, read from the filter's own
     *    documented "value" position.
     * Anything else resolves to [] — no guessing beyond these two shapes. Mirrors
     * resolveReturnLiterals()'s own two shapes, just array- instead of scalar-valued; doesn't
     * also attempt a bare literal return (`return array(...);` directly with no intermediate
     * variable) — no evidence for that shape yet.
     *
     * @param list<Token> $tokens
     * @param array<string,list<string>> $anyArrayLiteralVars
     * @return list<string>
     */
    private function resolveReturnArrayLiterals(array $tokens, int $i, array $anyArrayLiteralVars): array
    {
        $j = $this->skipInsignificant($tokens, $i + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j])) {
            return [];
        }
        $t = $tokens[$j];

        if ($t[0] === T_VARIABLE) {
            $after = $this->skipInsignificant($tokens, $j + 1);
            return isset($tokens[$after]) && $tokens[$after] === ';' ? ($anyArrayLiteralVars[$t[1]] ?? []) : [];
        }

        if (in_array($t[0], self::CLASS_NAME_TOKENS, true) && $this->shortClassName($t[1]) === 'apply_filters' && $this->peekNextMeaningful($tokens, $j) === '(') {
            $argTokens = $this->argTokensAt($tokens, $j, 1);
            if ($argTokens !== null && count($argTokens) === 1 && is_array($argTokens[0]) && $argTokens[0][0] === T_VARIABLE) {
                return $anyArrayLiteralVars[$argTokens[0][1]] ?? [];
            }
        }

        return [];
    }

    /**
     * $quoteIndex points at (what might be) the raw `"` opening a double-quoted string.
     * Recognizes `"literal$var"` starting exactly there — a literal text segment immediately
     * followed by a bare interpolated variable and the closing quote, nothing else inside the
     * string. Interpolation syntax only ever substitutes a bare variable this simply (a function
     * call needs the `{$...}` complex form, out of scope here — no evidence for it), so unlike
     * literalConcatVarAt()'s use of the general transform-chain resolver, matching the variable
     * token directly is sufficient. Returns [literal, variable name], or null for anything else.
     *
     * @param list<Token> $tokens
     * @return array{string, string}|null
     */
    private function interpolatedPrefixVarAt(array $tokens, int $quoteIndex): ?array
    {
        if (!isset($tokens[$quoteIndex]) || $tokens[$quoteIndex] !== '"') {
            return null;
        }
        $textIndex = $quoteIndex + 1;
        if (!isset($tokens[$textIndex]) || !is_array($tokens[$textIndex]) || $tokens[$textIndex][0] !== T_ENCAPSED_AND_WHITESPACE) {
            return null;
        }
        $varIndex = $textIndex + 1;
        if (!isset($tokens[$varIndex]) || !is_array($tokens[$varIndex]) || $tokens[$varIndex][0] !== T_VARIABLE) {
            return null;
        }
        $closeQuoteIndex = $varIndex + 1;
        if (!isset($tokens[$closeQuoteIndex]) || $tokens[$closeQuoteIndex] !== '"') {
            return null;
        }
        $afterIndex = $this->peekNextMeaningfulIndex($tokens, $closeQuoteIndex);
        if ($afterIndex === null || $tokens[$afterIndex] !== ';') {
            return null;
        }

        return [$this->stripQuotes($tokens[$textIndex][1]), $tokens[$varIndex][1]];
    }

    /**
     * $quoteIndex points at (what might be) the raw `"` opening a double-quoted string.
     * Recognizes `"literal{$var}literal"` — a literal text segment, a curly-brace complex
     * interpolation of a bare variable, then an optional trailing literal text segment before
     * the closing quote. Real-world shape (WordPress SEO):
     * `"Yoast\WP\SEO\Presenters\\{$presenter}_Presenter"` — the curly braces are syntactically
     * required here specifically *because* of the suffix (a bare `$presenter_Presenter` would
     * tokenize as one larger variable name instead of `$presenter` followed by literal text),
     * unlike interpolatedPrefixVarAt()'s own bare-`$var`-no-suffix shape just above. Returns
     * [prefix, variable name, suffix] (suffix `''` when the closing quote follows `}` directly),
     * or null for anything else — wherever this literal appears, not gated to a particular
     * statement (see literalConcatVarAt()'s own docblock for why).
     *
     * @param list<Token> $tokens
     * @return array{string, string, string}|null
     */
    private function interpolatedPrefixCurlyVarSuffixAt(array $tokens, int $quoteIndex): ?array
    {
        if (!isset($tokens[$quoteIndex]) || $tokens[$quoteIndex] !== '"') {
            return null;
        }
        $textIndex = $quoteIndex + 1;
        if (!isset($tokens[$textIndex]) || !is_array($tokens[$textIndex]) || $tokens[$textIndex][0] !== T_ENCAPSED_AND_WHITESPACE) {
            return null;
        }
        $curlyIndex = $textIndex + 1;
        if (!isset($tokens[$curlyIndex]) || !is_array($tokens[$curlyIndex]) || $tokens[$curlyIndex][0] !== T_CURLY_OPEN) {
            return null;
        }
        $varIndex = $curlyIndex + 1;
        if (!isset($tokens[$varIndex]) || !is_array($tokens[$varIndex]) || $tokens[$varIndex][0] !== T_VARIABLE) {
            return null;
        }
        $closeCurlyIndex = $varIndex + 1;
        if (!isset($tokens[$closeCurlyIndex]) || $tokens[$closeCurlyIndex] !== '}') {
            return null;
        }
        $afterCurlyIndex = $closeCurlyIndex + 1;
        if (!isset($tokens[$afterCurlyIndex])) {
            return null;
        }
        if ($tokens[$afterCurlyIndex] === '"') {
            return [$tokens[$textIndex][1], $tokens[$varIndex][1], ''];
        }
        if (is_array($tokens[$afterCurlyIndex]) && $tokens[$afterCurlyIndex][0] === T_ENCAPSED_AND_WHITESPACE) {
            $closeQuoteIndex = $afterCurlyIndex + 1;
            if (!isset($tokens[$closeQuoteIndex]) || $tokens[$closeQuoteIndex] !== '"') {
                return null;
            }
            return [$tokens[$textIndex][1], $tokens[$varIndex][1], $tokens[$afterCurlyIndex][1]];
        }

        return null;
    }

    /**
     * $i points at T_RETURN. Recognizes the narrow shape `return <anything> . $param .
     * 'suffix';` — the trailing `. $param . 'literal-suffix'` immediately before the statement's
     * own terminating `;`, with everything before that pair left completely unresolved/ignored,
     * the same "keep only the segment directly adjacent to the tracked value" stance
     * resolveInterpolatedLoopSuffixPath already takes for interpolated strings, just for plain
     * concatenation instead. Real-world shape (wp-nested-pages' Helpers::view()): `return
     * dirname(__FILE__) . '/Views/' . $file . '.php';` — the unresolvable `dirname(__FILE__)`
     * prefix is never looked at; only the "$file . '.php'" tail matters, since FileAnalyzer's own
     * referenced-index matches by basename anyway. Doesn't verify the variable is actually the
     * function's own declared parameter (a small "coarse net" concession, same trade-off the
     * rest of this parser makes elsewhere) — a small helper's own return statement is virtually
     * always built from its own parameter in real code. Returns null for any other shape — no
     * trailing literal, no variable directly before it, or the terminating `;` can't be found (an
     * unbalanced/malformed statement).
     *
     * @param list<Token> $tokens
     */
    private function resolveReturnParamSuffixTemplate(array $tokens, int $i): ?string
    {
        $depth = 0;
        $j = $i + 1;
        $semicolonIndex = null;
        while (isset($tokens[$j])) {
            $t = $tokens[$j];
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                if ($depth === 0) {
                    return null; // unbalanced — bail rather than guess
                }
                $depth--;
            } elseif ($depth === 0 && $t === ';') {
                $semicolonIndex = $j;
                break;
            }
            $j++;
        }
        if ($semicolonIndex === null) {
            return null;
        }

        $suffixIndex = $this->peekPrevMeaningfulIndex($tokens, $semicolonIndex);
        if ($suffixIndex === null || !is_array($tokens[$suffixIndex]) || $tokens[$suffixIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $dotIndex = $this->peekPrevMeaningfulIndex($tokens, $suffixIndex);
        if ($dotIndex === null || $tokens[$dotIndex] !== '.') {
            return null;
        }
        $varIndex = $this->peekPrevMeaningfulIndex($tokens, $dotIndex);
        if ($varIndex === null || !is_array($tokens[$varIndex]) || $tokens[$varIndex][0] !== T_VARIABLE) {
            return null;
        }

        return $this->stripQuotes($tokens[$suffixIndex][1]);
    }

    /**
     * $equalsIndex points at the `=` of `$var = 'literal';`. Returns the literal only when the
     * entire RHS is that one string token — nothing else, no concatenation — bailing (null)
     * otherwise; a concatenated/dynamic RHS carries no single resolvable literal worth
     * accumulating.
     *
     * @param list<Token> $tokens
     */
    private function singleStringLiteralRhs(array $tokens, int $equalsIndex): ?string
    {
        $j = $this->skipInsignificant($tokens, $equalsIndex + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $literal = $this->stripQuotes($tokens[$j][1]);

        $after = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$after]) || $tokens[$after] !== ';') {
            return null;
        }

        return $literal;
    }

    /**
     * $equalsIndex points at the `=` of `$var = <chain>;`, where `<chain>` is zero or more
     * nested `str_replace('lit', 'lit', INNER)` / `ucfirst(INNER)` / `ucwords(INNER)` calls
     * wrapped around either a bare variable or a variable already tracked in
     * $transformChainVars (letting a chain span several statements — see its own declaration
     * comment). Generalizes what was originally one hardcoded idiom (WPForms'
     * `str_replace(' ', '', ucwords(str_replace('_', ' ', $x)))`) after two more real-world
     * shapes confirmed the same general pattern recurs with different transform combinations:
     * wp-nested-pages' `Events::setHandlers()` does `$class = str_replace('admin_post_np', '',
     * $action); $class = ucfirst(str_replace('wp_ajax_np', '', $class));` (two chained
     * statements, reassigning the same variable); Jetpack's tiled-gallery module does a bare
     * `ucfirst($this->atts['type'])` with no `str_replace` at all. Requires the outer call to be
     * the entire RHS (nothing wrapping it further) and every `str_replace()`'s first two
     * arguments to be plain string literals — a computed search/replace pair is not attempted.
     * Returns [ultimate source variable name, transform steps in application order — outermost
     * call last], or null for any other shape (an unrecognized function, non-literal
     * search/replace arguments, or bottoming out in anything but a bare variable).
     *
     * @param list<Token> $tokens
     * @param array<string,array{string,list<array{string,list<string>}>}> $transformChainVars
     * @return array{string,list<array{string,list<string>}>}|null
     */
    private function resolveTransformChainRhs(array $tokens, int $equalsIndex, array $transformChainVars): ?array
    {
        $outerIndex = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($outerIndex === null) {
            return null;
        }
        $exprTokens = $this->literalPathStatementTokens($tokens, $outerIndex);
        if ($exprTokens === []) {
            return null;
        }
        return $this->resolveTransformChainExpr($exprTokens, $transformChainVars);
    }

    /**
     * True when `$value`'s own RHS ends in `. $value` and the expression contains a string
     * literal somewhere before that — the shape literalConcatVarAt() matches later in the same
     * forward token scan (real-world, Elementor: `$class_name = __NAMESPACE__ . '\Widget_' .
     * $class_name;`). Doesn't try to resolve the RHS itself (that's literalConcatVarAt()'s job,
     * once $i reaches the literal token); only decides whether the *previous* statement's
     * $transformChainVars[$value] entry is still needed and must survive this one.
     *
     * @param list<Token> $tokens
     */
    private function rhsIsSelfReferentialLiteralConcat(array $tokens, int $equalsIndex, string $value): bool
    {
        $exprStart = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($exprStart === null) {
            return false;
        }
        $exprTokens = $this->literalPathStatementTokens($tokens, $exprStart);
        $n = count($exprTokens);
        if (
            $n < 3
            || $exprTokens[$n - 2] !== '.'
            || !is_array($exprTokens[$n - 1])
            || $exprTokens[$n - 1][0] !== T_VARIABLE
            || $exprTokens[$n - 1][1] !== $value
        ) {
            return false;
        }
        foreach ($exprTokens as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<Token> $tokens
     * @param array<string,array{string,list<array{string,list<string>}>}> $transformChainVars
     * @param bool $allowOpaqueBase When true, an innermost expression that's neither a bare
     *   variable nor a recognized transform call still counts as a (zero-step) base rather than
     *   failing the whole match — see literalConcatVarAt()'s own docblock for why: Jetpack's
     *   tiled-gallery module applies the transform inline (`ucfirst($this->atts['type'])`, no
     *   separate assignment first), and the innermost expression there is a property-array-key
     *   read this parser has no way to identify further. The two existing callers
     *   (resolveTransformChainRhs(), and this function's own recursive calls when the flag isn't
     *   set) keep requiring a bare variable, since they need to identify *which* variable to key
     *   $transformChainVars by for a chain spanning several statements.
     * @return array{string,list<array{string,list<string>}>}|null
     */
    private function resolveTransformChainExpr(array $tokens, array $transformChainVars, bool $allowOpaqueBase = false): ?array
    {
        if (count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_VARIABLE) {
            $varName = $tokens[0][1];
            return $transformChainVars[$varName] ?? [$varName, []];
        }

        if (
            count($tokens) < 4
            || !is_array($tokens[0]) || !in_array($tokens[0][0], self::CLASS_NAME_TOKENS, true)
            || $tokens[1] !== '(' || $tokens[count($tokens) - 1] !== ')'
        ) {
            return $allowOpaqueBase ? ['', []] : null;
        }
        $funcName = $this->shortClassName($tokens[0][1]);
        $args = $this->splitTopLevelCommaArgs(array_slice($tokens, 2, -1));

        // `sanitize_title( $x )` — real-world shape (WooCommerce): `WC_Admin_Reports::get_report()`
        // does `$name = sanitize_title( str_replace( '_', '-', $name ) );`. Every domain value
        // this parser ever sees here is already a clean slug (a report registry's own array key,
        // e.g. 'sales_by_date'), with nothing for sanitize_title() to actually change once it's
        // hyphenated — a transparent (zero-step) pass-through, not a recorded step, same no-op-
        // for-matching-purposes trade-off as basename()/sanitize_file_name() elsewhere in this
        // parser. Left unrecorded (rather than added as its own step type) since it contributes
        // no character transformation to replay.
        if ($funcName === 'sanitize_title' && count($args) === 1) {
            return $this->resolveTransformChainExpr($args[0], $transformChainVars, $allowOpaqueBase);
        }

        if (($funcName === 'ucfirst' || $funcName === 'ucwords') && count($args) === 1) {
            $inner = $this->resolveTransformChainExpr($args[0], $transformChainVars, $allowOpaqueBase);
            if ($inner === null) {
                return null;
            }
            [$sourceVar, $steps] = $inner;
            $steps[] = [$funcName, []];
            return [$sourceVar, $steps];
        }

        if ($funcName === 'str_replace' && count($args) === 3) {
            if (count($args[0]) !== 1 || !is_array($args[0][0]) || $args[0][0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return $allowOpaqueBase ? ['', []] : null;
            }
            if (count($args[1]) !== 1 || !is_array($args[1][0]) || $args[1][0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return $allowOpaqueBase ? ['', []] : null;
            }
            $inner = $this->resolveTransformChainExpr($args[2], $transformChainVars, $allowOpaqueBase);
            if ($inner === null) {
                return null;
            }
            [$sourceVar, $steps] = $inner;
            $steps[] = ['str_replace', [$this->stripQuotes($args[0][0][1]), $this->stripQuotes($args[1][0][1])]];
            return [$sourceVar, $steps];
        }

        return $allowOpaqueBase ? ['', []] : null;
    }

    /**
     * Splits a flat, already-whitespace-stripped token list on its own top-level commas
     * (tracking `()`/`[]`/`{}` nesting so a nested call's own arguments aren't split too).
     * Mirrors argTokensAt()'s depth tracking, but over a token list whose outer call's name and
     * parens have already been stripped by the caller, rather than scanning forward from a call-
     * name index — resolveTransformChainExpr() needs this to recurse into an arbitrary inner
     * expression, not just read one argument at a fixed position.
     *
     * @param list<Token> $tokens
     * @return list<list<Token>>
     */
    private function splitTopLevelCommaArgs(array $tokens): array
    {
        $args = [];
        $current = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
            }
            if ($depth === 0 && $token === ',') {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }
        if ($current !== [] || $args !== []) {
            $args[] = $current;
        }
        return $args;
    }

    /**
     * $literalIndex points at (what might be) a T_CONSTANT_ENCAPSED_STRING token. Recognizes
     * `'literal' . <expr>;` starting exactly there — one string literal concatenated with
     * exactly one expression, literal first, nothing else before the statement's own terminating
     * `;`. `<expr>` is resolved via resolveTransformChainExpr() with `$allowOpaqueBase: true`, so
     * it matches both a bare variable already holding a transform chain (real-world shape,
     * wp-nested-pages: `'NestedPages\Form\Listeners\\' . $class;`, where `$class` was built
     * across two earlier statements) and a transform applied inline with no separate assignment
     * at all (real-world shape, Jetpack tiled-gallery: `'Jetpack_Tiled_Gallery_Layout_' .
     * ucfirst( $this->atts['type'] );` — the innermost `$this->atts['type']` is a property-array-
     * key read this parser has no way to identify further, hence the opaque base). Deliberately
     * doesn't care what precedes the literal or what the whole expression is ultimately assigned
     * to (called at the literal's own token position, not anchored to any particular `=`) —
     * wp-nested-pages' own real assignment target is `$this->handlers[$key]->class`, an lvalue
     * far more complex than a bare `$var =` this parser otherwise attempts to track. Returns
     * [literal, transform chain (possibly zero steps — the caller must still check for that)],
     * or null if nothing at all matches this shape.
     *
     * @param list<Token> $tokens
     * @param array<string,array{string,list<array{string,list<string>}>}> $transformChainVars
     * @return array{string,array{string,list<array{string,list<string>}>}}|null
     */
    private function literalConcatVarAt(array $tokens, int $literalIndex, array $transformChainVars): ?array
    {
        if (!is_array($tokens[$literalIndex]) || $tokens[$literalIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $dotIndex = $this->peekNextMeaningfulIndex($tokens, $literalIndex);
        if ($dotIndex === null || $tokens[$dotIndex] !== '.') {
            return null;
        }
        $exprStart = $this->peekNextMeaningfulIndex($tokens, $dotIndex);
        if ($exprStart === null) {
            return null;
        }
        $exprTokens = $this->literalPathStatementTokens($tokens, $exprStart);
        if ($exprTokens === []) {
            return null;
        }
        $chain = $this->resolveTransformChainExpr($exprTokens, $transformChainVars, true);
        if ($chain === null) {
            return null;
        }

        return [$this->stripQuotes($tokens[$literalIndex][1]), $chain];
    }

    /**
     * $equalsIndex points at the `=` of `$var = ( <cond> ) ? 'lit1' : 'lit2';`. Requires the
     * condition to be wrapped in explicit parens (matching the one real-world shape this has
     * evidence for — wp-nested-pages' `( $this->post->type !== 'np-redirect' ) ? 'partials/row' :
     * 'partials/row-link'`) rather than trying to find the ternary's top-level `?` in an
     * unparenthesized condition, which would need real operator-precedence handling to do safely.
     * Bails (null) for anything else — a non-literal branch, a nested ternary, no parens.
     *
     * @param list<Token> $tokens
     * @return array{string, string}|null [true-branch literal, false-branch literal]
     */
    private function parseTernaryLiteralDomain(array $tokens, int $equalsIndex): ?array
    {
        $j = $this->skipInsignificant($tokens, $equalsIndex + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $closeParenIndex = $this->findMatchingCloseParen($tokens, $j);
        if ($closeParenIndex === null) {
            return null;
        }

        $questionIndex = $this->skipInsignificant($tokens, $closeParenIndex + 1);
        if (!isset($tokens[$questionIndex]) || $tokens[$questionIndex] !== '?') {
            return null;
        }
        $trueIndex = $this->skipInsignificant($tokens, $questionIndex + 1);
        if (!isset($tokens[$trueIndex]) || !is_array($tokens[$trueIndex]) || $tokens[$trueIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $colonIndex = $this->skipInsignificant($tokens, $trueIndex + 1);
        if (!isset($tokens[$colonIndex]) || $tokens[$colonIndex] !== ':') {
            return null;
        }
        $falseIndex = $this->skipInsignificant($tokens, $colonIndex + 1);
        if (!isset($tokens[$falseIndex]) || !is_array($tokens[$falseIndex]) || $tokens[$falseIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $afterIndex = $this->skipInsignificant($tokens, $falseIndex + 1);
        if (!isset($tokens[$afterIndex]) || $tokens[$afterIndex] !== ';') {
            return null;
        }

        return [$this->stripQuotes($tokens[$trueIndex][1]), $this->stripQuotes($tokens[$falseIndex][1])];
    }

    /**
     * $equalsIndex points at the `=` of `$var = helper_fn();`. Returns the called function's
     * name only when the entire RHS is that one bare, zero-argument call — nothing else, no
     * chained call, no arguments, no concatenation — bailing (null) otherwise.
     *
     * @param list<Token> $tokens
     */
    private function bareZeroArgFunctionCallRhs(array $tokens, int $equalsIndex): ?string
    {
        $j = $this->skipInsignificant($tokens, $equalsIndex + 1);
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            return null;
        }
        $name = $tokens[$j][1];

        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
            return null;
        }
        $j = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$j]) || $tokens[$j] !== ')') {
            return null;
        }

        $after = $this->skipInsignificant($tokens, $j + 1);
        if (!isset($tokens[$after]) || $tokens[$after] !== ';') {
            return null;
        }

        return $name;
    }

    /**
     * $equalsIndex points at the `=` of `$var = array(...)` / `$var = [...]`. Same shape as
     * parseAnyStringLiteralArray just below, plus one extra gate to avoid matching an unrelated
     * short-string/single-word array by coincidence: at least one element must contain a "/" —
     * something a genuine bulk-include path list will always have and an arbitrary config array
     * often won't. Feeds the file-path-specific consumers ($phpPathStrings); a plain array of
     * non-path string literals (dispatched some other way) is invisible here by design — see
     * parseAnyStringLiteralArray for that case.
     *
     * @param list<Token> $tokens
     * @return list<string>|null
     */
    private function parseStringLiteralArray(array $tokens, int $equalsIndex): ?array
    {
        $values = $this->parseAnyStringLiteralArray($tokens, $equalsIndex);
        if ($values === null) {
            return null;
        }
        foreach ($values as $v) {
            if (str_contains($v, '/')) {
                return $values;
            }
        }
        return null;
    }

    /**
     * $equalsIndex points at the `=` of `$var = array(...)` / `$var = [...]`. Returns the
     * elements in order when every one is a plain string literal (no keys, no concatenation, no
     * nested arrays/calls/variables) — bailing (null) the moment anything else appears, same
     * "bail rather than guess" stance parseUseImports takes on group-use syntax. Also bails
     * unless there are at least two elements, to avoid matching an unrelated single-element array
     * by coincidence.
     *
     * @param list<Token> $tokens
     * @return list<string>|null
     */
    private function parseAnyStringLiteralArray(array $tokens, int $equalsIndex): ?array
    {
        return $this->parseStringLiteralArrayAt($tokens, $this->skipInsignificant($tokens, $equalsIndex + 1));
    }

    /**
     * Same shape/bail rules as parseAnyStringLiteralArray(), factored out so a caller that
     * already has the array's own starting token index (rather than the `=` before it) can reuse
     * the exact same parsing — see parseCollectEachLoop()'s own use (`collect([...])`'s bare call
     * argument, no `=` involved at all).
     *
     * @param list<Token> $tokens
     * @return list<string>|null
     */
    private function parseStringLiteralArrayAt(array $tokens, int $j): ?array
    {
        if (!isset($tokens[$j])) {
            return null;
        }

        if (is_array($tokens[$j]) && $tokens[$j][0] === T_ARRAY) {
            $j = $this->skipInsignificant($tokens, $j + 1);
            if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
                return null;
            }
            $close = ')';
        } elseif ($tokens[$j] === '[') {
            $close = ']';
        } else {
            return null;
        }

        $j++;
        $values = [];
        $expectingValue = true;

        while (isset($tokens[$j])) {
            $j = $this->skipInsignificant($tokens, $j);
            if (!isset($tokens[$j])) {
                return null;
            }
            $t = $tokens[$j];

            if ($t === $close) {
                return count($values) < 2 ? null : $values;
            }

            if (!$expectingValue) {
                if ($t === ',') {
                    $expectingValue = true;
                    $j++;
                    continue;
                }
                return null;
            }

            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }

            $afterString = $this->skipInsignificant($tokens, $j + 1);
            if (isset($tokens[$afterString]) && is_array($tokens[$afterString]) && $tokens[$afterString][0] === T_DOUBLE_ARROW) {
                return null; // 'key' => 'value' — keyed arrays aren't the shape this supports
            }

            $values[] = $this->stripQuotes($t[1]);
            $j = $afterString;
            $expectingValue = false;
        }

        return null;
    }

    /** @param list<Token> $tokens */
    private function skipInsignificant(array $tokens, int $j): int
    {
        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        return $j;
    }

    /** @param list<Token> $tokens */
    private function peekNextMeaningful(array $tokens, int $i): string
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                $j++;
                continue;
            }
            return is_string($t) ? $t : $t[1];
        }
        return '';
    }

    /** @param list<Token> $tokens */
    private function peekNextMeaningfulIndex(array $tokens, int $i): ?int
    {
        $j = $i + 1;
        while (isset($tokens[$j])) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
                continue;
            }
            return $j;
        }
        return null;
    }

    /**
     * Given the index of a receiver token ($this, self, parent, static, or a class name),
     * checks whether it's immediately followed by `::` or `->`, an identifier, and a call `(`
     * — i.e. an actual method call, not e.g. Foo::CONST, Foo::class, Foo::$prop, or $this->prop.
     * Also guards against non-call uses of the same tokens, like the `static` *modifier* in
     * `public static function foo()` — that's `static` immediately followed by `function`, not
     * by `::`, so it must not be mistaken for `static::foo(`.
     *
     * @return array{string, int}|null  [method name, its token index] or null if this isn't a call.
     * @param list<Token> $tokens
     */
    private function findScopedCallTarget(array $tokens, int $receiverIndex): ?array
    {
        $sepIndex = $this->peekNextMeaningfulIndex($tokens, $receiverIndex);
        if ($sepIndex === null) {
            return null;
        }
        $sepToken = $tokens[$sepIndex];
        $sepValue = is_string($sepToken) ? $sepToken : $sepToken[1];
        if ($sepValue !== '::' && $sepValue !== '->') {
            return null;
        }

        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $sepIndex);
        if ($nameIndex === null) {
            return null;
        }
        $nameToken = $tokens[$nameIndex];
        if (!is_array($nameToken) || $nameToken[0] !== T_STRING) {
            return null;
        }

        $afterIndex = $this->peekNextMeaningfulIndex($tokens, $nameIndex);
        if ($afterIndex === null || $tokens[$afterIndex] !== '(') {
            return null;
        }

        return [$nameToken[1], $nameIndex];
    }

    /**
     * $receiverIndex points at `$this` (the only receiver property-type tracking supports —
     * there's no reliable way to know an arbitrary variable's or `self`/parent`'s property
     * types without a much bigger analysis). Returns the property name and its token index for
     * `$this->propName`, regardless of what follows it — an assignment (`= ...`), a further
     * chained access (`->method()`), or neither. Same shape as findScopedCallTarget() but
     * without requiring `(` right after the name, since the caller needs to branch on what
     * comes next rather than assume it's a call.
     *
     * @return array{string, int}|null  [property name, its token index] or null if this isn't
     *   `$this->identifier` at all.
     * @param list<Token> $tokens
     */
    private function propertyAccessTarget(array $tokens, int $receiverIndex): ?array
    {
        $sepIndex = $this->peekNextMeaningfulIndex($tokens, $receiverIndex);
        if ($sepIndex === null) {
            return null;
        }
        $sepToken = $tokens[$sepIndex];
        $sepValue = is_string($sepToken) ? $sepToken : $sepToken[1];
        if ($sepValue !== '->') {
            return null;
        }

        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $sepIndex);
        if ($nameIndex === null) {
            return null;
        }
        $nameToken = $tokens[$nameIndex];
        if (!is_array($nameToken) || $nameToken[0] !== T_STRING) {
            return null;
        }

        return [$nameToken[1], $nameIndex];
    }

    /**
     * Given the index of an assignment's `=` token, checks whether the right-hand side is
     * exactly a bare variable — `$this->prop = $controller;` — whose class is already known via
     * the current scope's $varTypes (most commonly a type-hinted constructor parameter tracked
     * by collectParamTypeHint, the constructor-injection pattern: `public function
     * __construct(Controller $controller) { $this->controller = $controller; }`, where the
     * property isn't `new`'d directly or auto-promoted). Requires the RHS to be nothing more than
     * `$var;` — anything else (a method call, a further property access, a binary expression)
     * bails rather than guessing, same stance as assignedNewClassName's own sibling cases.
     *
     * @param list<Token> $tokens
     * @param array<string,string> $varTypes
     */
    private function assignedVariableClassName(array $tokens, int $equalsIndex, array $varTypes): ?string
    {
        $j = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
            return null;
        }
        $varName = $tokens[$j][1];

        $after = $this->peekNextMeaningfulIndex($tokens, $j);
        if ($after === null || $tokens[$after] !== ';') {
            return null;
        }

        return $varTypes[$varName] ?? null;
    }

    /**
     * Resolves a receiver identifier's raw text ("self", "parent", or a literal class name) to
     * the FQCN it actually refers to. "self" and "parent" depend on where this code physically
     * is — $classNameStack/$classParentStack already hold FQCNs (see the class-open push sites)
     * — anything else is resolved via resolveFqcn() against the current namespace/use-imports.
     *
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param array<string,string> $useImports
     */
    private function resolveClassNameToken(string $name, array $classNameStack, array $classParentStack, string $currentNamespace, array $useImports): ?string
    {
        return match ($name) {
            'self' => empty($classNameStack) ? null : end($classNameStack),
            'parent' => empty($classParentStack) ? null : end($classParentStack),
            default => $this->resolveFqcn($name, $currentNamespace, $useImports),
        };
    }

    /**
     * Given the index of an assignment's `=` token, checks whether the right-hand side is
     * `new ClassName(...)` (or `new self()`/`new parent()`/`new static()`) and resolves it to a
     * concrete class name. Returns null for anything else — `new class {}` (anonymous),
     * `new $dynamicClass()`, or a non-`new` expression entirely.
     *
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function assignedNewClassName(array $tokens, int $equalsIndex, array $classNameStack, array $classParentStack, string $currentNamespace, array $useImports): ?string
    {
        $newIndex = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($newIndex === null || !is_array($tokens[$newIndex]) || $tokens[$newIndex][0] !== T_NEW) {
            return null;
        }

        return $this->resolveNewExpressionClassNameAt($tokens, $newIndex, $classNameStack, $classParentStack, $currentNamespace, $useImports);
    }

    /**
     * $newIndex points at the `new` keyword itself (not, unlike assignedNewClassName(), an
     * assignment's `=`) — shared by that function and by the literal-path `new self(...)`/
     * `new static(...)` wrapper-forwarding capture (Wordfence's `wfView::create()` does `return
     * new self( $view, $data );`, no assignment at all).
     *
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function resolveNewExpressionClassNameAt(array $tokens, int $newIndex, array $classNameStack, array $classParentStack, string $currentNamespace, array $useImports): ?string
    {
        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $newIndex);
        if ($nameIndex === null) {
            return null;
        }
        $nameToken = $tokens[$nameIndex];

        if (is_array($nameToken) && $nameToken[0] === T_STATIC) {
            return empty($classNameStack) ? null : end($classNameStack);
        }

        if (!is_array($nameToken) || !in_array($nameToken[0], self::CLASS_NAME_TOKENS, true)) {
            return null;
        }

        return $this->resolveClassNameToken($nameToken[1], $classNameStack, $classParentStack, $currentNamespace, $useImports);
    }

    /**
     * `$var = ClassName::cls()` / `::instance()` / `::getInstance()` / `::get_instance()` — a
     * common WP-plugin static-singleton-factory convention: the factory method (declared once on
     * a shared base class) uses late static binding (`new static()`/`get_called_class()`), so
     * calling it on a specific subclass always returns an instance of that subclass, regardless
     * of the factory method's own declared or inferred return type — the same reasoning
     * `PendingReturnTypedCall` can't apply here since these methods typically have no declared
     * return type to resolve against. Curated method-name list, same spirit as
     * TEMPLATE_FUNCS/BASE_CLASS_CONTRACT_METHODS — a real, reusable convention, not a
     * plugin-specific hack. Confirmed in the wild: LiteSpeed Cache's `Base::cls()`
     * (`get_called_class()`-based, e.g. `$this->cloud = Cloud::cls();`) and Jetpack's
     * `WPCOM_JSON_API_Links::getInstance()`.
     *
     * @param list<Token> $tokens
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param array<string,string> $useImports
     */
    private function assignedStaticFactoryClassName(array $tokens, int $equalsIndex, array $classNameStack, array $classParentStack, string $currentNamespace, array $useImports): ?string
    {
        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $equalsIndex);
        if ($nameIndex === null || !is_array($tokens[$nameIndex])) {
            return null;
        }
        $nameToken = $tokens[$nameIndex];
        if (!in_array($nameToken[0], self::CLASS_NAME_TOKENS, true) && $nameToken[0] !== T_STATIC) {
            return null;
        }

        $doubleColonIndex = $this->peekNextMeaningfulIndex($tokens, $nameIndex);
        if ($doubleColonIndex === null || $this->peekNextMeaningful($tokens, $nameIndex) !== '::') {
            return null;
        }

        $methodIndex = $this->peekNextMeaningfulIndex($tokens, $doubleColonIndex);
        if (
            $methodIndex === null || !is_array($tokens[$methodIndex]) || $tokens[$methodIndex][0] !== T_STRING
            || !in_array($tokens[$methodIndex][1], self::STATIC_FACTORY_METHOD_NAMES, true)
        ) {
            return null;
        }

        $openParenIndex = $this->peekNextMeaningfulIndex($tokens, $methodIndex);
        if ($openParenIndex === null || $tokens[$openParenIndex] !== '(') {
            return null;
        }

        $name = $nameToken[0] === T_STATIC ? 'static' : $nameToken[1];

        return $this->resolveClassNameToken($name, $classNameStack, $classParentStack, $currentNamespace, $useImports);
    }

    /**
     * `( new ClassName(...) )->method(...)` — the inline, parenthesized-`new` counterpart to
     * `$var = new ClassName(); $var->method();` (assignedNewClassName above). Requires `new` to
     * be immediately preceded by `(` — the wrapping group PHP has required for this exact
     * chained-call shape since 5.4 — which also matches the one confirmed real-world example
     * this was built from (Elementor's `( new Export() )->register_route(...)`); a bare
     * `new Foo()->bar()` without the wrapping parens is a separate, rarer shape deliberately not
     * handled here.
     *
     * @return array{string, string, int}|null [resolved receiver FQCN, method name, its token index]
     * @param list<Token> $tokens
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param array<string,string> $useImports
     */
    private function findInlineNewChainedCallTarget(array $tokens, int $newIndex, array $classNameStack, array $classParentStack, string $currentNamespace, array $useImports): ?array
    {
        $openParenIndex = $this->peekPrevMeaningfulIndex($tokens, $newIndex);
        if ($openParenIndex === null || $tokens[$openParenIndex] !== '(') {
            return null;
        }

        $nameIndex = $this->peekNextMeaningfulIndex($tokens, $newIndex);
        if ($nameIndex === null) {
            return null;
        }
        $nameToken = $tokens[$nameIndex];

        if (is_array($nameToken) && $nameToken[0] === T_STATIC) {
            $receiverFqcn = empty($classNameStack) ? null : end($classNameStack);
        } elseif (is_array($nameToken) && in_array($nameToken[0], self::CLASS_NAME_TOKENS, true)) {
            $receiverFqcn = $this->resolveClassNameToken($nameToken[1], $classNameStack, $classParentStack, $currentNamespace, $useImports);
        } else {
            return null; // `new class {}` (anonymous) / `new $dynamicClass()` — not resolvable
        }
        if ($receiverFqcn === null) {
            return null;
        }

        $afterNameIndex = $this->peekNextMeaningfulIndex($tokens, $nameIndex);
        $closeCtorParenIndex = $nameIndex;
        if ($afterNameIndex !== null && $tokens[$afterNameIndex] === '(') {
            $closeCtorParenIndex = $this->findMatchingCloseParen($tokens, $afterNameIndex);
            if ($closeCtorParenIndex === null) {
                return null;
            }
        }

        $wrappingCloseIndex = $this->peekNextMeaningfulIndex($tokens, $closeCtorParenIndex);
        if ($wrappingCloseIndex === null || $tokens[$wrappingCloseIndex] !== ')') {
            return null;
        }

        $target = $this->findScopedCallTarget($tokens, $wrappingCloseIndex);
        if ($target === null) {
            return null;
        }
        [$methodName, $methodNameIndex] = $target;

        return [$receiverFqcn, $methodName, $methodNameIndex];
    }

    /** @param list<Token> $tokens */
    private function peekPrevMeaningfulIndex(array $tokens, int $i): ?int
    {
        $j = $i - 1;
        while ($j >= 0) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j--;
                continue;
            }
            return $j;
        }
        return null;
    }

    /**
     * A bare class-string callback can be constructed as `__NAMESPACE__ . '\Class::method'`.
     * The literal's leading slash does not make it global: it simply joins the namespace magic
     * constant to its class suffix. LiteSpeed Cache uses this for register_uninstall_hook(), and
     * the same PHP callable form is common for namespaced WordPress hook callbacks. Requiring
     * the exact adjacent `T_NS_C . <string>` sequence avoids changing resolution for ordinary
     * explicitly-global `'\Class::method'` callback literals.
     *
     * @param list<Token> $tokens
     */
    private function isNamespaceConcatenatedClassCallback(array $tokens, int $stringIndex, string $classPart): bool
    {
        if (!str_starts_with($classPart, '\\')) {
            return false;
        }

        $dotIndex = $this->peekPrevMeaningfulIndex($tokens, $stringIndex);
        if ($dotIndex === null || $tokens[$dotIndex] !== '.') {
            return false;
        }

        $namespaceIndex = $this->peekPrevMeaningfulIndex($tokens, $dotIndex);
        return $namespaceIndex !== null
            && is_array($tokens[$namespaceIndex])
            && $tokens[$namespaceIndex][0] === T_NS_C;
    }

    /** @param list<Token> $tokens */
    private function isArrayOpenAt(array $tokens, int $index): bool
    {
        if (($tokens[$index] ?? null) === '[') {
            return true;
        }
        if (($tokens[$index] ?? null) === '(') {
            // Long array syntax: array( ... ) — the '(' must belong to an `array` keyword, not
            // an ordinary function call.
            $arrIndex = $this->peekPrevMeaningfulIndex($tokens, $index);
            return $arrIndex !== null && is_array($tokens[$arrIndex]) && $tokens[$arrIndex][0] === T_ARRAY;
        }
        return false;
    }

    /**
     * True when $i is the last element of the array/list literal it sits inside — the next
     * meaningful token (past a possible trailing `. <segment>` concatenation chain — $i might
     * only be the first segment of a longer expression that's still just ONE array element, e.g.
     * `array($this, 'footer_html_' . $i)` — and then one optional trailing comma) is a closing
     * `]`/`)`. Doesn't verify $i is inside an array at all (callers already know that from their
     * own backward walk); only rules out "there's at least one more element after this one."
     * A concatenated segment is usually a single value token (a bare variable, a literal), but a
     * single-call-expression segment (`substr($var, N)` — real-world shape: WooCommerce's
     * breadcrumb dispatch) is also unwound, by skipping to its matching close paren instead of
     * just one token, so the walk doesn't mistake the call's own opening paren for the whole
     * array's closing one; anything more complex than that (a nested function call as an
     * argument, a chained call) still isn't attempted.
     *
     * @param list<Token> $tokens
     */
    private function isLastArrayElementAt(array $tokens, int $i): bool
    {
        $j = $this->peekNextMeaningfulIndex($tokens, $i);
        while ($j !== null && $tokens[$j] === '.') {
            $j = $this->peekNextMeaningfulIndex($tokens, $j); // the concatenated segment itself
            if ($j === null) {
                return false;
            }
            $afterSegmentStart = $this->peekNextMeaningfulIndex($tokens, $j);
            if ($afterSegmentStart !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$afterSegmentStart] === '(') {
                $closeParenIndex = $this->findMatchingCloseParen($tokens, $afterSegmentStart);
                if ($closeParenIndex === null) {
                    return false;
                }
                $j = $this->peekNextMeaningfulIndex($tokens, $closeParenIndex); // token right after the call
                continue;
            }
            $j = $this->peekNextMeaningfulIndex($tokens, $j); // token right after that segment
        }
        if ($j !== null && $tokens[$j] === ',') {
            $j = $this->peekNextMeaningfulIndex($tokens, $j);
        }
        return $j !== null && ($tokens[$j] === ']' || $tokens[$j] === ')');
    }

    /**
     * Given the index of a string token that looks like a method/function name, checks whether
     * it's the second element of an array-callback literal — [receiver, 'method'] or
     * array(receiver, 'method') — with a receiver wp-specter can resolve to a concrete class:
     * $this, self::class, parent::class, Foo::class, or a literal 'Foo' string. Returns the
     * resolved class name, or null if this isn't that shape at all (an arbitrary variable
     * receiver like [$obj, 'method'], more/fewer than two elements, no array literal here).
     *
     * @param list<?string> $classNameStack
     * @param list<?string> $classParentStack
     * @param list<Token> $tokens
     * @param array<string,string> $useImports
     */
    private function arrayCallbackReceiverClass(array $tokens, int $i, array $classNameStack, array $classParentStack, string $currentNamespace, array $useImports): ?string
    {
        // Real-world regression (Sydney theme): a plain list of 3+ string literals —
        // array('One', 'Two', 'Three', ...), no callback semantics at all — was misparsed the
        // instant any two adjacent elements happened to look like a [$receiver, 'method'] pair.
        // Checking only *backward* from $i (comma, then a plausible receiver, then the array's
        // own opening bracket) can't tell "the real 2-element callback shape" apart from
        // "these just happen to be the first two entries of a longer list" — 'Two' here matched
        // every backward check and got consumed into a fabricated ScopedMethodCall('One', 'Two'),
        // silently dropping it from $functionCalls/$classReferences entirely (any project's own
        // Sydney_Abilities_Typography-style array of class names has its 2nd entry vanish this
        // way). A real callback pair is never longer than 2 elements, so $i must also be the
        // array's *last* element — checked once here, covering all three shape branches below.
        if (!$this->isLastArrayElementAt($tokens, $i)) {
            return null;
        }

        $commaIndex = $this->peekPrevMeaningfulIndex($tokens, $i);
        if ($commaIndex === null || $tokens[$commaIndex] !== ',') {
            return null;
        }

        $receiverEndIndex = $this->peekPrevMeaningfulIndex($tokens, $commaIndex);
        if ($receiverEndIndex === null) {
            return null;
        }
        $receiverEndToken = $tokens[$receiverEndIndex];

        // [$this, 'method']
        if (is_array($receiverEndToken) && $receiverEndToken[0] === T_VARIABLE && $receiverEndToken[1] === '$this') {
            $openIndex = $this->peekPrevMeaningfulIndex($tokens, $receiverEndIndex);
            if ($openIndex === null || !$this->isArrayOpenAt($tokens, $openIndex)) {
                return null;
            }
            return empty($classNameStack) ? null : end($classNameStack);
        }

        // [Foo::class, 'method'] / [self::class, 'method'] / [parent::class, 'method']
        // Note: under TOKEN_PARSE (used throughout this parser), the "class" in "Foo::class"
        // always tokenizes as T_STRING with value "class" — T_CLASS is only the `class`
        // *keyword* (declarations, `new class {}`), never this magic-constant access.
        if (is_array($receiverEndToken) && $receiverEndToken[0] === T_STRING && $receiverEndToken[1] === 'class') {
            $dcIndex = $this->peekPrevMeaningfulIndex($tokens, $receiverEndIndex);
            if ($dcIndex === null || !is_array($tokens[$dcIndex]) || $tokens[$dcIndex][0] !== T_DOUBLE_COLON) {
                return null;
            }
            $nameIndex = $this->peekPrevMeaningfulIndex($tokens, $dcIndex);
            if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                return null;
            }
            $openIndex = $this->peekPrevMeaningfulIndex($tokens, $nameIndex);
            if ($openIndex === null || !$this->isArrayOpenAt($tokens, $openIndex)) {
                return null;
            }
            return $this->resolveClassNameToken($tokens[$nameIndex][1], $classNameStack, $classParentStack, $currentNamespace, $useImports);
        }

        // ['Foo', 'method']
        if (is_array($receiverEndToken) && $receiverEndToken[0] === T_CONSTANT_ENCAPSED_STRING) {
            $openIndex = $this->peekPrevMeaningfulIndex($tokens, $receiverEndIndex);
            if ($openIndex === null || !$this->isArrayOpenAt($tokens, $openIndex)) {
                return null;
            }
            // Unlike the $this/self::class/Foo::class branches above, a bare pair of plain
            // string literals has no unambiguous callback syntax at all — shape alone can't
            // tell a real ['Foo', 'method'] pair apart from an ordinary 2-item list of
            // unrelated strings (real-world regression: WooCommerce's `$controllers =
            // array('WC_REST_Product_Brands_V2_Controller', 'WC_REST_Product_Brands_Controller');`
            // later consumed via `new $controller()` in a foreach — not a callback at all;
            // the 2nd literal was silently fabricated into ScopedMethodCall(1st, 2nd) and
            // vanished from $functionCalls/$classReferences, the same failure mode
            // testPlainThreeElementStringArrayIsNotMisparsedAsACallback already fixed for
            // 3+ elements — just never covered the exactly-2 case, where "last element"
            // alone can't rule anything out). Every genuine bare-string-pair callback found
            // in the wild (Akismet: `add_action('init', array('Akismet', 'init'))`) sits
            // directly inside a function call's own argument list; a bare `$var =
            // array(...)`/`$var = [...]` assignment never is — the one context signal this
            // token-based parser can check without full dataflow.
            $beforeArray = $this->tokenBeforeArrayLiteral($tokens, $openIndex);
            if ($beforeArray !== null && $tokens[$beforeArray] === '=') {
                return null;
            }
            $literal = $this->stripQuotes($receiverEndToken[1]);
            return $literal !== '' ? $this->resolveFqcn($literal, $currentNamespace, $useImports) : null;
        }

        return null;
    }

    /**
     * Resolves method_exists()'s first argument to a receiver class — the narrow set of shapes
     * with real-world evidence: `$this`, `self::class`/`static::class` (both resolve to the
     * current class — no late-static-binding fan-out, same trade-off as this parser's other
     * self/static resolutions), `__CLASS__`, and a bare class-name string literal. Anything else
     * (a tracked variable, a computed expression) returns null — same "don't guess" stance
     * arrayCallbackReceiverClass() takes for its own unhandled shapes.
     *
     * @param list<Token> $argTokens
     * @param array<string,string> $useImports
     */
    private function methodExistsReceiverClass(array $argTokens, ?string $currentClass, string $currentNamespace, array $useImports): ?string
    {
        if (count($argTokens) === 1 && is_array($argTokens[0])) {
            $token = $argTokens[0];
            if ($token[0] === T_VARIABLE && $token[1] === '$this') {
                return $currentClass;
            }
            if ($token[0] === T_CLASS_C) {
                return $currentClass;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $this->stripQuotes($token[1]);
                return $literal !== '' ? $this->resolveFqcn($literal, $currentNamespace, $useImports) : null;
            }
            return null;
        }

        // self::class / static::class
        if (
            count($argTokens) === 3
            && is_array($argTokens[0]) && (in_array($argTokens[0][0], self::CLASS_NAME_TOKENS, true) || $argTokens[0][0] === T_STATIC)
            && is_array($argTokens[1]) && $argTokens[1][0] === T_DOUBLE_COLON
            && is_array($argTokens[2]) && $argTokens[2][0] === T_STRING && $argTokens[2][1] === 'class'
        ) {
            return $currentClass;
        }

        return null;
    }

    /**
     * Given method_exists()'s second-argument token list (already comma/paren-bounded — see
     * argTokensAt), recognizes a literal prefix concatenated with anything, optionally followed
     * by a literal suffix — `'prefix' . <anything>` / `'prefix' . <anything> . 'suffix'` — or the
     * double-quoted interpolated-string equivalent (`"prefix$anything"` /
     * `"prefix{$anything}"` / `"prefix{$anything}suffix"`). Doesn't care what `<anything>`
     * actually is or evaluates to — unlike the class-name-transform family elsewhere in this
     * parser, no domain enumeration is needed here: method_exists()'s own second argument being
     * built this way, checked against a receiver method_exists() can resolve, is itself the
     * whole signal (see the parser's own call site for the precision-guard reasoning). Returns
     * null for any other shape — a plain literal (already handled elsewhere), a bare variable
     * alone, or more than one dynamic segment.
     *
     * @param list<Token> $argTokens
     * @return array{string,string}|null [prefix, suffix]
     */
    private function methodExistsDynamicNamePrefixSuffix(array $argTokens): ?array
    {
        $n = count($argTokens);

        if (
            $n >= 3
            && is_array($argTokens[0]) && $argTokens[0][0] === T_CONSTANT_ENCAPSED_STRING
            && $argTokens[1] === '.'
        ) {
            $prefix = $this->stripQuotes($argTokens[0][1]);
            if (
                $n >= 5
                && $argTokens[$n - 2] === '.'
                && is_array($argTokens[$n - 1]) && $argTokens[$n - 1][0] === T_CONSTANT_ENCAPSED_STRING
            ) {
                return [$prefix, $this->stripQuotes($argTokens[$n - 1][1])];
            }
            return [$prefix, ''];
        }

        if ($n >= 4 && $argTokens[0] === '"') {
            // "prefix$var" — exactly [", TEXT, VAR, "].
            if (
                $n === 4
                && is_array($argTokens[1]) && $argTokens[1][0] === T_ENCAPSED_AND_WHITESPACE
                && is_array($argTokens[2]) && $argTokens[2][0] === T_VARIABLE
                && $argTokens[3] === '"'
            ) {
                return [$argTokens[1][1], ''];
            }
            // "prefix{$var}" / "prefix{$var}suffix".
            if (
                $n >= 6
                && is_array($argTokens[1]) && $argTokens[1][0] === T_ENCAPSED_AND_WHITESPACE
                && is_array($argTokens[2]) && $argTokens[2][0] === T_CURLY_OPEN
                && is_array($argTokens[3]) && $argTokens[3][0] === T_VARIABLE
                && $argTokens[4] === '}'
            ) {
                if ($n === 6 && $argTokens[5] === '"') {
                    return [$argTokens[1][1], ''];
                }
                if ($n === 7 && is_array($argTokens[5]) && $argTokens[5][0] === T_ENCAPSED_AND_WHITESPACE && $argTokens[6] === '"') {
                    return [$argTokens[1][1], $argTokens[5][1]];
                }
            }
        }

        return null;
    }

    /**
     * Index of the token immediately preceding an array/list literal's own opening bracket —
     * for short syntax (`[`) that's just whatever comes before $openIndex; for long syntax
     * (`array(`) it's whatever comes before the `array` keyword itself, not before the `(`.
     *
     * @param list<Token> $tokens
     */
    private function tokenBeforeArrayLiteral(array $tokens, int $openIndex): ?int
    {
        if ($tokens[$openIndex] === '[') {
            return $this->peekPrevMeaningfulIndex($tokens, $openIndex);
        }
        $arrayKeywordIndex = $this->peekPrevMeaningfulIndex($tokens, $openIndex);
        if ($arrayKeywordIndex === null) {
            return null;
        }
        return $this->peekPrevMeaningfulIndex($tokens, $arrayKeywordIndex);
    }

    private function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    private function looksLikeCallback(string $value): bool
    {
        // Valid PHP function name: letters, digits, underscore, starts with letter or _
        // Also allow Namespace\Class::method but keep simple for now
        return (bool) preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $value);
    }

    private function emptyResult(string $file, string $error): ParseResult
    {
        return new ParseResult(
            file: $file,
            functionDefs: [],
            functionCalls: [],
            hookRegistrations: [],
            hookInvocations: [],
            templateRefs: [],
            phpPathStrings: [],
            error: $error,
        );
    }
}
