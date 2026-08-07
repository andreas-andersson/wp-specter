<?php

declare(strict_types=1);

namespace WpSpecter\ProjectConfig;

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

        return new ProjectConfig($dir, $targets, $stubsFrom, $stubsPath);
    }

    /** Walk upward from $path looking for the default `.wp-specter.stubs.json` convention file. */
    public function findDefaultStubsFile(string $path): ?string
    {
        $dir = PathWalker::findAncestorContaining($path, self::STUBS_FILENAME);
        return $dir !== null ? $dir . '/' . self::STUBS_FILENAME : null;
    }

    /** @return list<string>|null */
    private function resolvePathList(mixed $value, string $baseDir): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $resolved = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $resolved[] = $this->resolvePath($entry, $baseDir);
            }
        }
        return $resolved;
    }

    private function resolvePath(string $path, string $baseDir): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $baseDir . '/' . $path;
        return realpath($absolute) ?: rtrim($absolute, '/');
    }
}
