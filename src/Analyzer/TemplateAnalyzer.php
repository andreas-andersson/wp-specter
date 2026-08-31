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
    // "resources/views" is Roots Sage/Acorn's Blade views root — Sage's WP hierarchy files
    // (index.blade.php, single.blade.php, ...) and partials both live there instead of at the
    // theme root / template-parts, so it needs the same treatment as the other three.
    private const TEMPLATE_DIRS = ['templates', 'template-parts', 'parts', 'resources/views'];
    // Rector's documented project-level configuration filename. WP Rig carries rector.php at
    // its theme root; it imports RectorConfig and returns tooling configuration, never a
    // WordPress template. Keep this deliberately narrow rather than treating arbitrary root PHP
    // configuration-looking files as non-templates.
    private const ROOT_TOOLING_CONFIG_FILES = ['rector.php'];
    private const BLOCK_JSON_RENDER_KEYS = ['render', 'renderCallback'];

    // Blade directives whose argument(s) name another view, dot-notation (Blade's own path
    // separator — "layouts.app" means resources/views/layouts/app.blade.php). Every literal
    // string found inside the directive's parens is treated as a candidate view name (rather
    // than trying to identify "the" argument precisely) — @includeFirst(['a.b', 'a.c']) and
    // @includeWhen($cond, 'a.b') both need every quoted literal, not just the first.
    private const BLADE_INCLUDE_DIRECTIVES = [
        'extends', 'include', 'includeIf', 'includeWhen', 'includeUnless',
        'includeFirst', 'each', 'component', 'componentFirst',
    ];

    public function __construct(
        private readonly PhpTokenParser $parser,
        private readonly WpModeDetector $modeDetector,
    ) {}

    /**
     * @param list<string> $files
     * @param (callable(int, int): void)|null $onProgress See PhpTokenParser::parseAll().
     * @return list<Finding>
     */
    public function analyze(array $files, ?WpMode $mode, string $themeDir, ?callable $onProgress = null): array
    {
        $parseResults = $this->parser->parseAll($files, $onProgress);

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

        // WPForms' render helper hands its slug through several named/scoped methods before
        // include_html() appends ".php" and reaches load_template()/require. Resolve that same
        // bounded fixed-fragment wrapper graph used by FileAnalyzer, so a template is treated as
        // referenced without a WPForms-specific name/path rule.
        foreach (LiteralPathPropagationResolver::resolve($parseResults) as $path) {
            $normalized = $this->normalizePath($path);
            if ($normalized === '') {
                continue;
            }
            $referenced[$normalized] = true;
            $referenced[basename($normalized)] = true;
            $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
        }

        // get_header( helper_fn() ) / get_template_part( $var ) where `$var = helper_fn();` —
        // helper_fn() is a project-defined function whose own `return` statements resolve to one
        // or more literal paths (see PhpTokenParser's T_RETURN handling). Merged across every
        // scanned file first, since the helper and its caller are routinely in different files —
        // same mechanism FileAnalyzer uses for template refs that land outside TEMPLATE_DIRS.
        $functionLiteralReturns = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionLiteralReturns as $fnName => $literals) {
                if (!isset($functionLiteralReturns[$fnName])) {
                    $functionLiteralReturns[$fnName] = [];
                }
                array_push($functionLiteralReturns[$fnName], ...$literals);
            }
        }
        foreach ($parseResults as $result) {
            foreach ($result->pendingTemplateHelperCalls as $pending) {
                foreach ($functionLiteralReturns[$pending->helperFunction] ?? [] as $literal) {
                    $normalized = $this->normalizePath($this->prefixTemplateHelperPath($literal, $pending->templateFunction));
                    if ($normalized === '') {
                        continue;
                    }
                    $referenced[$normalized] = true;
                    $referenced[basename($normalized)] = true;
                    $referenced[pathinfo($normalized, PATHINFO_FILENAME)] = true;
                }
            }
        }

        // Blade's @extends/@include-family directives and <x-component> tags aren't PHP syntax
        // at all — a .blade.php file is almost entirely inline HTML/text from a tokenizer's
        // point of view, so PhpTokenParser's include-ref detection (which fires on real
        // T_INCLUDE/T_REQUIRE tokens) never sees them. Scanned separately, straight off the raw
        // file content, for every file actually named *.blade.php among $files.
        foreach ($files as $file) {
            if (!str_ends_with($file, '.blade.php')) {
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            foreach ($this->extractBladeReferences($content) as $ref) {
                $normalized = $this->normalizePath($ref);
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
            $basename = $this->templateBasename($templateFile);

            // Exempt WP core hierarchy templates (exact names + pattern variants) — but only for
            // an actual theme scan (classic/hybrid, or mode not yet determined). WP's own
            // locate_template()/template-hierarchy auto-location is a THEME mechanism; a plugin's
            // own bundled "templates/" directory (WooCommerce's WC_Template_Loader and the many
            // similar CPT-plugin conventions it inspired) is never auto-located this way, even
            // when a bundled file's name happens to start with a hierarchy prefix like
            // "taxonomy-"/"single-"/"archive-". Previously gated on "not Block mode", which
            // incorrectly also exempted Plugin mode — confirmed against real WooCommerce
            // templates (taxonomy-product-cat.php, taxonomy-product-tag.php) that have zero
            // literal reference anywhere in the codebase (only reached via a runtime string
            // concatenation in WC_Template_Loader that no static analysis can resolve) and were
            // silently escaping detection purely because of this exemption, not because they're
            // actually reachable. Mirrors collectTemplateFiles()'s own "is this actually a theme
            // scan" condition just below for root-level file collection.
            $isThemeScan = $mode === WpMode::Classic || $mode === WpMode::Hybrid || $mode === null;
            if ($isThemeScan && $this->modeDetector->isHierarchyTemplate($basename)) {
                continue;
            }

            // WP Page Templates are selected from the admin UI by this header comment — WP loads
            // them by scanning the theme, never through a visible include()/require() call, same
            // reasoning as FileAnalyzer::hasPageTemplateHeader(). A custom-named page template
            // (author-chosen name — the whole point of a custom page template) has no hierarchy
            // name to match the check above, so it needs this separate exemption. Works for
            // Blade's `{{-- Template Name: ... --}}` comment syntax too: the regex only cares
            // about the raw text, not which comment syntax wraps it.
            if ($this->hasPageTemplateHeader($templateFile)) {
                continue;
            }

            // Check if referenced
            $relativeToTheme = ltrim(str_replace($themeDir, '', $templateFile), '/');
            $relativePath = $this->normalizePath($relativeToTheme);
            if (
                !isset($referenced[$relativePath])
                && !isset($referenced[$basename])
                && !$this->isReferencedByPartialMatch($relativePath, $referenced)
                // A slug-based loader convention (get_template_part('content', 'product') =>
                // "content-product.php", wc_get_template_part's own identical shape) operates on
                // the FILENAME alone, never a directory-qualified path — WP core's own hierarchy
                // templates happen to sit at the theme root already, so $relativePath and
                // $basename were the same thing there and this never mattered; a plugin's own
                // bundled templates/ directory breaks that coincidence (the real slug "content"
                // never has "templates/" prefixed onto it by the plugin's own convention, but
                // $relativePath always does here) — confirmed real false positive this fixes:
                // WooCommerce's content-product.php/content-single-product.php.
                && !$this->isReferencedByPartialMatch($basename, $referenced)
            ) {
                $findings[] = new Finding(
                    type: FindingType::UnusedTemplate,
                    name: basename($templateFile),
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

            // Always skip the theme's own root-level bootstrap files — matched by their exact
            // root-relative path, not just basename, so a same-named file in a nested template
            // dir (e.g. Sage's resources/views/index.blade.php, a real hierarchy template once
            // its .blade.php is stripped down to "index") is never mistaken for theme root
            // index.php.
            if ($relative === 'functions.php' || $relative === 'index.php') {
                continue;
            }

            if (in_array($relative, self::ROOT_TOOLING_CONFIG_FILES, true)) {
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

    private function hasPageTemplateHeader(string $file): bool
    {
        $content = file_get_contents($file, length: 4096);
        return $content !== false && (bool) preg_match('/Template\s+Name\s*:/i', $content);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        return $path;
    }

    /**
     * Mirrors PhpTokenParser::parseTemplateRef()'s own get_header('x')/get_footer('x')/
     * get_sidebar('x') => header-x/footer-x/sidebar-x prefixing, for a literal resolved via
     * $functionLiteralReturns instead of a direct string argument — the same stem-prefix
     * convention applies regardless of which of the two ways the literal was discovered.
     */
    private function prefixTemplateHelperPath(string $literal, string $templateFunction): string
    {
        $prefix = match ($templateFunction) {
            'get_header' => 'header',
            'get_footer' => 'footer',
            'get_sidebar' => 'sidebar',
            default => null,
        };
        return $prefix !== null && $literal !== '' ? $prefix . '-' . $literal : $literal;
    }

    /**
     * basename($file, '.php') only strips the final ".php" — on "single.blade.php" that leaves
     * "single.blade", which never matches WpModeDetector's hierarchy name list ("single"). Blade
     * views need their double extension stripped as a unit for hierarchy/reference matching to
     * work at all.
     */
    private function templateBasename(string $file): string
    {
        $name = basename($file);
        if (str_ends_with($name, '.blade.php')) {
            return substr($name, 0, -strlen('.blade.php'));
        }
        return basename($file, '.php');
    }

    /**
     * Pulls every candidate view name out of a .blade.php file's Blade directives and anonymous
     * component tags — see BLADE_INCLUDE_DIRECTIVES for why "every quoted literal in the parens"
     * rather than trying to identify a specific argument position. Dot notation is converted to
     * slashes (Blade's own path separator matches this project's directory-relative convention
     * for everything else referenced-index related).
     *
     * @return list<string>
     */
    private function extractBladeReferences(string $content): array
    {
        $refs = [];

        foreach (self::BLADE_INCLUDE_DIRECTIVES as $directive) {
            $needle = '@' . $directive . '(';
            $offset = 0;
            while (($pos = strpos($content, $needle, $offset)) !== false) {
                $openParen = $pos + strlen($needle) - 1;
                $closeParen = $this->findMatchingParen($content, $openParen);
                if ($closeParen === null) {
                    break;
                }

                $args = substr($content, $openParen + 1, $closeParen - $openParen - 1);
                if (preg_match_all('/([\'"])((?:(?!\1).)*)\1/', $args, $matches)) {
                    foreach ($matches[2] as $literal) {
                        $refs[] = str_replace('.', '/', $literal);
                    }
                }

                $offset = $closeParen + 1;
            }
        }

        // Anonymous Blade components: <x-alert ...> => components/alert.blade.php,
        // <x-forms.input ...> => components/forms/input.blade.php.
        if (preg_match_all('/<x[-:]([a-zA-Z0-9_.\-]+)/', $content, $matches)) {
            foreach ($matches[1] as $name) {
                $refs[] = 'components/' . str_replace('.', '/', $name);
            }
        }

        return $refs;
    }

    /**
     * Finds the index of the ')' matching the '(' at $openPos, honoring nested parens. Skips
     * over single/double-quoted string literals while scanning — a literal paren inside a quoted
     * view name (e.g. `@include('partials.header (v2)')`) must not be counted as real nesting,
     * or the scan desyncs and either truncates the args before the real close paren or drifts
     * into the wrong literal boundaries entirely.
     */
    private function findMatchingParen(string $content, int $openPos): ?int
    {
        $depth = 0;
        $length = strlen($content);
        $quote = null;
        for ($i = $openPos; $i < $length; $i++) {
            $ch = $content[$i];

            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++; // Skip the escaped character (e.g. \' inside a '...' literal).
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
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
