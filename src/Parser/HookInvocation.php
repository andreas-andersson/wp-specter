<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class HookInvocation
{
    public function __construct(
        public readonly string $tag,
        public readonly string $function,   // 'do_action' or 'apply_filters'
        public readonly int $line,
        public readonly string $file,
        public readonly bool $isDynamic,
        // Equals $tag when the tag is fully literal. When $isDynamic, the leading literal
        // segment of an interpolated string or a concatenation starting with a literal (e.g.
        // "acf/settings/{$name}" → "acf/settings/") — empty when nothing literal was resolvable.
        public readonly string $tagPrefix = '',
        // The mirror of $tagPrefix for the opposite shape — dynamic first, literal last (e.g.
        // "{$this->id_base}_widget_updated" → "_widget_updated") — empty when nothing literal
        // was resolvable, or when $tagPrefix already was (this parser doesn't try to extract
        // both from the same tag; see PhpTokenParser::classifyArgTokens).
        public readonly string $tagSuffix = '',
    ) {}
}
