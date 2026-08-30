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
    // into the suffix-based check below.
    private const TEMPLATE_FUNCS = ['get_template_part', 'get_header', 'get_footer', 'get_sidebar'];
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
    private const INCLUDE_KEYWORDS = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
    // T_STRING: plain `Foo`. T_NAME_QUALIFIED: `Foo\Bar`. T_NAME_FULLY_QUALIFIED: `\Foo\Bar`.
    private const CLASS_NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
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

        // $functionNameStack/$varLiteralAssignmentsStack run in lockstep with $functionDepthStack
        // /$varTypesStack (same push-on-'{'/pop-on-'}' pattern) — together they let a `return`
        // statement resolve to every literal a helper function might hand back, e.g.
        // `function ocean_single_post_header_template() { if (...) { $p = 'a'; } elseif (...) {
        // $p = 'b'; } return apply_filters('tag', $p); }` — real-world example (OceanWP theme).
        // $varLiteralAssignmentsStack *accumulates* every literal ever assigned to a variable
        // within the current function body (unlike $varTypesStack's last-write-wins), since which
        // conditional branch runs is exactly what can't be known statically — the whole point is
        // capturing every possibility a literal-only assignment reveals, tolerating that a
        // non-literal branch (a value this parser can't resolve at all) is simply invisible
        // rather than invalidating what *is* known.
        $functionNameStack = [];
        $pendingFunctionName = null;
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
        /** @var list<PendingTemplateHelperCall> $pendingTemplateHelperCalls */
        $pendingTemplateHelperCalls = [];
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
                        $functionNameStack[] = $pendingFunctionName;
                        $varLiteralAssignmentsStack[] = [];
                        $varLiteralValueStack[] = [];
                        $varAssignedFromFunctionStack[] = [];
                        $functionHasIncludeStack[] = false;
                        $functionDefIndexForBodyStack[] = $pendingFunctionDefIndex;
                        $expectingFunctionOpen = false;
                        $pendingParamTypes = [];
                        $pendingFunctionName = null;
                        $pendingFunctionDefIndex = null;
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
                            array_pop($functionNameStack);
                            array_pop($varLiteralAssignmentsStack);
                            array_pop($varLiteralValueStack);
                            array_pop($varAssignedFromFunctionStack);
                            $hasInclude = array_pop($functionHasIncludeStack);
                            $defIndex = array_pop($functionDefIndexForBodyStack);
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
                    // $interpolationDepth's own brace-depth-safety tracking below).
                    $interpResult = $this->resolveInterpolatedLoopSuffixPath($tokens, $i, $forLoopVarNameStack, $forLoopVarValuesStack);
                    if ($interpResult !== null) {
                        [$enumeratedPaths, $lastInterpIndex] = $interpResult;
                        foreach ($enumeratedPaths as $path) {
                            $phpPathStrings[] = $path;
                        }
                        $i = $lastInterpIndex;
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

            if ($type === T_NEW || $type === T_INSTANCEOF) {
                $ref = $this->captureClassNameAfter($tokens, $i);
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
            }

            // `return $template_path;` / `return apply_filters('tag', $template_path);` /
            // `return 'literal';` inside a top-level named function — resolved against the
            // current function scope's accumulated $varLiteralAssignmentsStack entry and folded
            // into $functionLiteralReturns for that function's own name. See
            // resolveFunctionCallHelperArg()/the TEMPLATE_FUNCS branch below for the other half:
            // a get_template_part()-family call whose argument is a bare call to this function.
            if ($type === T_RETURN) {
                $currentFunctionName = empty($functionNameStack) ? null : end($functionNameStack);
                if ($currentFunctionName !== null) {
                    $varScopeTop = count($varLiteralAssignmentsStack) - 1;
                    $literals = $this->resolveReturnLiterals($tokens, $i, $varLiteralAssignmentsStack[$varScopeTop]);
                    if ($literals !== []) {
                        if (!isset($functionLiteralReturns[$currentFunctionName])) {
                            $functionLiteralReturns[$currentFunctionName] = [];
                        }
                        array_push($functionLiteralReturns[$currentFunctionName], ...$literals);
                    }
                }
            }

            if ($type === T_EXTENDS || $type === T_IMPLEMENTS) {
                foreach ($this->captureClassNameList($tokens, $i, $currentNamespace, $useImports) as $ref) {
                    $classReferences[] = $ref->short;
                }
                continue;
            }

            if ($type === T_FUNCTION) {
                $insideClass = !empty($classDepthStack);
                $ownerClass = empty($classNameStack) ? null : end($classNameStack);
                $ownerParent = empty($classParentStack) ? null : end($classParentStack);
                $def = $this->parseFunctionDef($tokens, $i, $file, $insideClass, $ownerClass);
                if ($def !== null) {
                    $isGuarded = !empty($functionExistsGuardNameStack) && end($functionExistsGuardNameStack) === $def->name;
                    $fqcn = $currentNamespace === '' ? $def->name : $currentNamespace . '\\' . $def->name;
                    $def = new FunctionDef($def->name, $def->line, $def->file, $def->isMethod, $def->ownerClass, $def->returnType, guarded: $isGuarded, fqcn: $fqcn);
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
                        $def = new FunctionDef($def->name, $def->line, $def->file, $def->isMethod, $def->ownerClass, $returnType->fqcn, guarded: $def->guarded, fqcn: $def->fqcn);
                    }
                }
                if ($def !== null) {
                    $functionDefs[] = $def;
                }
                $pendingFunctionDefIndex = $def !== null ? count($functionDefs) - 1 : null;
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
                // Only a top-level (non-method) named function is ever callable via the bare
                // `helper_fn()` shape resolveFunctionCallHelperArg() looks for at a call site —
                // restricting to that avoids a same-named method ever being mismatched against
                // an unrelated global function's return literals.
                $pendingFunctionName = ($def !== null && !$insideClass) ? $def->name : null;
                // Every function/method/closure opens its own variable scope — including
                // anonymous ones ($def === null for those), which need this exactly as much.
                $expectingFunctionOpen = true;
                continue;
            }

            if ($type === T_VARIABLE) {
                $scopeTop = count($varTypesStack) - 1;

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
                                    ?? $this->assignedVariableClassName($tokens, $afterPropIndex, $varTypesStack[$scopeTop]);
                                if ($newClass !== null) {
                                    $propertyAssignedClasses[$receiverClass][$propName] = $newClass;
                                } else {
                                    unset($propertyAssignedClasses[$receiverClass][$propName]);
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
                    $newClass = $this->assignedNewClassName($tokens, $equalsIndex, $classNameStack, $classParentStack, $currentNamespace, $useImports);
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

                    // $var = 'literal'; — accumulated (not overwritten) into the current
                    // function scope's $varLiteralAssignmentsStack entry; see its own
                    // declaration comment above for why accumulation, not last-write-wins, is
                    // the right call here.
                    $singleLiteral = $this->singleStringLiteralRhs($tokens, $equalsIndex);
                    if ($singleLiteral !== null) {
                        $varScopeTop = count($varLiteralAssignmentsStack) - 1;
                        $varLiteralAssignmentsStack[$varScopeTop][$value][] = $singleLiteral;
                        // $hook = 'my_plugin_loaded'; do_action($hook); — last-write-wins,
                        // consulted by classifyArgTokens() below when a hook/template-part
                        // argument is a bare variable instead of a literal directly.
                        $varLiteralValueStack[$varScopeTop][$value] = $singleLiteral;
                    } else {
                        $varScopeTop = count($varLiteralValueStack) - 1;
                        unset($varLiteralValueStack[$varScopeTop][$value]);
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
                    $trackedClass = $varTypesStack[$scopeTop][$value] ?? null;
                    if ($trackedClass !== null) {
                        $target = $this->findScopedCallTarget($tokens, $i);
                        if ($target !== null) {
                            [$methodName, $methodNameIndex] = $target;
                            $scopedMethodCalls[] = new ScopedMethodCall($trackedClass, $methodName);
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

                if ($nextNonWhitespace === '::') {
                    // Foo::method(), Foo::CONST, Foo::class, Foo::$prop — whatever comes after
                    // the '::', "Foo" itself is a class reference either way.
                    $classReferences[] = $name;

                    // self::method()/parent::method()/Foo::method() (but not Foo::CONST,
                    // Foo::class, Foo::$prop — findScopedCallTarget only matches an actual call).
                    $target = $this->findScopedCallTarget($tokens, $i);
                    if ($target !== null) {
                        [$methodName, $methodNameIndex] = $target;

                        $this->recordWpCliAddCommandDispatch($name, $methodName, $tokens, $methodNameIndex, $currentNamespace, $useImports, $reflectionDispatchedClassNames);

                        $receiverClass = $this->resolveClassNameToken($name, $classNameStack, $classParentStack, $currentNamespace, $useImports);
                        if ($receiverClass !== null) {
                            $scopedMethodCalls[] = new ScopedMethodCall($receiverClass, $methodName);
                            $i = $methodNameIndex;

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
                        }
                    }
                    continue;
                }

                if ($nextNonWhitespace !== '(') {
                    // Could be a string callback — handled below via T_CONSTANT_ENCAPSED_STRING
                    continue;
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
                );
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
                    $this->dispatchBareFunctionCall(
                        $this->shortClassName($value),
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
                    );
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
                // A literal directly concatenated with a currently-tracked bounded for-loop
                // variable (`'prefix_' . $i` inside `for ($i = 1; $i < 5; $i++) { ... }`) —
                // computed once per literal and shared by both the callback-name and file-path
                // checks below, since exactly one of them ever consumes it for a given literal
                // (a callback-shaped identifier can't also end in '.php' — PHP identifiers don't
                // contain dots). See resolveForLoopConcatenatedLiteral's own docblock for the
                // real-world shapes this covers.
                $forLoopEnumeration = $this->resolveForLoopConcatenatedLiteral($tokens, $i, $stringVal, $forLoopVarNameStack, $forLoopVarValuesStack);
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
                if ($forLoopCallbackEnumeration !== null) {
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
                        $classPart = $this->shortClassName($classPartRaw);
                        if (!in_array($classPart, ['self', 'parent', 'static'], true)
                            && $this->looksLikeCallback($classPart)
                        ) {
                            $classReferences[] = $classPart;
                            $receiverClass = $this->resolveFqcn($classPartRaw, $currentNamespace, $useImports);
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
                if ($forLoopEnumeration !== null && $forLoopCallbackEnumeration === null) {
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

        return new FunctionDef($next[1], $next[2], $file, $isMethod, $ownerClass);
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
                        : new ClassRef($this->shortClassName($name), $this->resolveFqcn($name, $currentNamespace, $useImports)),
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
                        : new ClassRef($this->shortClassName($name), $this->resolveFqcn($name, $currentNamespace, $useImports)),
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
    ): void {
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
        [, , $path] = $this->extractStringArgAt($tokens, $i, 0, $varLiteralValues, $classConstants, $currentClass, $currentParent, $currentNamespace, $useImports) ?? ['', true, ''];

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
     * string literal and its second is either also a plain string literal —
     * `add_command('astra abilities', 'Astra_Abilities_CLI')` — or the idiomatic modern-PHP
     * `Foo::class` form — `add_command('elementor experiments', WP_CLI::class)` (real-world
     * shape, Elementor). Null for anything else (fewer than two arguments, a non-literal first
     * argument, or a second argument that's neither shape). Same "only trust the simplest literal
     * shape" stance as firstStringArgIndex — a concatenated or variable class name is left
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
            $receiverName = $argTokens[0][0] === T_STATIC ? 'static' : $argTokens[0][1];
            $receiverClass = match (strtolower($receiverName)) {
                'self', 'static' => $currentClass,
                'parent' => $currentParent,
                default => $this->resolveFqcn($receiverName, $currentNamespace, $useImports),
            };
            $value = $receiverClass !== null ? ($classConstants[$receiverClass][$argTokens[2][1]] ?? null) : null;
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
     * late-static-binding placeholder, not a literal class name).
     * @param list<Token> $tokens
     */
    private function captureClassNameAfter(array $tokens, int $i): ?string
    {
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        $next = $tokens[$j] ?? null;
        if (!is_array($next) || !in_array($next[0], self::CLASS_NAME_TOKENS, true)) {
            return null;
        }

        return $this->shortClassName($next[1]);
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
                $names[] = new ClassRef($this->shortClassName($t[1]), $this->resolveFqcn($t[1], $currentNamespace, $useImports));
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
     * @param list<list<int>> $forLoopVarValuesStack
     * @return array{list<string>, int}|null [enumerated basenames, last consumed token index —
     *   the closing '"']
     */
    private function resolveInterpolatedLoopSuffixPath(
        array $tokens,
        int $i,
        array $forLoopVarNameStack,
        array $forLoopVarValuesStack,
    ): ?array {
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
        $closingQuoteIndex = $j;

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
     * $i points at T_RETURN. Resolves what it hands back to a list of literals when possible:
     *  - `return 'literal';` — the literal itself.
     *  - `return $var;` — whatever's accumulated for $var in $varLiteralAssignments (the current
     *    function scope's own copy).
     *  - `return apply_filters('tag', $var_or_literal, ...);` — the same, read from the filter's
     *    second argument (its "default value" position) — an extremely common WP idiom for
     *    exactly this "return a value, but let a filter override it" shape.
     * Anything else (any other expression) resolves to [] — no guessing beyond these three shapes.
     *
     * @param list<Token> $tokens
     * @param array<string,list<string>> $varLiteralAssignments
     * @return list<string>
     */
    private function resolveReturnLiterals(array $tokens, int $i, array $varLiteralAssignments): array
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
     * $equalsIndex points at the `=` of `$var = array(...)` / `$var = [...]`. Returns the
     * elements in order when every one is a plain string literal (no keys, no concatenation, no
     * nested arrays/calls/variables) — bailing (null) the moment anything else appears, same
     * "bail rather than guess" stance parseUseImports takes on group-use syntax. Also bails (to
     * avoid matching an unrelated short-string/single-word array by coincidence) unless there are
     * at least two elements and at least one contains a "/" — the two `str_contains` calls a
     * genuine bulk-include path list will always pass and an arbitrary config array often won't.
     *
     * @param list<Token> $tokens
     * @return list<string>|null
     */
    private function parseStringLiteralArray(array $tokens, int $equalsIndex): ?array
    {
        $j = $this->skipInsignificant($tokens, $equalsIndex + 1);
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
                if (count($values) < 2) {
                    return null;
                }
                foreach ($values as $v) {
                    if (str_contains($v, '/')) {
                        return $values;
                    }
                }
                return null;
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
     * Same "single segment per `.`" assumption resolveForLoopConcatenatedLiteral's own suffix
     * handling already makes — a concatenation chain with something more complex than a bare
     * value token per segment (a nested function call, say) isn't unwound here either.
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
            $literal = $this->stripQuotes($receiverEndToken[1]);
            return $literal !== '' ? $this->resolveFqcn($literal, $currentNamespace, $useImports) : null;
        }

        return null;
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
