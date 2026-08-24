<?php

declare(strict_types=1);

namespace WpSpecter\Detector;

use WpSpecter\Enum\WpMode;

final class WpModeDetector
{
    private const WP_HIERARCHY_TEMPLATES = [
        'index', 'front-page', 'home', 'singular', 'single', 'page',
        'archive', 'author', 'category', 'tag', 'taxonomy', 'date',
        'search', 'searchform', 'attachment', 'image', '404', 'privacy-policy',
        'functions', 'header', 'footer', 'sidebar', 'comments', 'embed',
        // Not on the public template-hierarchy doc page (a separate, newer WP core mechanism —
        // the fatal-error/maintenance-mode protection added in 5.2, wp-includes/class-wp-fatal-
        // error-handler.php and the core-update routine) but auto-located by WP core via
        // locate_template() the exact same way, never referenced from theme code. Confirmed in
        // the wild: Kadence ships both, unreferenced anywhere in its own code.
        '500', 'offline',
    ];

    // Prefixes whose variants (e.g. single-cpt, page-slug) are always WP hierarchy
    private const WP_HIERARCHY_PREFIXES = [
        'single-', 'archive-', 'page-', 'taxonomy-', 'category-', 'tag-', 'author-',
        'header-', 'footer-', 'sidebar-', 'embed-',
    ];

    public function detect(string $dir): ?WpMode
    {
        $hasThemeJson = file_exists($dir . '/theme.json');
        $hasFunctions = file_exists($dir . '/functions.php');
        $hasBlockJson = $this->hasBlockJson($dir);
        $isPlugin = $this->hasPluginHeader($dir);
        $isClassicTheme = $this->hasClassicThemeHeader($dir);

        if ($isPlugin) {
            return WpMode::Plugin;
        }

        if ($hasThemeJson && $hasFunctions) {
            return WpMode::Hybrid;
        }

        if ($hasThemeJson || $hasBlockJson) {
            return WpMode::Block;
        }

        if ($isClassicTheme) {
            return WpMode::Classic;
        }

        return null;
    }

    /** @return list<string> WP hierarchy template basenames to treat as always-used */
    public function hierarchyTemplates(): array
    {
        return self::WP_HIERARCHY_TEMPLATES;
    }

    public function isHierarchyTemplate(string $basename): bool
    {
        if (in_array($basename, self::WP_HIERARCHY_TEMPLATES, true)) {
            return true;
        }
        foreach (self::WP_HIERARCHY_PREFIXES as $prefix) {
            if (str_starts_with($basename, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function hasBlockJson(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getFilename() === 'block.json') {
                return true;
            }
        }

        return false;
    }

    private function hasPluginHeader(string $dir): bool
    {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $content = file_get_contents($file, length: 8192);
            if ($content !== false && str_contains($content, 'Plugin Name:')) {
                return true;
            }
        }
        return false;
    }

    private function hasClassicThemeHeader(string $dir): bool
    {
        $styleFile = $dir . '/style.css';
        if (!file_exists($styleFile)) {
            return false;
        }
        $content = file_get_contents($styleFile, length: 4096);
        return $content !== false && str_contains($content, 'Theme Name:');
    }
}
