<?php

declare(strict_types=1);

namespace WpSpecter;

use WpSpecter\Analyzer\ClassAnalyzer;
use WpSpecter\Analyzer\FileAnalyzer;
use WpSpecter\Analyzer\FunctionAnalyzer;
use WpSpecter\Analyzer\HookAnalyzer;
use WpSpecter\Analyzer\TemplateAnalyzer;
use WpSpecter\Composer\ComposerProjectDetector;
use WpSpecter\ProjectConfig\ProjectConfigLoader;
use WpSpecter\Scan\ScanTarget;
use WpSpecter\Detector\WpModeDetector;
use WpSpecter\Enum\WpMode;
use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\Stubs\StubRegistry;
use WpSpecter\Reporter\TerminalReporter;
use WpSpecter\Scanner\FileScanner;

class Application
{
    private const VERSION = '0.2.0';

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
          path                   Path to a theme or plugin directory (default: current directory)

        Scan options:
          --target=<target>      What to scan: theme or plugin (default: auto-detect)
          --type=<types>         Comma-separated: functions,hooks,templates,files,classes (default: all)
          --stubs=<file>         JSON stubs file to suppress known hooks (see generate-stubs)
          --ignore=<globs>       Comma-separated glob patterns to exclude
          --verbose              Show matched references alongside findings
          --no-color             Disable ANSI color output

        Generate-stubs options:
          --output=<file>        Output path for the stubs file (default: wp-specter-stubs.json)
          (with no <path>, uses "stubsFrom" from .wp-specter.config.json if present)

        Project files (auto-discovered by walking upward from <path>):
          .wp-specter.config.json   "targets" (exact dirs to scan) and "stubsFrom" (see above)
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
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        if (!is_dir($config->path)) {
            return $this->error("Path does not exist or is not a directory: {$config->path}");
        }

        $configLoader = new ProjectConfigLoader();
        try {
            $projectConfig = $configLoader->load($config->path);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        // Stubs load additively: the project convention file (or the config's override of it),
        // then whatever --stubs= adds on top. Order doesn't matter — loadFile only ever adds
        // suppressions, never removes any.
        $autoStubsPath = $projectConfig?->stubsPath ?? $configLoader->findDefaultStubsFile($config->path);
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
        $projectRoot = null;
        $projectSourceLabel = null;
        $projectTargetsNote = null;
        $targets = null;

        if ($config->target === null) {
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
                    $projectRoot = $projectConfig->configDir;
                    $projectSourceLabel = 'configured via ' . ProjectConfigLoader::CONFIG_FILENAME;
                    $projectTargetsNote = 'theme/plugin dir(s) from config';
                }
            }

            if ($targets === null) {
                $projectDetector = new ComposerProjectDetector($modeDetector);
                $root = $projectDetector->findProjectRoot($config->path);
                if ($root !== null) {
                    $discovered = $projectDetector->discoverCustomTargets($root);
                    $matched = $this->findExactTarget($discovered, $config->path);
                    if ($matched !== null) {
                        // User pointed scan directly at one of the project's own custom
                        // targets — scan just that one, exactly as if composer weren't
                        // involved at all.
                        $targets = [$matched];
                    } elseif (!empty($discovered)) {
                        $targets = $discovered;
                        $projectRoot = $root;
                        $projectSourceLabel = 'composer-managed';
                        $projectTargetsNote = 'custom theme/plugin dir(s), vendor packages excluded';
                    }
                }
            }
        }

        if ($targets === null) {
            $mode = $this->resolveMode($config, $modeDetector);
            $targets = [new ScanTarget(basename($config->path), $config->path, $mode)];
        }

        $scanner = new FileScanner();
        $allFiles = [];
        foreach ($targets as $target) {
            if ($target->files !== null) {
                $allFiles = array_merge($allFiles, $this->applyIgnoreGlobs($target->files, $config->ignoreGlobs));
                continue;
            }
            $scanResult = $scanner->scan($target->path, $config->ignoreGlobs);
            if ($scanResult->error !== null) {
                return $this->error($scanResult->error);
            }
            $allFiles = array_merge($allFiles, $scanResult->files);
        }

        $reporter = new TerminalReporter($config->noColor);
        if ($projectRoot !== null) {
            $reporter->printProjectHeader($projectRoot, $targets, count($allFiles), $projectSourceLabel, $projectTargetsNote);
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

        $reporter->printFindings($findings, $config->verbose);
        $reporter->printSummary($findings);

        return empty($findings) ? 0 : 1;
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
                $projectConfig = (new ProjectConfigLoader())->load(getcwd());
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
                $sources[] = getcwd();
            }
        }

        if ($output === null) {
            $output = 'wp-specter-stubs.json';
        }

        $scanner = new FileScanner();
        $parser = new PhpTokenParser();
        $hooks = [];
        $prefixes = [];
        $totalFiles = 0;

        foreach ($sources as $source) {
            $scanResult = $scanner->scan($source);
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
            } elseif (!str_starts_with($arg, '--')) {
                $path = $arg;
            } else {
                throw new \InvalidArgumentException("Unknown option \"{$arg}\".");
            }
        }

        return new Config(
            path: realpath($path ?? getcwd()) ?: ($path ?? getcwd()),
            target: $target,
            types: array_values($types),
            ignoreGlobs: array_values($ignoreGlobs),
            stubs: $stubs,
            verbose: $verbose,
            noColor: $noColor,
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

    private function error(string $message): int
    {
        fwrite(STDERR, "Error: {$message}" . PHP_EOL);
        return 2;
    }
}
