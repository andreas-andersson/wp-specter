<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A literal supplied to one exact wrapper parameter node at a call site. The node may represent a
 * normal positional parameter or a literal key inside an array parameter (`$args['name']`), which
 * is why this remains separate from a generic FunctionCall. It is only useful when the merged
 * graph reaches a real include/template sink through a fixed path fragment.
 */
final class LiteralPathInput
{
    public function __construct(
        public readonly string $targetNode,
        public readonly string $literal,
    ) {}
}
