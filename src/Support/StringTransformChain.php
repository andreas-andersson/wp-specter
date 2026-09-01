<?php

declare(strict_types=1);

namespace WpSpecter\Support;

/**
 * Replays the small, fixed set of transform steps PhpTokenParser::resolveTransformChainExpr()
 * recognizes (`str_replace('lit', 'lit', ...)` / `ucfirst(...)` / `ucwords(...)`) against a real
 * candidate string, in application order. Shared by ClassAnalyzer and FileAnalyzer so a class
 * name synthesized this way (see $classNameTransformTemplates' own docblock on ParseResult)
 * resolves identically for both the unused-class and unused-file checks.
 */
final class StringTransformChain
{
    /**
     * @param list<array{string,list<string>}> $steps
     */
    public static function apply(string $value, array $steps): string
    {
        foreach ($steps as [$name, $args]) {
            $value = match ($name) {
                'str_replace' => str_replace($args[0], $args[1], $value),
                'ucfirst' => ucfirst($value),
                'ucwords' => ucwords($value),
                default => $value,
            };
        }
        return $value;
    }
}
