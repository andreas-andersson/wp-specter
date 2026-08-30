<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A scoped call (`Receiver::method($argExpr)`) whose sole argument resolves to one or more
 * candidate literal strings — a bare variable with a known literal domain (ternary/if-else
 * assignment, or sibling equality-comparison — see PhpTokenParser's $varLiteralAssignmentsStack),
 * or `'literal' . $var` with the same — rather than a literal directly. PhpTokenParser can't
 * resolve the callee's own return shape from a single file alone (the callee routinely lives in a
 * different file than this call site), so it's left as a pending reference for whichever analyzer
 * merges $functionParamSuffixReturns across every scanned file to resolve afterward: each
 * candidate gets the callee's own recorded suffix appended, the same way
 * PendingTemplateHelperCall resolves against $functionLiteralReturns. Real-world shape
 * (wp-nested-pages): `include( Helpers::view($row_view) )` where `Helpers::view()`'s own body is
 * `return dirname(__FILE__) . '/Views/' . $file . '.php';` and `$row_view`'s domain comes from a
 * ternary assignment two lines earlier.
 */
final class PendingParamSuffixCall
{
    /** @param list<string> $argumentCandidates */
    public function __construct(
        public readonly string $receiverClass,
        public readonly string $methodName,
        public readonly array $argumentCandidates,
    ) {}
}
