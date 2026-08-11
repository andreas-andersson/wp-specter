<?php

declare(strict_types=1);

namespace WpSpecter;

use WpSpecter\Analyzer\ClassAnalyzer;
use WpSpecter\Analyzer\FileAnalyzer;
use WpSpecter\Analyzer\FunctionAnalyzer;
use WpSpecter\Analyzer\HookAnalyzer;
use WpSpecter\Analyzer\TemplateAnalyzer;
use WpSpecter\Composer\ComposerProjectDetector;
use WpSpecter\Detector\WpModeDetector;
use WpSpecter\Enum\WpMode;
use WpSpecter\Finding\Finding;
use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\ProjectConfig\BaselineEntry;
use WpSpecter\ProjectConfig\ProjectConfig;
use WpSpecter\ProjectConfig\ProjectConfigLoader;
use WpSpecter\ProjectConfig\ProjectConfigWriter;
use WpSpecter\Reporter\TerminalReporter;
use WpSpecter\Scan\ProjectInfo;
use WpSpecter\Scan\ScanTarget;
use WpSpecter\Scanner\FileScanner;
use WpSpecter\Stubs\StubRegistry;
use WpSpecter\Support\GlobExpander;

class Application
{
    private const VERSION = '0.4.2';

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        array_shift($argv); // drop script name

        $subcommand = array_shift($argv) ?? 'help';

