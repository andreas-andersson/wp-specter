<?php

declare(strict_types=1);

namespace WpSpecter\ProjectConfig;

/**
 * Writes `.wp-specter.config.json` for `--generate-config` and `--generate-baseline`. Always
 * read-merge-write: touches only the key it's asked to update, preserving whatever else is
 * already declared in the file (targets/stubsFrom/stubs/baseline), and writes keys back out in
 * a fixed order so re-running either command produces a predictable, reviewable diff.
 */
final class ProjectConfigWriter
{
    public function exists(string $configDir): bool
    {
        return is_file($this->file($configDir));
    }

    /** @param list<string> $absoluteTargetPaths */
    public function writeTargets(string $configDir, array $absoluteTargetPaths): void
    {
        $data = $this->readRaw($configDir);
        $data['targets'] = array_map(
            fn(string $path) => BaselineEntry::relativize($path, $configDir),
            $absoluteTargetPaths,
        );
        $this->writeRaw($configDir, $data);
    }

    /** @param list<BaselineEntry> $entries */
    public function writeBaseline(string $configDir, array $entries): void
    {
        $data = $this->readRaw($configDir);
        $data['baseline'] = array_map(
            fn(BaselineEntry $e) => ['type' => $e->type, 'name' => $e->name, 'file' => $e->file],
            $entries,
        );
        $this->writeRaw($configDir, $data);
    }

    /** @return array<string,mixed> */
    private function readRaw(string $configDir): array
    {
        $file = $this->file($configDir);
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $data */
    private function writeRaw(string $configDir, array $data): void
    {
        $ordered = [];
        foreach (['targets', 'stubsFrom', 'stubs', 'exclude', 'baseline'] as $key) {
            if (array_key_exists($key, $data)) {
                $ordered[$key] = $data[$key];
            }
        }

        $encoded = json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($this->file($configDir), $encoded . PHP_EOL) === false) {
            throw new \RuntimeException('Cannot write ' . $this->file($configDir));
        }
    }

    private function file(string $configDir): string
    {
        return rtrim($configDir, '/') . '/' . ProjectConfigLoader::CONFIG_FILENAME;
    }
}
