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
        public readonly array $traitUsages = [],
        public readonly array $globIncludeDirs = [],
        public readonly array $rootRelativeIncludeDirs = [],
        public readonly bool $hasIncludeStatement = false,
        public readonly array $useImports = [],
        public readonly array $functionLiteralReturns = [],
        public readonly array $pendingTemplateHelperCalls = [],
        public readonly ?string $error = null,
    ) {}
}
