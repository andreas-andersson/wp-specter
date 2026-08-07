<?php

declare(strict_types=1);

namespace WpSpecter\Parser;

final class ScopedMethodCall
{
    public function __construct(
        public readonly string $receiverClass,
        public readonly string $method,
    ) {}
}
