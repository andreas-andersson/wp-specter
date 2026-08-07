<?php

declare(strict_types=1);

namespace WpSpecter\Scan;

use WpSpecter\Enum\WpMode;

final class ScanTarget
{
    /**
     * @param list<string>|null $files When set, scan exactly these files instead of recursing
     *   into $path — used for loose mu-plugin files (WP auto-loads .php files placed directly
     *   in mu-plugins/, not per-package subdirectories), so they aren't double-scanned once
     *   here and again as part of a sibling subdirectory target under the same parent.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly ?WpMode $mode,
        public readonly ?array $files = null,
    ) {}
}
