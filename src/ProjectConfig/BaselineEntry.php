<?php

declare(strict_types=1);

namespace WpSpecter\ProjectConfig;

use WpSpecter\Finding\Finding;

/**
 * One suppressed finding recorded in `.wp-specter.config.json`'s "baseline" key. Deliberately
 * excludes the line number — an unrelated edit above the flagged spot would otherwise silently
 * break the match, same tradeoff phpstan's own baseline makes.
 */
final class BaselineEntry
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $file,
    ) {}

    public function matches(Finding $finding, string $configDir): bool
    {
        return $this->type === $finding->type->value
            && $this->name === $finding->name
            && $this->file === self::relativize($finding->file, $configDir);
    }

    public static function relativize(string $absolutePath, string $configDir): string
    {
        $prefix = rtrim($configDir, '/') . '/';
        return str_starts_with($absolutePath, $prefix) ? substr($absolutePath, strlen($prefix)) : $absolutePath;
    }
}