        return match ($subcommand) {
            'help', '--help', '-h' => $this->runHelp(),
            'version', '--version', '-V' => $this->runVersion(),
            'scan' => $this->runScan($argv),
            'generate-stubs' => $this->runGenerateStubs($argv),
            default => $this->error("Unknown command \"{$subcommand}\". Run `wp-specter help` for usage."),
        };
    }

    private function runHelp(): int
    {
        echo <<<HELP
        wp-specter — Find unused code in WordPress projects

        Usage:
          wp-specter scan <path> [options]
          wp-specter generate-stubs <path> [--output=<file>]
          wp-specter help
          wp-specter version

        Arguments:
          path                   Path to a theme or plugin directory (default: current directory).
                                  May be a glob pattern (e.g. "plugins/custom-*", quoted so your
                                  shell doesn't expand it first) to scan every matching directory.

        Scan options:
          --target=<target>      What to scan: theme or plugin (default: auto-detect)
          --type=<types>         Comma-separated: functions,hooks,templates,files,classes (default: all)
          --stubs=<file>         JSON stubs file to suppress known hooks (see generate-stubs)
          --ignore=<globs>       Comma-separated glob patterns to exclude
          --verbose              Show matched references alongside findings
          --no-color             Disable ANSI color output
          --generate-config      Write resolved scan targets to .wp-specter.config.json and exit.
                                  Written to the composer project root if detected, else to the
                                  current directory — run this from your project root, not from
                                  inside the scanned theme/plugin directory.
          --generate-baseline    Save current findings as suppressions in .wp-specter.config.json
                                  and exit (requires --generate-config to have run first)

        Generate-stubs options:
          --output=<file>        Output path for the stubs file (default: .wp-specter.stubs.json)
          (with no <path>, uses "stubsFrom" from .wp-specter.config.json if present)

        Project files (auto-discovered by walking upward from <path>):
          .wp-specter.config.json   "targets" (dirs to scan) and "stubsFrom" (see above) — either
                                    may include glob patterns (e.g. "plugins/custom-*"), expanded
                                    fresh on every run — "exclude" (directory names/relative paths
                                    pruned from every scan, e.g. ["tests", "vendor"]) — and
                                    "baseline" (findings suppressed via --generate-baseline)
          .wp-specter.stubs.json    auto-loaded by scan, same as passing --stubs=

        Exit codes:
          0  No unused items found
          1  Unused items found
          2  Fatal error

        HELP;

        return 0;
    }

    private function runVersion(): int
    {
        echo 'wp-specter ' . self::VERSION . PHP_EOL;
        return 0;
    }

    /** @param list<string> $args */
    private function runScan(array $args): int
    {
        try {
            $config = $this->parseArgs($args);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        $isGlobPath = GlobExpander::containsWildcard($config->path);
        // A glob pattern isn't itself a real directory to check/walk from — anchor upward
        // searches (project-config discovery, composer.json detection) at its longest literal
        // leading segment instead, which is guaranteed to exist even if the pattern currently
        // matches zero directories.
        $configSearchPath = $isGlobPath ? GlobExpander::baseDir($config->path) : $config->path;

        if (!$isGlobPath && !is_dir($config->path)) {
            return $this->error("Path does not exist or is not a directory: {$config->path}");
        }

        $configLoader = new ProjectConfigLoader();
        try {
            $projectConfig = $configLoader->load($configSearchPath);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        if ($config->generateBaseline && $projectConfig === null) {
            return $this->error(
                'No ' . ProjectConfigLoader::CONFIG_FILENAME . ' found. Run with --generate-config first.'
            );
        }

        // Stubs load additively: the project convention file (or the config's override of it),
        // then whatever --stubs= adds on top. Order doesn't matter — loadFile only ever adds
        // suppressions, never removes any.
        $autoStubsPath = $projectConfig->stubsPath ?? $configLoader->findDefaultStubsFile($configSearchPath);
        if ($autoStubsPath !== null) {
            try {
                StubRegistry::loadFile($autoStubsPath);
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage());
            }
        }
        if ($config->stubs !== null) {
            try {
                StubRegistry::loadFile($config->stubs);
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage());
            }
        }

        $modeDetector = new WpModeDetector();

        // Look for a composer-managed project first, before trusting WpModeDetector on the raw
        // path — mode detection recurses to find block.json, which on a whole project root
        // (hundreds of vendored files) can true-positive on someone else's block.json and
        // misclassify the entire tree as one giant "theme". Composer detection sidesteps that:
        // it only trusts what composer.json + vendor/composer/installed.json actually say.
        $projectInfo = null;
        $composerRoot = null;
        $targets = null;

        if ($isGlobPath) {
            // An explicit wildcard on the CLI is the most explicit possible target declaration
            // — scan exactly what it matches, bypassing config-declared targets and composer
            // auto-discovery entirely (same tier as, and even more deliberate than, the
            // .wp-specter.config.json-targets case below).
            $matches = GlobExpander::expandDirs($config->path);
            if (empty($matches)) {
                return $this->error("No directories matched pattern: {$config->path}");
            }
            $targets = array_map(
                fn(string $dir) => new ScanTarget(basename($dir), $dir, $modeDetector->detect($dir)),
                $matches,
            );
            // Still worth detecting a real composer root here (for --generate-config to prefer
            // over cwd) — a wildcard match can perfectly well live inside a composer-managed
            // project even though it wasn't reached via composer auto-discovery.
            $composerRoot = (new ComposerProjectDetector($modeDetector))->findProjectRoot($configSearchPath);
            $projectInfo = new ProjectInfo(
                $composerRoot ?? $configSearchPath,
                'matched by CLI wildcard',
                'dir(s) matching "' . basename($config->path) . '"',
            );
        } elseif ($config->target === null) {
            // An explicit .wp-specter.config.json targets list is a deliberate choice — it wins
            // over composer auto-discovery, not just heuristics.
            if ($projectConfig !== null && !empty($projectConfig->targets)) {
                $configuredTargets = [];
                foreach ($projectConfig->targets as $targetPath) {
                    if (!is_dir($targetPath)) {
                        return $this->error("Configured target does not exist: {$targetPath}");
                    }
                    $configuredTargets[] = new ScanTarget(basename($targetPath), $targetPath, $modeDetector->detect($targetPath));
                }

                $matched = $this->findExactTarget($configuredTargets, $config->path);
                if ($matched !== null) {
                    $targets = [$matched];
                } else {
                    $targets = $configuredTargets;
                    $projectInfo = new ProjectInfo(
                        $projectConfig->configDir,
                        'configured via ' . ProjectConfigLoader::CONFIG_FILENAME,
                        'theme/plugin dir(s) from config',
                    );
                }
            }

            if ($targets === null) {
                $projectDetector = new ComposerProjectDetector($modeDetector);
                $root = $projectDetector->findProjectRoot($config->path);
                if ($root !== null) {
                    $composerRoot = $root;
                    $discovered = $projectDetector->discoverCustomTargets($root);
                    $matched = $this->findExactTarget($discovered, $config->path);
                    if ($matched !== null) {
                        // User pointed scan directly at one of the project's own custom
                        // targets — scan just that one, exactly as if composer weren't
                        // involved at all.
                        $targets = [$matched];
                    } elseif (!empty($discovered)) {
                        $targets = $discovered;
                        $projectInfo = new ProjectInfo(
                            $root,
                            'composer-managed',
                            'custom theme/plugin dir(s), vendor packages excluded',
                        );
                    }
                }
            }
        }

        if ($targets === null) {
            $mode = $this->resolveMode($config, $modeDetector);
            $targets = [new ScanTarget(basename($config->path), $config->path, $mode)];
        }

        if ($config->generateConfig) {
            return $this->writeGeneratedConfig($targets, $projectConfig, $composerRoot, $isGlobPath ? $config->path : null);
        }

        $scanner = new FileScanner();
        $allFiles = [];
        foreach ($targets as $target) {
            if ($target->files !== null) {
                $allFiles = array_merge($allFiles, $this->applyIgnoreGlobs($target->files, $config->ignoreGlobs));
                continue;
            }
            $scanResult = $scanner->scan($target->path, $config->ignoreGlobs, $projectConfig->exclude ?? []);
            if ($scanResult->error !== null) {
                return $this->error($scanResult->error);
            }
            $allFiles = array_merge($allFiles, $scanResult->files);
        }

        $reporter = new TerminalReporter($config->noColor);
        if ($projectInfo !== null) {
            $reporter->printProjectHeader($projectInfo->root, $targets, count($allFiles), $projectInfo->sourceLabel, $projectInfo->targetsNote);
        } else {
            $reporter->printHeader($config->path, $targets[0]->mode, count($allFiles));
        }

        $parser = new PhpTokenParser();
        $findings = [];

        // Functions and hooks are matched across the whole file set — a theme registering a
        // hook that its companion plugin fires (or vice versa) is a normal, correct pattern in
        // a multi-target project, not a false "unmatched".
        if ($config->wantsType('functions')) {
            $findings = array_merge($findings, (new FunctionAnalyzer($parser))->analyze($allFiles));
        }

        if ($config->wantsType('hooks')) {
            $findings = array_merge($findings, (new HookAnalyzer($parser))->analyze($allFiles));
        }

        if ($config->wantsType('classes')) {
            $findings = array_merge($findings, (new ClassAnalyzer($parser))->analyze($allFiles));
        }

        // Templates and files need a specific root to know what's "root-level" and which mode's
        // hierarchy applies, so those run once per target — but still see every target's files
        // when resolving references, for the same cross-target reason as above.
        foreach ($targets as $target) {
            // A target with no detected mode isn't a recognizable theme (e.g. an mu-plugins
            // directory, which WP auto-loads directly with no template hierarchy at all) — the
            // WP template hierarchy simply doesn't apply, so there's nothing to check here.
            if ($config->wantsType('templates') && $target->mode !== null && $target->mode !== WpMode::Plugin) {
                $findings = array_merge($findings, (new TemplateAnalyzer($parser, $modeDetector))->analyze($allFiles, $target->mode, $target->path));
            }

            if ($config->wantsType('files')) {
                $findings = array_merge($findings, (new FileAnalyzer($parser))->analyze($allFiles, $target->path));
            }
        }

        if ($config->generateBaseline) {
            // Guaranteed non-null: the earlier "$config->generateBaseline && $projectConfig
            // === null" guard already returned if a config file wasn't found.
            return $this->writeGeneratedBaseline($projectConfig, $findings);
        }

        $suppressedCount = 0;
        if ($projectConfig !== null && !empty($projectConfig->baseline)) {
            [$findings, $suppressedCount] = $this->applyBaseline($findings, $projectConfig);
        }

        $reporter->printFindings($findings, $config->verbose, $targets);
        $reporter->printSummary($findings, $suppressedCount);

        return empty($findings) ? 0 : 1;
    }

    /** @param list<ScanTarget> $targets */
    private function writeGeneratedConfig(array $targets, ?ProjectConfig $projectConfig, ?string $composerRoot, ?string $globPattern): int
    {
        if ($projectConfig !== null) {
            return $this->error(
                ProjectConfigLoader::CONFIG_FILENAME . ' already exists at ' . $projectConfig->configDir
                . ' — edit it directly, or remove it first to regenerate.'
            );
        }

        // A detected composer project root (found via composer.json/vendor detection) is an
        // authoritative anchor — prefer it over cwd, since every target (composer-discovered or
        // matched by a CLI wildcard) necessarily lives under it already. Otherwise default to
        // cwd, matching how most CLI tools (phpstan, eslint, ...) resolve their config file —
        // but only when cwd is actually an ancestor of every scanned target: ProjectConfigLoader
        // only ever walks *upward* from a scan path to find the config, so writing it anywhere
        // else makes it permanently undiscoverable on the next run.
        if ($composerRoot !== null) {
            $writeDir = $composerRoot;
        } else {
            try {
                $writeDir = $this->requireCwd();
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage());
            }
            foreach ($targets as $target) {
                if (!$this->isAncestorOrSame($writeDir, $target->path)) {
                    return $this->error(
                        'Cannot write ' . ProjectConfigLoader::CONFIG_FILENAME . " to the current directory ({$writeDir}) "
                        . "— it is not an ancestor of the scanned path ({$target->path}). Run this command from the "
                        . 'project root (an ancestor of everything you scan).'
                    );
                }
            }
        }

        // A wildcard CLI path is written back as the pattern itself, not the directories it
        // happens to match right now — that's the entire point of supporting it here: a
        // custom-* plugin added next month gets picked up on the next scan with no config
        // change needed, instead of freezing today's snapshot.
        $writer = new ProjectConfigWriter();
        if ($globPattern !== null) {
            $writer->writeTargets($writeDir, [$globPattern]);
            $count = count($targets);
            echo "Config written to {$writeDir}/" . ProjectConfigLoader::CONFIG_FILENAME
                . " (pattern matching {$count} target(s) currently)." . PHP_EOL;
        } else {
            $writer->writeTargets($writeDir, array_map(fn(ScanTarget $t) => $t->path, $targets));
            $count = count($targets);
            echo "Config written to {$writeDir}/" . ProjectConfigLoader::CONFIG_FILENAME . " ({$count} target(s))." . PHP_EOL;
        }

        return 0;
    }

    private function isAncestorOrSame(string $ancestor, string $path): bool
    {
        $ancestor = rtrim($ancestor, '/');
        return $path === $ancestor || str_starts_with($path, $ancestor . '/');
    }

    /** @param list<Finding> $findings */
    private function writeGeneratedBaseline(ProjectConfig $projectConfig, array $findings): int
    {
        $entries = array_map(
            fn(Finding $f) => new BaselineEntry($f->type->value, $f->name, BaselineEntry::relativize($f->file, $projectConfig->configDir)),
            $findings,
        );

        (new ProjectConfigWriter())->writeBaseline($projectConfig->configDir, $entries);

        $count = count($entries);
        echo "Baseline written to {$projectConfig->configDir}/" . ProjectConfigLoader::CONFIG_FILENAME . " ({$count} finding(s))." . PHP_EOL;

        return 0;
    }

    /**
     * @param list<Finding> $findings
     * @return array{0: list<Finding>, 1: int}
     */
    private function applyBaseline(array $findings, ProjectConfig $projectConfig): array
    {
        $kept = [];
        $suppressed = 0;
        foreach ($findings as $finding) {
            $isBaselined = false;
            foreach ($projectConfig->baseline as $entry) {
                if ($entry->matches($finding, $projectConfig->configDir)) {
                    $isBaselined = true;
                    break;
                }
            }
            if ($isBaselined) {
                $suppressed++;
            } else {
                $kept[] = $finding;
            }
        }
        return [$kept, $suppressed];
    }

    /** @param list<string> $args */
    private function runGenerateStubs(array $args): int
    {
        $path = null;
        $output = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $output = substr($arg, strlen('--output='));
            } elseif (!str_starts_with($arg, '--')) {
                $path = $arg;
            } else {
                return $this->error("Unknown option \"{$arg}\".");
            }
        }

        $sources = [];
        $projectConfig = null;

        if ($path !== null) {
            $resolved = realpath($path) ?: $path;
            if (!is_dir($resolved)) {
                return $this->error("Path does not exist or is not a directory: {$resolved}");
            }
            $sources[] = $resolved;
        } else {
            // No path given — fall back to .wp-specter.config.json's "stubsFrom" list, so a
            // project can just run `generate-stubs` with no arguments and get every declared
            // vendor/plugins source scanned in one shot instead of one --stubs invocation per dir.
            try {
                $projectConfig = (new ProjectConfigLoader())->load($this->requireCwd());
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage());
            }

            if ($projectConfig !== null && !empty($projectConfig->stubsFrom)) {
                foreach ($projectConfig->stubsFrom as $dir) {
                    if (!is_dir($dir)) {
                        return $this->error("Configured stubsFrom path does not exist: {$dir}");
                    }
                    $sources[] = $dir;
                }
                if ($output === null) {
                    $output = $projectConfig->stubsPath ?? ($projectConfig->configDir . '/' . ProjectConfigLoader::STUBS_FILENAME);
                }
            } else {
                try {
                    $sources[] = $this->requireCwd();
                } catch (\RuntimeException $e) {
                    return $this->error($e->getMessage());
                }
            }
        }

        if ($output === null) {
            $output = ProjectConfigLoader::STUBS_FILENAME;
        }

        $scanner = new FileScanner();
        $parser = new PhpTokenParser();
        $hooks = [];
        $prefixes = [];
        $totalFiles = 0;

        foreach ($sources as $source) {
            $scanResult = $scanner->scan($source, [], $projectConfig->exclude ?? []);
            if ($scanResult->error !== null) {
                return $this->error($scanResult->error);
            }
            $totalFiles += count($scanResult->files);

            foreach ($scanResult->files as $file) {
                $result = $parser->parse($file);
                foreach ($result->hookInvocations as $inv) {
                    if (!$inv->isDynamic && $inv->tag !== '') {
                        $hooks[$inv->tag] = true;
                    } elseif ($inv->isDynamic && $inv->tagPrefix !== '') {
                        // A dynamic call with a resolvable prefix — e.g. ACF's single
                        // apply_filters("acf/settings/{$name}", ...) dispatcher — fires every
                        // hook in that family even though no individual one ever appears as a
                        // literal string anywhere in the source.
                        $prefixes[$inv->tagPrefix] = true;
                    }
                }
            }
        }

        ksort($hooks);
        ksort($prefixes);
        $hookList = array_keys($hooks);
        $prefixList = array_keys($prefixes);

        $data = [
            'generated' => date('Y-m-d'),
            'source'    => count($sources) === 1 ? $sources[0] : $sources,
            'hooks'     => $hookList,
            'prefixes'  => $prefixList,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if (file_put_contents($output, $json) === false) {
            return $this->error("Cannot write stubs file: {$output}");
        }

        $count = count($hookList);
        $prefixCount = count($prefixList);
        $prefixDesc = $prefixCount > 0 ? " and {$prefixCount} dynamic hook prefix(es)" : '';
        $sourceDesc = count($sources) === 1 ? $sources[0] : (count($sources) . ' configured source(s)');
        echo "Found {$count} hook(s){$prefixDesc} in {$sourceDesc} ({$totalFiles} files scanned)" . PHP_EOL;
        echo "Stubs written to {$output}" . PHP_EOL;

        return 0;
    }

    /** @param list<ScanTarget> $targets */
    private function findExactTarget(array $targets, string $path): ?ScanTarget
    {
        $needle = rtrim($path, '/');
        foreach ($targets as $target) {
            if (rtrim($target->path, '/') === $needle) {
                return $target;
            }
        }
        return null;
    }

    private function resolveMode(Config $config, WpModeDetector $modeDetector): ?WpMode
    {
        if ($config->target === 'plugin') {
            return WpMode::Plugin;
        }

        $detected = $modeDetector->detect($config->path);

        if ($config->target === 'theme') {
            // User declared theme — use detected sub-mode (Classic/Block/Hybrid) or fall back to Classic
            return ($detected !== null && $detected !== WpMode::Plugin) ? $detected : WpMode::Classic;
        }

        // Auto-detect
        return $detected;
    }

    /** @param list<string> $args */
    private function parseArgs(array $args): Config
    {
        $path = null;
        $target = null;
        $types = ['all'];
        $ignoreGlobs = [];
        $stubs = null;
        $verbose = false;
        $noColor = false;
        $generateConfig = false;
        $generateBaseline = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--stubs=')) {
                $stubs = substr($arg, strlen('--stubs='));
            } elseif (str_starts_with($arg, '--target=')) {
                $target = substr($arg, strlen('--target='));
                if (!in_array($target, ['theme', 'plugin'], true)) {
                    throw new \InvalidArgumentException("Invalid --target \"{$target}\". Valid: theme, plugin");
                }
            } elseif (str_starts_with($arg, '--type=')) {
                $raw = substr($arg, strlen('--type='));
                $types = array_filter(array_map('trim', explode(',', $raw)));
                $valid = ['all', 'functions', 'hooks', 'templates', 'files', 'classes'];
                foreach ($types as $t) {
                    if (!in_array($t, $valid, true)) {
                        throw new \InvalidArgumentException("Unknown type \"{$t}\". Valid: " . implode(', ', $valid));
                    }
                }
            } elseif (str_starts_with($arg, '--ignore=')) {
                $raw = substr($arg, strlen('--ignore='));
                $ignoreGlobs = array_filter(array_map('trim', explode(',', $raw)));
            } elseif ($arg === '--verbose') {
                $verbose = true;
            } elseif ($arg === '--no-color') {
                $noColor = true;
            } elseif ($arg === '--generate-config') {
                $generateConfig = true;
            } elseif ($arg === '--generate-baseline') {
                $generateBaseline = true;
            } elseif (!str_starts_with($arg, '--')) {
                $path = $arg;
            } else {
                throw new \InvalidArgumentException("Unknown option \"{$arg}\".");
            }
        }

        if ($generateConfig && $generateBaseline) {
            throw new \InvalidArgumentException('--generate-config and --generate-baseline cannot be used together.');
        }

        $cwd = $this->requireCwd();
        $resolvedPath = $path ?? $cwd;
        if (GlobExpander::containsWildcard($resolvedPath)) {
            // realpath() can't resolve a path containing wildcard metacharacters — just anchor
            // it to an absolute path so the glob() call in runScan() is unambiguous regardless
            // of the process's cwd by the time it runs.
            if (!str_starts_with($resolvedPath, '/')) {
                $resolvedPath = $cwd . '/' . $resolvedPath;
            }
        } else {
            $resolvedPath = realpath($resolvedPath) ?: $resolvedPath;
        }

        return new Config(
            path: $resolvedPath,
            target: $target,
            types: array_values($types),
            ignoreGlobs: array_values($ignoreGlobs),
            stubs: $stubs,
            verbose: $verbose,
            noColor: $noColor,
            generateConfig: $generateConfig,
            generateBaseline: $generateBaseline,
        );
    }

    /**
     * @param list<string> $files
     * @param list<string> $globs
     * @return list<string>
     */
    private function applyIgnoreGlobs(array $files, array $globs): array
    {
        if (empty($globs)) {
            return $files;
        }
        return array_values(array_filter(
            $files,
            fn(string $file) => !$this->matchesAnyGlob($file, $globs),
        ));
    }

    /** @param list<string> $globs */
    private function matchesAnyGlob(string $file, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $file) || fnmatch($glob, basename($file))) {
                return true;
            }
        }
        return false;
    }

    /** getcwd() can fail (deleted/inaccessible cwd) — every caller needs a real path, not that edge case. */
    private function requireCwd(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Cannot determine current working directory.');
        }
        return $cwd;
    }

    private function error(string $message): int
    {
        echo("Error: {$message}" . PHP_EOL);
        return 2;
    }
}
