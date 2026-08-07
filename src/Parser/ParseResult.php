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
     */
    public function __construct(
        public readonly string $file,
        public readonly array $functionDefs,
        public readonly array $functionCalls,
        public readonly array $hookRegistrations,
        public readonly array $hookInvocations,
        public readonly array $templateRefs,
        public readonly array $phpPathStrings = [],
        public readonly ?string $error = null,
    ) {}
}
