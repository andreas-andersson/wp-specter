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
    ) {}
}
