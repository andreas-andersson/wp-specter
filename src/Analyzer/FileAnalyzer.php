<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\PhpTokenParser;

/**
 * Flags PHP files that are never include()/require()'d, never referenced via
 * get_template_part()/get_header()/get_footer()/get_sidebar(), and never referenced from a
 * block.json "render" field — anywhere in the scanned project.
 *
 * Root-level theme/plugin files and files under known template directories are left to
 * TemplateAnalyzer, which already understands the WP template hierarchy. This analyzer covers
 * everything else: inc/, classes/, includes/, admin/, api/, and similar support directories.
 */
final class FileAnalyzer
{
    private const TEMPLATE_DIRS = ['templates', 'template-parts', 'parts'];
    private const BLOCK_JSON_RENDER_KEYS = ['render', 'renderCallback'];
    // Composer autoload sections that map namespaces/paths to a class loader rather than a
    // literal include() — "autoload-dev" covers test-support classes under the same scheme.
    private const COMPOSER_AUTOLOAD_KEYS = ['autoload', 'autoload-dev'];

    /** @var list<string> Absolute dirs (trailing slash) exempted by a psr-4/psr-0/classmap dir entry. */
    private array $autoloadDirs = [];
    /** @var list<string> Absolute files exempted by a classmap file entry or the "files" list. */
    private array $autoloadFiles = [];
    /** @var list<string> Project-relative dirs exempted by a glob()-loop bulk-include pattern. */
    private array $globExemptDirs = [];

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @return list<Finding>
     */
    public function analyze(array $files, string $rootDir): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        $referenced = $this->buildReferencedIndex($parseResults, $rootDir);

        $rootDir = rtrim($rootDir, '/');
        $this->loadComposerAutoloadPaths($rootDir);
        $this->loadGlobExemptDirs($parseResults, $rootDir);
        $findings = [];

