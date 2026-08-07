<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Detector\WpModeDetector;
use WpSpecter\Enum\WpMode;
use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\PhpTokenParser;

final class TemplateAnalyzer
{
    private const TEMPLATE_DIRS = ['templates', 'template-parts', 'parts'];
    private const BLOCK_JSON_RENDER_KEYS = ['render', 'renderCallback'];

    public function __construct(
        private readonly PhpTokenParser $parser,
        private readonly WpModeDetector $modeDetector,
    ) {}

    /**
     * @param list<string> $files
     * @return list<Finding>
     */
    public function analyze(array $files, ?WpMode $mode, string $themeDir): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        // Collect all template references (get_template_part, include, etc.)
        $referenced = [];
        foreach ($parseResults as $result) {
            foreach ($result->templateRefs as $ref) {
                // Normalize: strip .php extension, leading slash, and trailing slash
                $normalized = $this->normalizePath($ref->path);
                if ($normalized !== '') {
                    $referenced[$normalized] = true;
                }
                // Also index by basename
                $referenced[basename($normalized)] = true;
                // And by filename without extension
                $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
            }
            // Any ".php"-suffixed literal is a plausible file reference — e.g. ACF's
            // 'render_template' => get_template_directory() . '/blocks/foo.php', which never
            // passes through get_template_part()/include().
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

        // In block mode, also parse block.json render fields
        if ($mode === WpMode::Block || $mode === WpMode::Hybrid) {
            foreach ($this->collectBlockJsonRefs($themeDir) as $ref) {
                $normalized = $this->normalizePath($ref);
                $referenced[$normalized] = true;
                $referenced[basename($normalized)] = true;
                $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
            }
        }

        // Collect template files
        $templateFiles = $this->collectTemplateFiles($files, $themeDir, $mode);

        $findings = [];
        foreach ($templateFiles as $templateFile) {
            $basename = basename($templateFile, '.php');

            // Exempt WP core hierarchy templates (exact names + pattern variants) in classic/hybrid mode
            if ($mode !== WpMode::Block && $this->modeDetector->isHierarchyTemplate($basename)) {
                continue;
            }

            // Check if referenced
            $relativeToTheme = ltrim(str_replace($themeDir, '', $templateFile), '/');
            $relativePath = $this->normalizePath($relativeToTheme);
            if (
                !isset($referenced[$relativePath])
                && !isset($referenced[$basename])
                && !$this->isReferencedByPartialMatch($relativePath, $referenced)
            ) {
                $findings[] = new Finding(
                    type: FindingType::UnusedTemplate,
                    name: $relativePath ?: basename($templateFile),
                    file: $templateFile,
                    line: 1,
                    certainty: FindingCertainty::Error,
                );
            }
        }

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file);

        return $findings;
    }

    /**
     * @param list<string> $allFiles
     * @return list<string>
     */
    private function collectTemplateFiles(array $allFiles, string $themeDir, ?WpMode $mode): array
    {
        $templateFiles = [];

        $themeDir = rtrim($themeDir, '/');

        foreach ($allFiles as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            // In a multi-target (composer project) scan, $allFiles spans every target so
            // reference resolution can see across them — but a file belonging to a *different*
            // target's tree is never a template candidate here; its own target's analyze() call
            // covers it.
            if (!str_starts_with($file, $themeDir . '/')) {
                continue;
            }

            $relative = ltrim(str_replace($themeDir, '', $file), '/');

            // Always skip non-template files
            $basename = basename($file, '.php');
            if (in_array($basename, ['functions', 'index'], true)) {
                continue;
            }

            // Check if in a template directory
            foreach (self::TEMPLATE_DIRS as $dir) {
                if (str_starts_with($relative, $dir . '/')) {
                    $templateFiles[] = $file;
                    continue 2;
                }
            }

            // Root-level .php files in theme dir — include if classic/hybrid mode
            if ($mode === WpMode::Classic || $mode === WpMode::Hybrid || $mode === null) {
                if (dirname($file) === $themeDir) {
                    $templateFiles[] = $file;
                }
            }
        }

        return $templateFiles;
    }

    /** @return list<string> */
    private function collectBlockJsonRefs(string $themeDir): array
    {
        if (!is_dir($themeDir)) {
            return [];
        }

        $refs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS),
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
        // Strip .php extension for comparison
        if (str_ends_with($path, '.php')) {
            $path = substr($path, 0, -4);
        }
        return $path;
    }

    /** @param array<string,bool> $referenced */
    private function isReferencedByPartialMatch(string $normalizedPath, array $referenced): bool
    {
        // get_template_part('parts/hero') matches files like parts/hero.php or parts/hero-home.php
        foreach (array_keys($referenced) as $ref) {
            if ($ref !== '' && (
                str_ends_with($normalizedPath, '/' . $ref)
                || str_starts_with($normalizedPath, $ref . '/')
                // get_template_part( 'slug', $name ) resolves to "slug-{$name}.php" — $name
                // can be a literal (get_template_part('content', 'search')) or dynamic
                // (get_template_part('content', get_post_format())); either way, any
                // "slug-*" file is reachable once "slug" itself is a known ref.
                || str_starts_with($normalizedPath, $ref . '-')
                || $normalizedPath === $ref
            )) {
                return true;
            }
        }
        return false;
    }
}
