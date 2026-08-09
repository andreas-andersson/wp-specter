<?php

declare(strict_types=1);

namespace WpSpecter\Scanner;

final class FileScanner
{
    private const DEFAULT_EXCLUDES = ['vendor', 'node_modules', '.git'];

    /**
     * @param list<string> $ignoreGlobs Glob patterns to exclude
     * @param list<string> $excludeDirs Additional directory names/relative paths to prune,
     *   on top of the always-on vendor/node_modules/.git defaults
     */
    public function scan(string $dir, array $ignoreGlobs = [], array $excludeDirs = []): ScanResult
    {
        if (!is_dir($dir)) {
            return new ScanResult([], 'Directory not found: ' . $dir);
        }

        $files = [];
        $this->collectPhpFiles($dir, $files, [...self::DEFAULT_EXCLUDES, ...$excludeDirs], $ignoreGlobs, $dir);

        sort($files);
        return new ScanResult($files, null);
    }

    /**
     * @param list<string> $files
     * @param list<string> $excludes
     * @param list<string> $ignoreGlobs
     */
    private function collectPhpFiles(
        string $dir,
        array &$files,
        array $excludes,
        array $ignoreGlobs,
        string $root,
    ): void {
        $dirIterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);

        $filtered = new \RecursiveCallbackFilterIterator(
            $dirIterator,
            function (\SplFileInfo $current) use ($excludes, $root): bool {
                if ($current->isDir() && $this->shouldExcludeDir($current->getPathname(), $excludes, $root)) {
                    return false;
                }
                return true;
            },
        );

        $iterator = new \RecursiveIteratorIterator($filtered);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if ($this->matchesGlob($path, $ignoreGlobs)) {
                continue;
            }

            $files[] = $path;
        }
    }

    /** @param list<string> $excludes */
    private function shouldExcludeDir(string $path, array $excludes, string $root): bool
    {
        $relative = ltrim(str_replace($root, '', $path), '/');
        foreach ($excludes as $exclude) {
            $exclude = trim($exclude, '/');
            if ($relative === $exclude || str_starts_with($relative, $exclude . '/')) {
                return true;
            }
            // Also match by directory basename
            if (basename($path) === $exclude) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $globs */
    private function matchesGlob(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $path) || fnmatch($glob, basename($path))) {
                return true;
            }
        }
        return false;
    }
}
