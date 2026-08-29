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
        // True when this exact declaration sits directly inside its own matching
        // `if ( ! function_exists( '<same name>' ) ) { ... }` guard — the real WP polyfill
        // convention (a function only meant to exist if WP core/another plugin hasn't already
        // declared it, so it's never callable from *this* project's own code). See
        // FunctionAnalyzer::isExcluded()'s own docblock for why this replaced a blanket
        // name-prefix exclusion.
        public readonly bool $guarded = false,
        // $name resolved against the file's own `namespace X;` declaration (empty namespace ⇒
        // $fqcn === $name, the common un-namespaced case). Unlike a class reference, a function
        // *declaration* has no resolution ambiguity of its own — only a bare, unqualified *call*
        // to it does, since PHP falls back to the global namespace for an unqualified call it
        // can't find locally (see ParseResult::$functionCalls' own $namespaceFallbackFqcn field
        // and FunctionAnalyzer's own docblock for why calls and declarations need different
        // treatment here).
        public readonly string $fqcn = '',
        // True when an include/require-family keyword appears anywhere in this function/
        // method's own body, including nested closures (but not further-nested named
        // methods/functions — each gets its own independent flag). Consulted by FileAnalyzer via
        // PendingDirectoryLoaderCall to recognize a bulk-directory-loader method called from a
        // different file with a literal directory-name argument — see that class's own docblock.
        public readonly bool $hasIncludeInBody = false,
    ) {}
}
