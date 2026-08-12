<?php

declare(strict_types=1);

namespace WpSpecter;

final class Config
{
    /**
     * @param list<string> $types
     * @param list<string> $ignoreGlobs
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $target,
        public readonly array $types,
        public readonly array $ignoreGlobs,
        public readonly ?string $stubs,
        public readonly bool $verbose,
        public readonly bool $noColor,
        public readonly bool $generateConfig = false,
        public readonly bool $generateBaseline = false,
        public readonly bool $noVendorReflection = false,
    ) {}

    public function wantsType(string $type): bool
    {
        return in_array('all', $this->types, true) || in_array($type, $this->types, true);
    }
}
