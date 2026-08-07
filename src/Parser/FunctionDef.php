<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class FunctionDef
{
    public function __construct(
        public readonly string $name,
        public readonly int $line,
        public readonly string $file,
        public readonly bool $isMethod = false,
    ) {}
}
