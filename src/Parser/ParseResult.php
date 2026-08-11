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
     * @param list<string>          $globIncludeDirs Directories a glob() call in this file scans
     *                                                (see PhpTokenParser::parseGlobDirRef).
     * @param array<string,string>  $useImports Short name/alias => fully-qualified name, from
     *                                           this file's top-level `use Some\Namespace\Name;`
     *                                           imports (see PhpTokenParser::parseUseImports).
     *                                           Used to resolve an extends/implements target to
     *                                           a real vendor class for VendorClassReflector.
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
        public readonly bool $hasIncludeStatement = false,
        public readonly array $useImports = [],
        public readonly ?string $error = null,
    ) {}
}
