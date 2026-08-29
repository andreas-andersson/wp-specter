<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * `$this->prop->method()` seen inside $ownerClass's own code — unresolved at parse time, since
 * whichever class $prop actually holds might be assigned anywhere else in the class body, in a
 * different method, possibly in a different file's parse (though a single class's own body is
 * always one file in practice). ClassAnalyzer resolves this against every
 * `$this->prop = new ClassName()` sighting collected across the whole scan
 * (ParseResult::$propertyAssignedClasses) after all files are parsed, rather than the parser
 * trying to resolve it inline — order within the class body (property set in one method, read in
 * another declared earlier in the file) shouldn't matter, and a single top-down pass can't
 * guarantee it doesn't.
 */
final class PropertyMethodCall
{
    public function __construct(
        public readonly string $ownerClass,
        public readonly string $property,
        public readonly string $method,
    ) {}
}
