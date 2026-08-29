<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class ClassDef
{
    /**
     * @param list<ClassRef> $extends     Base-class ref(s) from `extends` — normally at most one
     *                                    for a class, but interfaces can extend several.
     * @param list<ClassRef> $implements  Interface ref(s) from `implements`.
     * @param 'class'|'interface'|'trait'|'enum' $kind
     */
    public function __construct(
        public readonly string $name,
        public readonly string $fqcn,
        public readonly int $line,
        public readonly string $file,
        public readonly array $extends = [],
        public readonly array $implements = [],
        public readonly string $kind = 'class',
    ) {}
}
