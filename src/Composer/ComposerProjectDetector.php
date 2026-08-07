<?php

declare(strict_types=1);

namespace WpSpecter\Composer;

use WpSpecter\Detector\WpModeDetector;
use WpSpecter\Scan\ScanTarget;
use WpSpecter\Support\PathWalker;

/**
 * Recognizes composer-managed WordPress projects (Bedrock and similar layouts): a project-root
 * composer.json declares where themes/plugins are installed via extra.installer-paths, and
 * composer/installers records what it actually put there in vendor/composer/installed.json.
 *
 * That combination lets us tell "your custom theme" from "a theme composer downloaded from
 * WPackagist" without guessing at folder conventions.
 */
final class ComposerProjectDetector
{
    // WP package types composer/installers writes to vendor/composer/installed.json — code
    // that came from a registry, not the project's own.
    private const VENDOR_WP_TYPES = ['wordpress-theme', 'wordpress-plugin', 'wordpress-muplugin'];

    public function __construct(private readonly WpModeDetector $modeDetector) {}

    /** Walk upward from $path looking for the nearest composer.json. */
    public function findProjectRoot(string $path): ?string
    {
        return PathWalker::findAncestorContaining($path, 'composer.json');
    }

    /**
     * @return list<ScanTarget> custom (non-vendor) theme/plugin directories declared via
     *   composer.json's extra.installer-paths
     */
    public function discoverCustomTargets(string $projectRoot): array
    {
        $composerJson = json_decode((string) file_get_contents($projectRoot . '/composer.json'), true);
        if (!is_array($composerJson)) {
            return [];
        }

        $installerPaths = $composerJson['extra']['installer-paths'] ?? null;
        if (!is_array($installerPaths)) {
            return [];
        }

        $vendorPaths = $this->vendorManagedPaths($projectRoot);

        $targets = [];
        foreach ($installerPaths as $pattern => $rules) {
            if (!is_string($pattern) || !is_array($rules) || !$this->isWpInstallerRule($rules)) {
                continue;
            }

            $baseDir = $this->resolveBaseDir($projectRoot, $pattern);
            if ($baseDir === null || !is_dir($baseDir)) {
                continue;
            }

            foreach ($this->subdirectories($baseDir) as $name => $targetPath) {
                $real = realpath($targetPath) ?: $targetPath;
                if (in_array($real, $vendorPaths, true)) {
                    continue; // installed via composer — not the project's own code
                }

                $targets[] = new ScanTarget($name, $targetPath, $this->modeDetector->detect($targetPath));
            }

            // mu-plugins is the one WP convention where a package can be a single loose .php
            // file dropped directly in the directory (not its own subdirectory) — WP auto-loads
            // every top-level .php file there. Scanned non-recursively and as one pseudo-target
            // so its files aren't also swept up by a sibling subdirectory target above.
            $looseFiles = [];
            foreach ($this->looseFiles($baseDir) as $file) {
                $real = realpath($file) ?: $file;
                if (!in_array($real, $vendorPaths, true)) {
                    $looseFiles[] = $file;
                }
            }
            if (!empty($looseFiles)) {
                $targets[] = new ScanTarget(basename($baseDir), $baseDir, null, $looseFiles);
            }
        }

        return $targets;
    }

    /** @param array<mixed> $rules */
    private function isWpInstallerRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'type:')) {
                $type = substr($rule, strlen('type:'));
                if (in_array($type, self::VENDOR_WP_TYPES, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function resolveBaseDir(string $projectRoot, string $pattern): ?string
    {
        // "public/app/plugins/{$name}/" -> "public/app/plugins"
        $stripped = preg_replace('/\{\$name\}\/?$/', '', $pattern);
        if ($stripped === null) {
            return null;
        }
        $stripped = trim($stripped, '/');
        if ($stripped === '') {
            return null;
        }
        return $projectRoot . '/' . $stripped;
    }

    /** @return array<string,string> directory name => absolute path */
    private function subdirectories(string $dir): array
    {
        $result = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $result[$entry] = $path;
            }
        }
        return $result;
    }

    /** @return list<string> absolute paths of .php files directly inside $dir (non-recursive) */
    private function looseFiles(string $dir): array
    {
        $result = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path) && str_ends_with($entry, '.php')) {
                $result[] = $path;
            }
        }
        return $result;
    }

    /** @return list<string> absolute, realpath-resolved install locations of vendor WP packages */
    private function vendorManagedPaths(string $projectRoot): array
    {
        $installedFile = $projectRoot . '/vendor/composer/installed.json';
        if (!file_exists($installedFile)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($installedFile), true);
        if (!is_array($data)) {
            return [];
        }
        // Composer 2.x wraps packages under a "packages" key; 1.x wrote a flat list.
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        $paths = [];
        $composerDir = $projectRoot . '/vendor/composer';
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $type = $package['type'] ?? '';
            $installPath = $package['install-path'] ?? null;
            if (!in_array($type, self::VENDOR_WP_TYPES, true) || !is_string($installPath)) {
                continue;
            }
            $resolved = realpath($composerDir . '/' . $installPath);
            if ($resolved !== false) {
                $paths[] = $resolved;
            }
        }

        return $paths;
    }
}
