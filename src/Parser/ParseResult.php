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
     * @param array<string,list<string>> $functionLiteralReturns Top-level function name => every
     *                                           literal a `return` statement inside its body
     *                                           resolved to (direct literal, a variable
     *                                           accumulated from literal-only assignments, or
     *                                           apply_filters()'s own default-value argument —
     *                                           see PhpTokenParser's T_RETURN handling). Lets a
     *                                           get_template_part()-family call whose argument is
     *                                           a bare call to one of these functions (see
     *                                           $pendingTemplateHelperCalls) resolve to every
     *                                           literal path that function might hand back.
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
        public readonly ?string $error = null,
    ) {}
}
