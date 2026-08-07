<?php

declare(strict_types=1);

namespace WpSpecter\Finding;

final class Finding
{
    public function __construct(
        public readonly FindingType $type,
        public readonly string $name,
        public readonly string $file,
        public readonly int $line,
        public readonly FindingCertainty $certainty,
        public readonly ?string $note = null,
    ) {}
}
