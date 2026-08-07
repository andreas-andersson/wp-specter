<?php

declare(strict_types=1);

namespace WpSpecter\Enum;

enum WpMode: string
{
    case Block = 'block';
    case Classic = 'classic';
    case Plugin = 'plugin';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Block => 'Block (FSE)',
            self::Classic => 'Classic theme',
            self::Plugin => 'Plugin',
            self::Hybrid => 'Hybrid (classic + block)',
        };
    }
}
