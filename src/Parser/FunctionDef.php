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
        // Declaring class's short name, set only when $isMethod. Null for interface/trait/enum
        // bodies and anonymous classes, which have no ClassDef to attribute the method to.
        public readonly ?string $ownerClass = null,
    ) {}
}
