<?php

declare(strict_types=1);

namespace WpSpecter\Scan;

final class ProjectInfo
{
    public function __construct(
        public readonly string $root,
        public readonly string $sourceLabel,
        public readonly string $targetsNote,
    ) {}
}
