<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class TemplateRef
{
    public function __construct(
        public readonly string $path,       // literal value from the call
        public readonly string $function,   // get_template_part / get_header / etc.
        public readonly int $line,
        public readonly string $file,
    ) {}
}
