<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A method-name *prefix* resolved to a concrete receiver class, from a callback whose full name
 * is only known at runtime — `array($this, 'footer_html_' . $index)` inside a loop. $prefix is
 * the literal portion before the concatenation; any method on $receiverClass whose name starts
 * with it is credited as used, since the token parser can't evaluate $index.
 */
final class ScopedMethodCallPrefix
{
    public function __construct(
        public readonly string $receiverClass,
        public readonly string $prefix,
        public readonly string $suffix = '',
    ) {}
}
