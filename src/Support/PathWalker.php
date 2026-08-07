<?php

declare(strict_types=1);

namespace WpSpecter\Support;

final class PathWalker
{
    /** Walk upward from $path (inclusive) looking for the nearest ancestor containing $filename. */
    public static function findAncestorContaining(string $path, string $filename): ?string
    {
        $dir = is_dir($path) ? rtrim($path, '/') : dirname($path);

        while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR) {
            if (file_exists($dir . '/' . $filename)) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }
}
