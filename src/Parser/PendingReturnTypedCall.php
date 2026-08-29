<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * `$x = SomeFactory::make(); $x->method();` — unresolved at parse time, since make()'s own
 * declared return type might live in a different file's parse than this call site (or later in
 * the same one). $sourceReceiverClass/$sourceMethod identify the assignment's own call
 * (SomeFactory::make(), null receiver for a bare top-level function, "$this"'s owner class for
 * $this->make()); $readMethod is the method actually called on the variable afterward.
 * ClassAnalyzer resolves this once every file's parse is merged, by looking up
 * $sourceMethod's declared return type and, if it names a class, crediting that class with a
 * call to $readMethod — the same deferred-resolution shape PropertyMethodCall already uses for
 * property types, for the same reason: order within or across files shouldn't matter.
 */
final class PendingReturnTypedCall
{
    public function __construct(
        public readonly ?string $sourceReceiverClass,
        public readonly string $sourceMethod,
        public readonly string $readMethod,
    ) {}
}
