<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class HookRegistration
{
    public function __construct(
        public readonly string $tag,
        public readonly string $function,   // 'add_action' or 'add_filter'
        public readonly int $line,
        public readonly string $file,
        public readonly bool $isDynamic,    // true when tag is a variable/expression
    ) {}
}