        foreach ($files as $file) {
            if (!$this->isCandidate($file, $rootDir)) {
                continue;
            }

            $relative = $this->normalizePath(ltrim(str_replace($rootDir, '', $file), '/'));
            $basename = pathinfo($file, PATHINFO_FILENAME);

            if (
                isset($referenced[$relative])
                || isset($referenced[$basename])
                || $this->isReferencedByPartialMatch($relative, $referenced)
            ) {
                continue;
            }

            $findings[] = new Finding(
                type: FindingType::UnusedFile,
                name: $relative,
                file: $file,
                line: 1,
                certainty: FindingCertainty::Warning,
                note: 'not included, required, or referenced anywhere in scanned directory',
            );
        }

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file);

        return $findings;
    }

    private function isCandidate(string $file, string $rootDir): bool
    {
        if (!str_ends_with($file, '.php')) {
            return false;
        }

        // In a multi-target (composer project) scan, $files spans every target so reference
        // resolution can see across them — but a file that belongs to a *different* target's
        // tree is never a candidate for "unused" here; its own target's analyze() call covers it.
        if (!str_starts_with($file, $rootDir . '/')) {
            return false;
        }

        // Root-level files (functions.php, index.php, single.php, ...) are the WP template
        // hierarchy — TemplateAnalyzer already covers them.
        if (dirname($file) === $rootDir) {
            return false;
        }

        // index.php is used both as a directory-listing blocker ("silence is golden") and as a
        // sibling-file autoloader — neither pattern is a meaningful "unused" signal.
        if (basename($file) === 'index.php') {
            return false;
        }

        // A file only ever loaded by Composer's autoloader (the normal way any class gets
        // loaded in a namespaced OOP plugin/theme with its own composer.json) has no
        // include()/require() call anywhere to find — it's reachable, just not through anything
        // this analyzer's referenced-index can see. Exempted wholesale rather than trying to
        // prove real per-class usage (which would need namespace-aware class resolution
        // PhpTokenParser doesn't have).
        if ($this->isComposerAutoloaded($file)) {
            return false;
        }

        // WP Page Templates are selected from the admin UI via this header comment — WP loads
        // them by scanning the theme, never through a visible include()/require() call.
        if ($this->hasPageTemplateHeader($file)) {
            return false;
        }

        $relative = ltrim(str_replace($rootDir, '', $file), '/');
        foreach (self::TEMPLATE_DIRS as $dir) {
            if (str_starts_with($relative, $dir . '/')) {
                return false;
            }
        }

        // A directory only ever bulk-loaded through `foreach (glob(...) as $f) { require $f; }`
        // has no per-file include()/require() call for the referenced-index to find — the
        // loop variable is fully dynamic. Exempted wholesale, same shape as the Composer
        // autoload exemption above.
        if ($this->isUnderGlobExemptDir($relative)) {
            return false;
        }

        return true;
    }

    private function isUnderGlobExemptDir(string $relative): bool
    {
        foreach ($this->globExemptDirs as $dir) {
            if ($relative === $dir || str_starts_with($relative, $dir . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads $rootDir/composer.json's "autoload"/"autoload-dev" blocks and records every
     * path they map, so isComposerAutoloaded() can exempt those files/dirs from candidacy.
     * A missing or malformed composer.json (no composer.json at all is the common case — most
     * scanned themes/plugins don't declare their own) just leaves both lists empty.
     */
    private function loadComposerAutoloadPaths(string $rootDir): void
    {
        $this->autoloadDirs = [];
        $this->autoloadFiles = [];

        $raw = @file_get_contents($rootDir . '/composer.json');
        $json = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            return;
        }

        foreach (self::COMPOSER_AUTOLOAD_KEYS as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $this->collectAutoloadSection($json[$key], $rootDir);
            }
        }
    }

    /** @param array<string,mixed> $autoload */
    private function collectAutoloadSection(array $autoload, string $rootDir): void
    {
        foreach (['psr-4', 'psr-0'] as $key) {
            if (!isset($autoload[$key]) || !is_array($autoload[$key])) {
                continue;
            }
            foreach ($autoload[$key] as $dirs) {
                foreach (is_array($dirs) ? $dirs : [$dirs] as $dir) {
                    if (!is_string($dir) || $dir === '') {
                        // A root-namespace mapping ("Prefix\\": "") maps the whole project —
                        // too broad to exempt wholesale without defeating this check entirely.
                        continue;
                    }
                    $this->autoloadDirs[] = $this->joinPath($rootDir, $dir) . '/';
                }
            }
        }

        if (isset($autoload['classmap']) && is_array($autoload['classmap'])) {
            foreach ($autoload['classmap'] as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $resolved = $this->joinPath($rootDir, $path);
                if (is_dir($resolved)) {
                    $this->autoloadDirs[] = $resolved . '/';
                } else {
                    $this->autoloadFiles[] = $resolved;
                }
            }
        }

        if (isset($autoload['files']) && is_array($autoload['files'])) {
            foreach ($autoload['files'] as $path) {
                if (is_string($path) && $path !== '') {
                    $this->autoloadFiles[] = $this->joinPath($rootDir, $path);
                }
            }
        }
    }

    private function joinPath(string $rootDir, string $relative): string
    {
        return rtrim($rootDir, '/') . '/' . trim($relative, '/');
    }

    private function isComposerAutoloaded(string $file): bool
    {
        if (in_array($file, $this->autoloadFiles, true)) {
            return true;
        }
        foreach ($this->autoloadDirs as $dir) {
            if (str_starts_with($file, $dir)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<\WpSpecter\Parser\ParseResult> $parseResults
     * $rootDir must already be rtrim'd — matches the convention isCandidate() relies on.
     */
    private function loadGlobExemptDirs(array $parseResults, string $rootDir): void
    {
        $this->globExemptDirs = [];

        foreach ($parseResults as $result) {
            // A glob() call alone isn't enough signal — plenty of WP code globs a directory for
            // reasons that have nothing to do with loading PHP (image galleries, asset lists).
            // Only trust it as a bulk-include when an actual include/require keyword also shows
            // up somewhere in the same file — still coarse (it doesn't prove the glob() result
            // is what gets required), but rules out the unrelated-glob case cheaply.
            if (!$result->hasIncludeStatement || empty($result->globIncludeDirs)) {
                continue;
            }
            $callerRelDir = $this->relativeDir($result->file, $rootDir);
            foreach ($result->globIncludeDirs as $globDir) {
                $exemptDir = trim($this->resolveGlobExemptDir($globDir, $callerRelDir), '/');
                if ($exemptDir !== '') {
                    $this->globExemptDirs[] = $exemptDir;
                }
            }
        }
    }

    /** $rootDir must already be rtrim'd — matches the convention isCandidate() relies on. */
    private function relativeDir(string $file, string $rootDir): string
    {
        $relative = ltrim(str_replace($rootDir, '', $file), '/');
        $dir = dirname($relative);
        return $dir === '.' ? '' : $dir;
    }

    /**
     * A glob() pattern's directory portion is relative to wherever the glob() call itself
     * lives — almost always spelled via `__DIR__`/`dirname(__FILE__)` concatenation, which is
     * exactly what "relative to the calling file" means. An empty/"." directory (glob()'ing
     * `*.php` with no subdirectory at all) means the glob targets the calling file's own
     * directory — i.e. sibling files, the "one bootstrap file globbing its own directory"
     * pattern.
     */
    private function resolveGlobExemptDir(string $globDirLiteral, string $callerRelDir): string
    {
        $globDirLiteral = trim($globDirLiteral, '/');
        if ($globDirLiteral === '' || $globDirLiteral === '.') {
            return $callerRelDir;
        }
        return $callerRelDir === '' ? $globDirLiteral : $callerRelDir . '/' . $globDirLiteral;
    }

    private function hasPageTemplateHeader(string $file): bool
    {
        $content = file_get_contents($file, length: 4096);
        return $content !== false && (bool) preg_match('/Template\s+Name\s*:/i', $content);
    }

    /**
     * @param list<\WpSpecter\Parser\ParseResult> $parseResults
     * @return array<string,bool>
     */
    private function buildReferencedIndex(array $parseResults, string $rootDir): array
    {
        $referenced = [];

        foreach ($parseResults as $result) {
            foreach ($result->templateRefs as $ref) {
                $normalized = $this->normalizePath($ref->path);
                if ($normalized === '') {
                    continue;
                }
                $referenced[$normalized] = true;
                $referenced[basename($normalized)] = true;
                $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
            }
            foreach ($result->phpPathStrings as $path) {
                $normalized = $this->normalizePath($path);
                if ($normalized === '') {
                    continue;
                }
                $referenced[$normalized] = true;
                $referenced[basename($normalized)] = true;
                $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
            }
        }

        foreach ($this->collectBlockJsonRefs($rootDir) as $ref) {
            $normalized = $this->normalizePath($ref);
            if ($normalized === '') {
                continue;
            }
            $referenced[$normalized] = true;
            $referenced[basename($normalized)] = true;
            $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
        }

        return $referenced;
    }

    /** @return list<string> */
    private function collectBlockJsonRefs(string $rootDir): array
    {
        if (!is_dir($rootDir)) {
            return [];
        }

        $refs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getFilename() !== 'block.json') {
                continue;
            }

            $raw = file_get_contents($file->getPathname());
            $json = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($json)) {
                continue;
            }

            foreach (self::BLOCK_JSON_RENDER_KEYS as $key) {
                if (isset($json[$key]) && is_string($json[$key])) {
                    $refs[] = $json[$key];
                }
            }
        }

        return $refs;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        if (str_ends_with($path, '.php')) {
            $path = substr($path, 0, -4);
        }
        return $path;
    }

    /** @param array<string,bool> $referenced */
    private function isReferencedByPartialMatch(string $normalizedPath, array $referenced): bool
    {
        foreach (array_keys($referenced) as $ref) {
            if ($ref !== '' && (
                str_ends_with($normalizedPath, '/' . $ref)
                || str_starts_with($normalizedPath, $ref . '/')
                // get_template_part( 'slug', $dynamic_name ) resolves to "slug-{$name}.php" —
                // the dynamic $name can't be known statically, so treat any "slug-*" file as
                // referenced once "slug" itself is a known get_template_part() call.
                || str_starts_with($normalizedPath, $ref . '-')
                || $normalizedPath === $ref
            )) {
                return true;
            }
        }
        return false;
    }
}
