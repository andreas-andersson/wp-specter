<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * A get_template_part()-family call whose sole argument is a bare call to a project-defined
 * helper function — e.g. `get_template_part( ocean_single_post_header_template() )` (OceanWP
 * theme) — rather than a string literal. PhpTokenParser can't resolve $helperFunction's possible
 * return values from a single file alone (the helper is routinely defined in a different file
 * than this call site), so it's left as a pending reference for whichever analyzer merges
 * $functionLiteralReturns across every scanned file to resolve afterward.
 */
final class PendingTemplateHelperCall
{
    public function __construct(
        public readonly string $helperFunction,
        public readonly string $templateFunction,
        public readonly int $line,
        public readonly string $file,
    ) {}
}
