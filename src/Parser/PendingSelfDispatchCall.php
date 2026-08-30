<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A scoped call (`$this->method('literal')`) whose literal first argument is a candidate for a
 * same-class self-dispatch method-name template — `method()`'s own body does
 * `call_user_func([$this, "{$param}_suffix"])` (or `array($this, ...)`), using its own parameter
 * to build the real target method name. PhpTokenParser can't resolve `method()`'s own dispatch
 * suffix from a single call site alone (the dispatcher and its callers are routinely scattered
 * across the same file, sometimes different files), so every literal-argument call site is left
 * as a pending reference for whichever analyzer merges $selfDispatchSuffixes across every scanned
 * file to resolve afterward: each collected literal gets the dispatcher's own recorded suffix
 * appended, crediting the real target method as used. Real-world shape (Sydney theme,
 * class-sydney-style-book.php): `get_section( $section ) { if ( method_exists( $this,
 * "{$section}_section" ) ) { call_user_func( array( $this, "{$section}_section" ) ); } }`, called
 * 8 times as `$this->get_section('colors')`, `('buttons')`, etc.
 */
final class PendingSelfDispatchCall
{
    public function __construct(
        public readonly string $receiverClass,
        public readonly string $methodName,
        public readonly string $literalArg,
    ) {}
}
