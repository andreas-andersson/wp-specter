<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class FunctionCall
{
    public function __construct(
        public readonly string $name,
        public readonly int $line,
        public readonly string $file,
        // An additional, fully-resolved candidate identity for this exact call — populated only
        // for the two shapes where $name (the bare/short form every other consumer of
        // ParseResult::$functionCalls already relies on unchanged) isn't the whole story:
        //   - a namespaced/fully-qualified call (`Foo\Bar\helper()`) resolves deterministically,
        //     no runtime ambiguity — the real resolveFqcn() result, same rule a class reference
        //     would use.
        //   - a BARE call made from inside a namespaced file is genuinely ambiguous: PHP tries
        //     the current namespace first, falling back to the global namespace only if nothing
        //     matches there — a real runtime decision this static parser can't make, so this
        //     holds the "if it resolves locally" candidate (`$currentNamespace . '\\' . $name`)
        //     alongside $name itself (the "or it's global" candidate).
        // Null for the common case (a bare call made from un-namespaced code) — $name is already
        // the only candidate there, exactly as before this field existed. See
        // FunctionAnalyzer::analyze()'s own docblock for how this gets consulted.
        public readonly ?string $extraCandidateFqcn = null,
    ) {}
}
