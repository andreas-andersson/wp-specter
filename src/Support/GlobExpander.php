<?php

declare(strict_types=1);

namespace WpSpecter\Support;

/**
 * Expands a glob-style directory pattern (e.g. "plugins/custom-*") into the directories it
 * currently matches. Used for scan targets — both the CLI path argument and
 * `.wp-specter.config.json`'s "targets"/"stubsFrom" lists — so a project can declare "every
 * directory matching this prefix" once instead of listing each one and updating it by hand.
 */
final class GlobExpander
{
    public static function containsWildcard(string $path): bool
    {
        return preg_match('/[*?\[\]]/', $path) === 1;
    }

    /** @return list<string> absolute directory paths matching $pattern, sorted for determinism */
    public static function expandDirs(string $pattern): array
    {
        $matches = glob($pattern, GLOB_ONLYDIR) ?: [];
        sort($matches);
        return $matches;
    }

    /**
     * The longest leading path segment of $pattern that contains no wildcard character — a real,
     * existing directory (assuming a valid pattern) suitable for anchoring an upward walk
     * (project-config discovery, composer.json detection) even when the pattern itself matches
     * zero directories.
     */
    public static function baseDir(string $pattern): string
    {
        $segments = explode('/', $pattern);
        $base = [];
        foreach ($segments as $segment) {
            if (self::containsWildcard($segment)) {
                break;
            }
            $base[] = $segment;
        }
        $dir = implode('/', $base);
        return $dir === '' ? '/' : $dir;
    }
}
