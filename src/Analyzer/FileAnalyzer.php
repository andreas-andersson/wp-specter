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

        return true;
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

            $json = json_decode(file_get_contents($file->getPathname()), true);
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
