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
        // A declared `: ReturnType` resolved to a concrete class name — only when it's a single,
        // unambiguous type (self/parent/static resolved against $ownerClass/its parent; a
        // union/intersection type, or any primitive/void/never/mixed type, is null instead of a
        // guess). Lets `$x = SomeFactory::make(); $x->method();` resolve $x's type from make()'s
        // own signature the same way a local `new ClassName()` assignment already does — see
        // PhpTokenParser's parseReturnTypeHint and ClassAnalyzer's PendingReturnTypedCall
        // resolution.
        public readonly ?string $returnType = null,
    ) {}
}
