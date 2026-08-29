<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A scoped call (`Foo::bulkLoad('inc')`, `self::bulkLoad('inc')`) with a plain string-literal
 * first argument — real-world shape (Flynt theme): `functions.php` calls
 * `FileLoader::loadPhpFiles('inc')`, and `loadPhpFiles()` itself (a different file,
 * `lib/Utils/FileLoader.php`) walks that directory via `DirectoryIterator` and `require_once`s
 * every PHP file it finds. Neither `glob()`/`scandir()`-based bulk-include detection nor the
 * dynamic-middle-segment `require` detection can see this: there's no `glob()`/`scandir()` call
 * anywhere, and the literal directory name and the `require_once` that consumes it live in two
 * separate files, connected only by an ordinary method call this parser doesn't (and, in
 * general, can't) trace dataflow through.
 *
 * Recorded unconditionally at the call site — every scoped call with a literal first argument
 * becomes a candidate, regardless of what the callee actually does with it. Resolution is
 * deferred to `FileAnalyzer`, once every scanned file's parse is merged: a candidate only turns
 * into a real directory exemption if `$receiverClass::$methodName` resolves to a method whose own
 * `FunctionDef::$hasIncludeInBody` is true — the same "co-occurrence, not proven causality" trade-
 * off the existing `glob()`-loop bulk-include detection already accepts, just spanning two files
 * instead of one.
 */
final class PendingDirectoryLoaderCall
{
    public function __construct(
        public readonly string $receiverClass,
        public readonly string $methodName,
        public readonly string $literalArg,
    ) {}
}
