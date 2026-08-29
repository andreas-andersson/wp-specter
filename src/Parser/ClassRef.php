<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A captured extends/implements/trait-use target, resolved two ways at once: `$short` (exactly
 * what shortClassName() would produce today) for matching against WP-core/vendor curated tables
 * (BASE_CLASS_CONTRACT_METHODS and friends, always keyed by bare short name since WP core is
 * always global-namespace) and VendorClassReflector, and `$fqcn` (resolved against the
 * declaring file's own namespace + use-imports) for matching another project ClassDef exactly,
 * so two unrelated classes sharing a short name across different namespaces no longer collide.
 * For a class with no namespace declaration at all, $fqcn === $short — see PhpTokenParser's
 * resolveFqcn().
 */
final class ClassRef
{
    public function __construct(
        public readonly string $short,
        public readonly string $fqcn,
    ) {}
}
