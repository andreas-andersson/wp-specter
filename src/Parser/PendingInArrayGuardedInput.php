<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A call argument built as `prefix . $expr . suffix` where `$expr` isn't itself resolvable to a
 * literal-path node, but IS guarded elsewhere in the same file by `in_array( $expr, SomeClass::
 * someMethod() )` — SomeClass::someMethod()'s own return value (a flat array of string literals,
 * the same shape `functionArrayReturns` already tracks for a wholly different consumer) is the
 * exact vocabulary of values `$expr` can hold. PhpTokenParser can't resolve `SomeClass::someMethod`'s
 * return values itself (routinely a different file entirely) — this is a pending reference for
 * whichever analyzer merges `functionArrayReturns` across every scanned file, at which point every
 * value becomes its own `prefix . value . suffix` literal input at $targetNode, the same as if the
 * call site had written each one out explicitly. Real-world shape (Wordfence):
 * `views/diagnostics/text.php` does `if (!in_array($i['type'], $issueTypes)) { continue; }` right
 * before `wfView::create('scanner/text/issue-' . $i['type'], ...)`, where `$issueTypes =
 * wfIssues::validIssueTypes()` (`lib/wfIssues.php`) returns a flat literal array of ~30 issue-type
 * slugs — every `views/scanner/text/issue-{slug}.php` file was a false-positive `UnusedFile`.
 */
final class PendingInArrayGuardedInput
{
    public function __construct(
        public readonly string $targetNode,
        public readonly string $prefix,
        public readonly string $suffix,
        public readonly string $domainFunctionKey,
    ) {}
}
