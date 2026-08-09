<?php

declare(strict_types=1);

namespace WpSpecter\ProjectConfig;

use WpSpecter\Support\GlobExpander;
use WpSpecter\Support\PathWalker;

/**
 * Reads `.wp-specter.config.json` — a project-level file for declaring exactly which
 * theme/plugin directories to scan and where `generate-stubs` should look, so you don't have to
 * repeat `--target`-equivalent paths on every invocation. Discovered by walking upward from the
 * given path, same as composer.json.
 */
final class ProjectConfigLoader
{
    public const CONFIG_FILENAME = '.wp-specter.config.json';
    public const STUBS_FILENAME = '.wp-specter.stubs.json';

    /** @throws \RuntimeException on malformed JSON */
    public function load(string $path): ?ProjectConfig
    {
        $dir = PathWalker::findAncestorContaining($path, self::CONFIG_FILENAME);
        if ($dir === null) {
            return null;
        }

        $file = $dir . '/' . self::CONFIG_FILENAME;
        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid {$file} (expected a JSON object)");
        }

        $targets = $this->resolvePathList($data['targets'] ?? null, $dir);
        $stubsFrom = $this->resolvePathList($data['stubsFrom'] ?? null, $dir);
        $stubsPath = isset($data['stubs']) && is_string($data['stubs']) && $data['stubs'] !== ''
            ? $this->resolvePath($data['stubs'], $dir)
            : null;
        $baseline = $this->parseBaseline($data['baseline'] ?? null);
        $exclude = $this->parseExclude($data['exclude'] ?? null);

        return new ProjectConfig($dir, $targets, $stubsFrom, $stubsPath, $baseline, $exclude);
    }

    /** Walk upward from $path looking for the default `.wp-specter.stubs.json` convention file. */
    public function findDefaultStubsFile(string $path): ?string
    {
        $dir = PathWalker::findAncestorContaining($path, self::STUBS_FILENAME);
        return $dir !== null ? $dir . '/' . self::STUBS_FILENAME : null;
    }

    /**
     * A "targets"/"stubsFrom" entry may be a glob pattern (e.g. "plugins/custom-*") — expanded
     * fresh on every load, so a new directory matching an already-declared pattern is picked up
     * automatically with no config change needed. A pattern matching nothing contributes no
     * entries; that's a normal state (e.g. no custom plugins yet), not an error.
     *
     * @return list<string>|null
     */
    private function resolvePathList(mixed $value, string $baseDir): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $resolved = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $absolute = str_starts_with($entry, '/') ? $entry : $baseDir . '/' . $entry;
            if (GlobExpander::containsWildcard($entry)) {
                array_push($resolved, ...GlobExpander::expandDirs($absolute));
                continue;
            }
            $resolved[] = realpath($absolute) ?: rtrim($absolute, '/');
        }
        return $resolved;
    }

    private function resolvePath(string $path, string $baseDir): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $baseDir . '/' . $path;
        return realpath($absolute) ?: rtrim($absolute, '/');
    }

    /**
     * "exclude" entries are directory names or paths relative to whatever directory they end up
     * nested under during a scan (e.g. "tests" or "vendor/some-lib") — not resolved against
     * $configDir, since the same entry must match the same-named directory under every scan
     * target, not just one anchored at the config file's location.
     *
     * @return list<string>
     */
    private function parseExclude(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $entries = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry, '/') !== '') {
                $entries[] = trim($entry, '/');
            }
        }
        return $entries;
    }

    /** @return list<BaselineEntry> */
    private function parseBaseline(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $entries = [];
        foreach ($value as $entry) {
            if (
                is_array($entry)
                && isset($entry['type'], $entry['name'], $entry['file'])
                && is_string($entry['type']) && is_string($entry['name']) && is_string($entry['file'])
            ) {
                $entries[] = new BaselineEntry($entry['type'], $entry['name'], $entry['file']);
            }
        }
        return $entries;
    }
}
