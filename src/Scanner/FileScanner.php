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

    /**
     * Every PHP file living under a vendor-prefixed directory (vendor/, vendor_prefixed/,
     * jetpack_vendor/, ...) anywhere in the tree — the exact files scan() excludes from every
     * other check. Used only for VendorHookInvocationScanner's cross-boundary hook-invocation
     * scan (see HookAnalyzer::analyze()'s own docblock): auditing a dependency's own internal
     * dead code stays out of scope, but a bundled dependency firing a hook the host project
     * listens to is a normal WordPress pattern this scan needs to see.
     *
     * A deliberately separate walk from scan() rather than a shared one bucketing files two
     * ways: scan()'s own directory-pruning (`RecursiveCallbackFilterIterator` returning `false`)
     * means an excluded directory's contents are never even visited, so there's nothing to
     * bucket from that same walk without restructuring it — simpler and lower-risk to walk the
     * (much smaller, filesystem-only) directory tree once more here than to touch scan()'s own
     * exclusion logic for a second, unrelated purpose.
     *
     * @return list<string>
     */
    public function scanVendorPrefixedFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $dirIterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $dirIterator,
            fn(\SplFileInfo $current): bool => !$current->isDir() || !in_array($current->getFilename(), ['.git', 'node_modules'], true),
        );
        $iterator = new \RecursiveIteratorIterator($filtered);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $relativeDir = ltrim(str_replace($dir, '', dirname($path)), '/');
            foreach ($relativeDir === '' ? [] : explode('/', $relativeDir) as $segment) {
                if ($this->isVendorPrefixedDirName($segment)) {
                    $files[] = $path;
                    break;
                }
            }
        }

        return $files;
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
        return $this->isVendorPrefixedDirName(basename($path));
    }

    /**
     * A literal "vendor" directory name isn't the only real shape third-party dependencies show
     * up under: php-scoper/Mozart/Strauss-style dependency-prefixing relocates a package's code
     * into the host project's own tree under an author-chosen directory name, always with a
     * "vendor" segment somewhere in it by convention — confirmed in the real-world 7-plugin test
     * corpus under three different spellings: `vendor_prefixed` (Elementor, wp-smushit),
     * `vendor-prefixed` (WooCommerce), and `jetpack_vendor` (Jetpack's own separately-published
     * packages). Before this check, none of these were excluded, and the *entire* output of a
     * function/hook scan against Elementor or wp-smushit was 100% noise from bundled Twig/
     * Symfony-polyfill/Guzzle code — not a single genuine finding in either. Matches "vendor" as
     * a whole `_`/`-`-delimited segment (not a bare substring) so a real project directory like
     * "vendors" (plural, unrelated) or "my-vendors-page" isn't swept up by accident.
     */
    private function isVendorPrefixedDirName(string $basename): bool
    {
        return in_array('vendor', preg_split('/[_-]/', strtolower($basename)) ?: [], true);
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
