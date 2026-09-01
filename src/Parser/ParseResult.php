<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class ParseResult
{
    /**
     * @param list<FunctionDef>     $functionDefs
     * @param list<FunctionCall>    $functionCalls
     * @param list<HookRegistration> $hookRegistrations
     * @param list<HookInvocation>  $hookInvocations
     * @param list<TemplateRef>     $templateRefs
     * @param list<string>          $phpPathStrings
     * @param list<ClassDef>        $classDefs
     * @param list<string>          $classReferences
     * @param list<ScopedMethodCall> $scopedMethodCalls
     * @param list<ScopedMethodCallPrefix> $scopedMethodCallPrefixes Same shape as
     *                                           $scopedMethodCalls, but for an array-callback
     *                                           built via string concatenation with a resolvable
     *                                           receiver — `array($this, 'footer_html_' .
     *                                           $index)` inside a loop. Any method on the
     *                                           receiver class starting with the prefix counts as
     *                                           used (see PhpTokenParser's T_CONSTANT_ENCAPSED_
     *                                           STRING handling). Deliberately requires a
     *                                           resolved receiver — without that narrowing, a
     *                                           prefix match on an unscoped name is far too broad
     *                                           (ordinary string-building unrelated to any
     *                                           callback matches the same identifier-prefix
     *                                           shape constantly, and a short incidental prefix
     *                                           would hide genuinely dead code project-wide).
     * @param list<string>          $reflectionDispatchedClassNames Class names passed as a bare
     *                                           string to an API that dispatches across *every*
     *                                           public method of that class by reflection —
     *                                           currently just `WP_CLI::add_command($hook,
     *                                           'My_Class')`, which calls whatever public method
     *                                           matches the CLI subcommand typed, not a fixed
     *                                           method name a curated list could name up front.
     * @param array<string,array<string,string>> $propertyAssignedClasses Class name => property
     *                                           name => the class assigned to it, from every
     *                                           `$this->prop = new ClassName()` sighting anywhere
     *                                           in that class's body (last write wins, same as
     *                                           local-variable tracking), plus every constructor-
     *                                           promoted property's own type hint (`public
     *                                           function __construct(private My_Service $svc)`
     *                                           auto-assigns `$this->svc`, same as an explicit
     *                                           assignment would). Consulted by ClassAnalyzer to
     *                                           resolve $propertyMethodCalls once every file's
     *                                           parse is merged — see PhpTokenParser's T_VARIABLE
     *                                           handling ($this-> branch) and collectParamTypeHint.
     * @param list<PropertyMethodCall> $propertyMethodCalls Every `$this->prop->method()` seen,
     *                                           unresolved — see PropertyMethodCall's own
     *                                           docblock for why resolution is deferred to
     *                                           ClassAnalyzer instead of attempted inline.
     * @param list<PendingReturnTypedCall> $pendingReturnTypedCalls `$x = SomeFactory::make();
     *                                           $x->method();` — unresolved, since make()'s own
     *                                           declared return type might be defined in a
     *                                           different file's parse (or later in this one) —
     *                                           see PendingReturnTypedCall's own docblock.
     * @param list<PendingDirectoryLoaderCall> $pendingDirectoryLoaderCalls A scoped call with a
     *                                           plain string-literal first argument
     *                                           (`Foo::bulkLoad('inc')`) — unresolved, since
     *                                           whether the callee actually bulk-loads that
     *                                           directory can only be known once every file's
     *                                           parse is merged — see
     *                                           PendingDirectoryLoaderCall's own docblock.
     * @param list<TraitUsage>      $traitUsages Each `use TraitName;` seen directly inside a
     *                                            class/trait body, paired with the enclosing
     *                                            class/trait's own name (see PhpTokenParser's
     *                                            T_USE handling).
     * @param list<string>          $globIncludeDirs Directories a glob()/scandir() call in this
     *                                                file scans, relative to the calling file's
     *                                                own directory (see
     *                                                PhpTokenParser::parseGlobDirRef).
     * @param list<string>          $rootRelativeIncludeDirs Directories a glob()/scandir() call
     *                                                resolves via a `define('X', ...)`-tracked
     *                                                constant — a THEME_DIR-style constant's
     *                                                value is conventionally project-root-
     *                                                relative already, not relative to whichever
     *                                                file happens to call scandir($constant), so
     *                                                these are trusted as-is rather than prefixed
     *                                                with the calling file's own directory the
     *                                                way $globIncludeDirs entries are.
     * @param array<string,string>  $useImports Short name/alias => fully-qualified name, from
     *                                           this file's top-level `use Some\Namespace\Name;`
     *                                           imports (see PhpTokenParser::parseUseImports).
     *                                           Used to resolve an extends/implements target to
     *                                           a real vendor class for VendorClassReflector.
     * @param array<string,list<string>> $functionLiteralReturns Function/method key ("Class::
     *                                           method" for a method, bare name for a top-level
     *                                           function — same convention as
     *                                           $functionParamSuffixReturns) => every literal a
     *                                           `return` statement inside its body resolved to
     *                                           (direct literal, a variable accumulated from
     *                                           literal-only assignments, a resolved `self::`/
     *                                           `static::`/`Foo::` class constant, or
     *                                           apply_filters()'s own default-value argument —
     *                                           see PhpTokenParser's T_RETURN handling and
     *                                           resolveReturnLiterals()). Lets a
     *                                           get_template_part()-family call whose argument is
     *                                           a bare call to one of these top-level functions
     *                                           (see $pendingTemplateHelperCalls — methods aren't
     *                                           yet a recognized call-site shape there) resolve to
     *                                           every literal path that function might hand back.
     * @param list<PendingTemplateHelperCall> $pendingTemplateHelperCalls
     * @param array<string,list<string>> $functionParamSuffixReturns Function/method key
     *                                           ("Class::method" for a method, bare name for a
     *                                           top-level function) => every literal suffix a
     *                                           `return <ignored> . $param . 'suffix';` statement
     *                                           inside its body resolved to (see
     *                                           PhpTokenParser::resolveReturnParamSuffixTemplate).
     *                                           Lets a $pendingParamSuffixCalls entry resolve each
     *                                           of its argument candidates to a concrete path once
     *                                           every file's parse is merged.
     * @param list<PendingParamSuffixCall> $pendingParamSuffixCalls
     * @param array<string,list<string>> $selfDispatchSuffixes Method key ("Class::method") =>
     *                                           every literal suffix a `call_user_func([$this,
     *                                           "{$param}_suffix"])`-shaped self-dispatch inside
     *                                           its own body resolved to (see
     *                                           PhpTokenParser::isThisArrayCallbackReceiverAt /
     *                                           extractTrailingVarSuffix). Lets a
     *                                           $pendingSelfDispatchCalls entry resolve its
     *                                           literal argument to the real target method name
     *                                           once every file's parse is merged.
     * @param list<PendingSelfDispatchCall> $pendingSelfDispatchCalls
     * @param array<string,list<array{string,string}>> $selfDispatchPrefixSuffixTemplates Method
     *                                           key ("Class::method") => every [prefix, suffix]
     *                                           pair a `array($this, 'prefix' . $param .
     *                                           'suffix')`-shaped self-dispatch inside its own
     *                                           body resolved to (see
     *                                           PhpTokenParser::resolvePrefixVarSuffixSelfDispatchTemplate).
     *                                           Cross-referenced against $classArrayKeyLiterals
     *                                           (same owner class) once every file's parse is
     *                                           merged — there's no literal call-site argument to
     *                                           pair this with, unlike $pendingSelfDispatchCalls.
     * @param array<string,list<string>> $classArrayKeyLiterals Owner class => every literal
     *                                           string array key assigned via
     *                                           `$anyLocalVar['literal'] = ...;` anywhere in the
     *                                           class's own methods (see
     *                                           PhpTokenParser::arrayKeyLiteralAssignment).
     * @param list<LiteralPathPropagationLink> $literalPathPropagationLinks Directed, local
     *                                           dataflow links from wrapper parameters through
     *                                           fixed literal path fragments, named/scoped
     *                                           wrapper calls, return values, and an include or
     *                                           WP template sink. Resolved only after every file
     *                                           is parsed — see LiteralPathPropagationLink.
     * @param list<LiteralPathInput>     $literalPathInputs Literal positional or keyed-array
     *                                           values supplied at named/scoped wrapper call
     *                                           sites. These seed the bounded link traversal in
     *                                           LiteralPathPropagationResolver.
     * @param list<string>               $literalPathFileExistenceGuards Exact expression/node
     *                                           guards recorded for `file_exists()`/`is_file()`;
     *                                           they permit only their matching guarded
     *                                           literal-path link to discard one unknown direct
     *                                           variable term.
     * @param array<string,int> $hookPassThroughParams Function/method key => which of its own
     *                                           declared parameter positions is passed unchanged
     *                                           as the hook-tag argument to an already-recognized
     *                                           CRON_SCHEDULE_FUNCS/HOOK_INVOKE_FUNCS call inside
     *                                           its own body (see
     *                                           PhpTokenParser::captureHookPassThroughParam).
     *                                           Resolved against $literalPathInputs in
     *                                           HookAnalyzer.
     * @param array<string,list<array{string,list<array{string,list<string>}>,string}>> $classNameTransformTemplates
     *                                           Owner class => every [literal prefix, transform
     *                                           steps, literal suffix] triple recognized as
     *                                           building a dynamic class name from a variable —
     *                                           either `'prefix' . $var` (suffix always `''`
     *                                           there — literalConcatVarAt()), requiring $var to
     *                                           have gone through a recognized
     *                                           str_replace()/ucfirst()/ucwords() chain first, or
     *                                           `"prefix{$var}suffix"` (curly-brace complex
     *                                           interpolation —
     *                                           interpolatedPrefixCurlyVarSuffixAt()), which
     *                                           doesn't require any transform at all — a bare,
     *                                           untransformed variable resolves to a zero-step
     *                                           identity. See
     *                                           PhpTokenParser::resolveTransformChainExpr's own
     *                                           docblock for the transform-chain shapes (one
     *                                           hardcoded idiom generalized twice after
     *                                           independent real-world confirmations each used a
     *                                           different transform combination, or none at all).
     *                                           Steps are replayed in order by
     *                                           WpSpecter\Support\StringTransformChain::apply(),
     *                                           then the suffix appended. Cross-referenced
     *                                           against $classArrayKeyLiterals (same owner class)
     *                                           in ClassAnalyzer/FileAnalyzer, the same "no
     *                                           literal call-site argument, cross-product against
     *                                           every literal key/value the class ever declares"
     *                                           trade-off $selfDispatchPrefixSuffixTemplates
     *                                           already makes — except when the transform's own
     *                                           source variable (`literalConcatVarAt()`'s chain
     *                                           tuple, `[sourceVar, steps]`) matches an actively-
     *                                           tracked `foreach` loop variable, in which case
     *                                           that loop's own concrete values are pushed into
     *                                           $classArrayKeyLiterals directly at capture time
     *                                           instead (Elementor's widget/control/element
     *                                           registries: `foreach ($build_widgets_filename as
     *                                           $widget_filename) { $class_name = str_replace('-',
     *                                           '_', $widget_filename); $class_name =
     *                                           __NAMESPACE__ . '\Widget_' . $class_name; new
     *                                           $class_name(); }` — the domain is a local flat
     *                                           array, never a class-body array literal). A prefix
     *                                           with a leading backslash but no trailing one (that
     *                                           example's `'\Widget_'`) is the __NAMESPACE__
     *                                           separator, not part of the literal short name —
     *                                           ClassAnalyzer/FileAnalyzer strip it before
     *                                           prepending.
     * @param array<string,list<string>> $functionArrayReturns Function/method key (same
     *                                           convention as $functionLiteralReturns) => every
     *                                           literal value a function/method's own `return`
     *                                           statement resolved to when the returned value is
     *                                           a flat literal array rather than a scalar (a
     *                                           bare `$var` tracked in `$anyArrayLiteralVars`, or
     *                                           `apply_filters('tag', $var, ...)` wrapping one) —
     *                                           see PhpTokenParser::resolveReturnArrayLiterals.
     *                                           Real-world shape (Botiga):
     *                                           `botiga_get_default_single_product_components()`
     *                                           returns exactly this. Feeds
     *                                           $functionNameTransformTemplates below.
     * @param list<array{string,list<array{string,list<string>}>}> $functionNameTransformTemplates
     *                                           Flat, project-wide list of [literal function-name
     *                                           prefix, transform steps] pairs — the procedural
     *                                           (no enclosing class) counterpart to
     *                                           $classNameTransformTemplates, feeding
     *                                           FunctionAnalyzer instead of Class/FileAnalyzer.
     *                                           Real-world shape (Botiga):
     *                                           `botiga_get_quick_view_summary_components()`
     *                                           does `array_map(function($component){ $suffix =
     *                                           str_replace('woocommerce_template_single_', '',
     *                                           $component); if ($component ===
     *                                           "woocommerce_template_single_$suffix") { return
     *                                           "botiga_quick_view_summary_$suffix"; } return
     *                                           $component; }, $components)` — the domain-
     *                                           providing function and the transforming closure
     *                                           are two unrelated top-level functions, not
     *                                           methods sharing a class, so there's no owner to
     *                                           scope the cross-product by the way
     *                                           $classNameTransformTemplates is; every entry here
     *                                           is cross-referenced against every
     *                                           $functionArrayReturns entry project-wide instead,
     *                                           the same "coarse net" trade-off just widened to
     *                                           project scope. See
     *                                           PhpTokenParser::interpolatedPrefixVarAt's own
     *                                           docblock for the interpolated-string shape this
     *                                           recognizes (`"literal$var"`, not `.`
     *                                           concatenation) and the T_RETURN handling's own
     *                                           comment for the two gates (inside an
     *                                           `array_map()` closure, and the literal looks like
     *                                           a snake_case function-name prefix) that keep this
     *                                           narrow.
     */
    public function __construct(
        public readonly string $file,
        public readonly array $functionDefs,
        public readonly array $functionCalls,
        public readonly array $hookRegistrations,
        public readonly array $hookInvocations,
        public readonly array $templateRefs,
        public readonly array $phpPathStrings = [],
        public readonly array $classDefs = [],
        public readonly array $classReferences = [],
        public readonly array $scopedMethodCalls = [],
        public readonly array $scopedMethodCallPrefixes = [],
        public readonly array $reflectionDispatchedClassNames = [],
        public readonly array $propertyAssignedClasses = [],
        public readonly array $propertyMethodCalls = [],
        public readonly array $pendingReturnTypedCalls = [],
        public readonly array $pendingDirectoryLoaderCalls = [],
        public readonly array $traitUsages = [],
        public readonly array $globIncludeDirs = [],
        public readonly array $rootRelativeIncludeDirs = [],
        public readonly bool $hasIncludeStatement = false,
        public readonly array $useImports = [],
        public readonly array $functionLiteralReturns = [],
        public readonly array $pendingTemplateHelperCalls = [],
        public readonly array $functionParamSuffixReturns = [],
        public readonly array $pendingParamSuffixCalls = [],
        public readonly array $selfDispatchSuffixes = [],
        public readonly array $pendingSelfDispatchCalls = [],
        public readonly array $selfDispatchPrefixSuffixTemplates = [],
        public readonly array $classArrayKeyLiterals = [],
        public readonly array $literalPathPropagationLinks = [],
        public readonly array $literalPathInputs = [],
        public readonly array $literalPathFileExistenceGuards = [],
        public readonly array $hookPassThroughParams = [],
        public readonly array $classNameTransformTemplates = [],
        public readonly array $functionArrayReturns = [],
        public readonly array $functionNameTransformTemplates = [],
        public readonly ?string $error = null,
    ) {}
}
