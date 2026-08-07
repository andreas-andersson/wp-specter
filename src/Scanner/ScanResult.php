<?php

declare(strict_types=1);

namespace WpSpecter\Scanner;

final class ScanResult
{
    /** @param list<string> $files */
    public function __construct(
        public readonly array $files,
        public readonly ?string $error,
    ) {}

    public function count(): int
    {
        return count($this->files);
    }
}
