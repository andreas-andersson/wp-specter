<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class ClassDef
{
    /**
     * @param list<string> $extends     Short base-class name(s) from `extends` — normally at
     *                                  most one for a class, but interfaces can extend several.
     * @param list<string> $implements  Short interface name(s) from `implements`.
     * @param 'class'|'interface'|'trait'|'enum' $kind
     */
    public function __construct(
        public readonly string $name,
        public readonly int $line,
        public readonly string $file,
        public readonly array $extends = [],
        public readonly array $implements = [],
        public readonly string $kind = 'class',
    ) {}
}
