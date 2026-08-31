<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

/**
 * One bounded link in a wrapper's literal-path flow graph. A node is either a declared parameter,
 * a local value, or a wrapper return value; a sink is a real include/require or WordPress template
 * load. Blocksy builds an options/dynamic-styles filename in one wrapper then hands it to a
 * require helper, while WPForms passes a template slug through several methods before its include.
 * Recording only exact variable/array-key links and named or resolved scoped calls lets the
 * analyzer connect those real shapes without treating every arbitrary function transformation as
 * a path loader. A link that discarded one otherwise-unknown direct variable is traversable only
 * when its matching file-existence guard was recorded in the same wrapper.
 */
final class LiteralPathPropagationLink
{
    /**
     * @param list<string> $fileExistenceGuardKeys Any one of these exact guard keys must have
     *                                             been recorded for this link to be traversable.
     */
    public function __construct(
        public readonly string $fromNode,
        public readonly ?string $toNode = null,
        public readonly string $prefix = '',
        public readonly string $suffix = '',
        public readonly bool $isSink = false,
        public readonly array $fileExistenceGuardKeys = [],
    ) {}
}
