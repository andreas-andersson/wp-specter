<?php

declare(strict_types=1);

namespace WpSpecter\ProjectConfig;

final class ProjectConfig
{
    /**
     * @param string $configDir Directory containing the config file — the base every relative
     *   path in it is resolved against.
     * @param list<string>|null $targets Absolute theme/plugin directories to scan. When set,
     *   this replaces auto-detection entirely.
     * @param list<string>|null $stubsFrom Absolute directories `generate-stubs` scans when run
     *   with no explicit path.
     * @param string|null $stubsPath Absolute path to the project's stubs file, if the config
     *   overrides the default `.wp-specter.stubs.json` convention.
     * @param list<BaselineEntry> $baseline Findings suppressed via `--generate-baseline`.
     * @param list<string> $exclude Directory names/relative paths pruned from every scan (e.g.
     *   "tests"), on top of the always-on vendor/node_modules/.git defaults.
     */
    public function __construct(
        public readonly string $configDir,
        public readonly ?array $targets,
        public readonly ?array $stubsFrom,
        public readonly ?string $stubsPath,
        public readonly array $baseline = [],
        public readonly array $exclude = [],
    ) {}
}
