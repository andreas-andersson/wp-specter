<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class TraitUsage
{
    /**
     * @param string $user  Short name of the class/trait whose body contains the `use`.
     * @param string $trait Short name of the trait being used.
     */
    public function __construct(
        public readonly string $user,
        public readonly string $trait,
    ) {}
}
