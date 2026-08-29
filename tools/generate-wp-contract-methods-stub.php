#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dev tool — regenerates src/Stubs/WpCoreContractMethods.php from actual WordPress core source.
 *
 * BASE_CLASS_CONTRACT_METHODS in ClassAnalyzer (WP_Widget's widget()/form()/update(), Walker's
 * start_el()/end_el(), ...) is hand-curated: every WP core base class designed for subclassing
 * has to be discovered and added by hand as a real-world false-positive turns it up. This tool
 * generates a second, *additive* list to fall back to for any base class the hand-curated list
 * doesn't yet name — never replacing it, only widening the net.
 *
 * The signature it looks for: a method WP core declares `public` on a base class, and *also*
 * calls via `$this->method()` from somewhere else in that same class's own body (reusing
 * WpSpecter's own PhpTokenParser/ScopedMethodCall machinery — the same parser that recognizes
 * `$this->method()` calls in a scanned project). That's the actual "dispatches on self, possibly
 * a subclass" mechanism every one of these classes shares: WP_Widget's display_callback() calls
 * $this->widget(), Walker's walk() calls $this->start_el(), and so on — a project subclass
 * overriding the method is never called by a visible name reference in project code, only
 * reached by WP core itself calling through the object's actual (possibly overridden) type.
 *
 * Deliberately over-inclusive by design, same trade-off as the hooks stub and the rest of this
 * project: a class-internal `public` helper that merely *happens* to be called via $this-> too
 * (not actually meant for override) can slip in as a false candidate — but since this is only
 * ever consulted as a fallback to *suppress* a finding, never to produce one, an over-broad entry
 * costs a missed "unused method" warning on some rare theme/plugin override, not a wrong one.
 *
 * Usage:
 *   php tools/generate-wp-contract-methods-stub.php [--source=/path] [--version=6.6.2] [--dry-run]
 *
 * --source   Path to an existing WP core checkout (must contain wp-includes/version.php).
 *            Skips the download. Useful offline or against a wordpress-develop checkout.
 * --version  Pin a specific release instead of the latest stable (ignored with --source).
 * --dry-run  Print the added/removed class summary but don't overwrite the stub file.
 */

// Namespaced (unlike generate-wp-hooks-stub.php, which predates this file and has no namespace)
// specifically so this script's own global helper functions — several sharing a name with that
// script's (scanCore, collectPhpFiles, removeDir, resolveSource, ...), since both tools follow
// the same self-contained-CLI-script shape — don't collide with it under static analysis, which
// evaluates every file in the project together and would otherwise see two conflicting global
// declarations of the same function name.

namespace WpSpecter\Tools\ContractMethodsGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\Stubs\WpCoreContractMethods;

const TARGET_FILE = __DIR__ . '/../src/Stubs/WpCoreContractMethods.php';
const SCAN_DIRS = ['wp-admin', 'wp-includes'];
// Bundled third-party libraries, not WP's own subclassing-facing API surface — a theme/plugin
// extending "Cookie" or "Box" (Requests_Cookie, getID3's Box, both collapsed to a bare short
// name the same way every other curated list here already is) is essentially unheard of, and
// scanning them just adds several hundred irrelevant classes' worth of noise to the stub for no
// real coverage gain. Paths relative to wp-includes/.
const EXCLUDED_DIRS = [
    'wp-includes/ID3', 'wp-includes/IXR', 'wp-includes/PHPMailer', 'wp-includes/Requests',
    'wp-includes/SimplePie', 'wp-includes/Text', 'wp-includes/sodium_compat',
    'wp-includes/php-compat', 'wp-includes/php-ai-client',
];
// Single-file bundled third-party libraries (not under their own directory, so EXCLUDED_DIRS
// can't catch them) whose class names are generic enough to pose a real collision risk with an
// unrelated project class of the same short name — e.g. class-avif-info.php's own internal
// "Box"/"Parser" classes (an AV1/AVIF bitstream parser vendored from the Alliance for Open
// Media), not anything a theme/plugin would ever knowingly extend.
const EXCLUDED_FILES = [
    'wp-includes/class-avif-info.php',
];
// A method matching this name never indicates a subclass override point regardless of how it's
// called — PHP's own magic-method dispatch (__construct, __get, __toString, ...) already has
// nothing to do with WP core's own polymorphic self-dispatch pattern this tool looks for.
const MAGIC_PREFIX = '__';
// A single leading underscore is WP core's own long-standing naming convention for "internal,
// don't touch this even though PHP visibility says public" (_get_display_callback(),
// _register_one(), ...) — real WP core methods, but not a subclass override point in the sense
// this tool is looking for, so excluded the same way MAGIC_PREFIX is, just for a convention
// instead of a language feature. Confirmed noise source: WP_Widget's own internal
// _get_display_callback()/_get_form_callback()/_get_update_callback()/_register_one()/_set()
// all self-dispatch within the class, exactly the shape this tool looks for, but aren't real
// override points.
const WP_INTERNAL_PREFIX = '_';

/** @param list<string> $argv */
function main(array $argv): int
{
    $opts = parseArgs($argv);

    try {
        [$wpRoot, $wpVersion, $cleanup] = resolveSource($opts['source'], $opts['version']);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, "Error: {$e->getMessage()}\n");
        return 1;
    }

    try {
        fwrite(STDERR, "Scanning WordPress {$wpVersion} core source...\n");
        $contractMethods = scanCore($wpRoot);
    } finally {
        $cleanup();
    }

    $totalMethods = array_sum(array_map('count', $contractMethods));
    fwrite(STDERR, "Found {$totalMethods} candidate contract methods across " . count($contractMethods) . " classes.\n");

    $oldMethods = WpCoreContractMethods::methods();
    reportDiff($oldMethods, $contractMethods);

    if ($opts['dryRun']) {
        fwrite(STDERR, "\n--dry-run: not writing " . TARGET_FILE . "\n");
        return 0;
    }

    writeStubFile($contractMethods, $wpVersion);
    fwrite(STDERR, "\nWrote " . realpath(TARGET_FILE) . "\n");

    return 0;
}

/**
 * @param list<string> $argv
 * @return array{source: ?string, version: ?string, dryRun: bool}
 */
function parseArgs(array $argv): array
{
    $opts = ['source' => null, 'version' => null, 'dryRun' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $opts['dryRun'] = true;
        } elseif (str_starts_with($arg, '--source=')) {
            $opts['source'] = substr($arg, strlen('--source='));
        } elseif (str_starts_with($arg, '--version=')) {
            $opts['version'] = substr($arg, strlen('--version='));
        } else {
            fwrite(STDERR, "Unknown argument: {$arg}\n");
            exit(1);
        }
    }
    return $opts;
}

/** @return array{0: string, 1: string, 2: callable(): void} [wpRoot, version, cleanup] */
function resolveSource(?string $source, ?string $version): array
{
    if ($source !== null) {
        $versionFile = rtrim($source, '/') . '/wp-includes/version.php';
        if (!is_file($versionFile)) {
            throw new \RuntimeException("{$source} doesn't look like a WordPress core checkout (missing wp-includes/version.php)");
        }
        return [rtrim($source, '/'), readWpVersion($versionFile), function (): void {}];
    }

    return downloadWordPress($version);
}

function readWpVersion(string $versionFile): string
{
    $code = file_get_contents($versionFile);
    if ($code !== false && preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $code, $m)) {
        return $m[1];
    }
    return 'unknown';
}

/** @return array{0: string, 1: string, 2: callable(): void} [wpRoot, version, cleanup] */
function downloadWordPress(?string $version): array
{
    $url = $version !== null
        ? "https://wordpress.org/wordpress-{$version}.tar.gz"
        : 'https://wordpress.org/latest.tar.gz';

    $tmpDir = sys_get_temp_dir() . '/wp-specter-contract-methods-' . uniqid();
    if (!mkdir($tmpDir, 0755, true)) {
        throw new \RuntimeException("Could not create temp dir {$tmpDir}");
    }
    $cleanup = function () use ($tmpDir): void {
        removeDir($tmpDir);
    };

    fwrite(STDERR, "Downloading {$url}...\n");
    $context = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: wp-specter-contract-methods-generator\r\n"]]);
    $tarGzPath = $tmpDir . '/wordpress.tar.gz';
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        $cleanup();
        throw new \RuntimeException("Failed to download {$url}");
    }
    file_put_contents($tarGzPath, $data);
    unset($data);

    try {
        $phar = new \PharData($tarGzPath);
        $phar->extractTo($tmpDir, overwrite: true);
    } catch (\Exception $e) {
        $cleanup();
        throw new \RuntimeException("Failed to extract {$tarGzPath}: {$e->getMessage()}");
    }

    $wpRoot = $tmpDir . '/wordpress';
    if (!is_dir($wpRoot)) {
        $cleanup();
        throw new \RuntimeException('Extracted archive has no wordpress/ directory (unexpected release layout)');
    }

    return [$wpRoot, readWpVersion($wpRoot . '/wp-includes/version.php'), $cleanup];
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/** @return array<string, list<string>> Base class short name => contract method names, sorted. */
function scanCore(string $wpRoot): array
{
    $parser = new PhpTokenParser();

    // class name => method name => true, built independently from two different signals per
    // file (a regex pass for visibility, PhpTokenParser for the rest) then intersected below —
    // a method only counts once both agree it exists, is public, and is genuinely self-dispatched.
    $publicMethodDefs = [];
    $selfDispatched = [];

    foreach (collectPhpFiles($wpRoot) as $absPath) {
        $code = file_get_contents($absPath);
        if ($code === false) {
            continue;
        }
        // A per-file (not per-class) approximation: WP core overwhelmingly declares one class
        // per file, so this rarely conflates two different classes' visibility — same "simple
        // shape, occasionally coarse" trade-off the rest of this project accepts throughout.
        // PhpTokenParser doesn't track visibility at all (FunctionDef has no such field), so a
        // lightweight regex over the raw source is the cheapest way to get it without teaching
        // the core parser a concept only this one-off generator needs.
        preg_match_all('/^\s*public\s+function\s+&?(\w+)\s*\(/m', $code, $m);
        $publicNames = array_flip($m[1]);

        $result = $parser->parse($absPath);

        foreach ($result->functionDefs as $def) {
            if (
                !$def->isMethod
                || $def->ownerClass === null
                || str_starts_with($def->name, MAGIC_PREFIX)
                || str_starts_with($def->name, WP_INTERNAL_PREFIX)
                || !isset($publicNames[$def->name])
            ) {
                continue;
            }
            $publicMethodDefs[$def->ownerClass][$def->name] = true;
        }

        foreach ($result->scopedMethodCalls as $call) {
            $selfDispatched[$call->receiverClass][$call->method] = true;
        }
    }

    $contractMethods = [];
    foreach ($publicMethodDefs as $class => $methods) {
        $selfCalled = $selfDispatched[$class] ?? [];
        $contract = array_keys(array_intersect_key($methods, $selfCalled));
        if ($contract !== []) {
            sort($contract);
            $contractMethods[$class] = $contract;
        }
    }

    ksort($contractMethods);

    return $contractMethods;
}

/** @return iterable<string> absolute paths */
function collectPhpFiles(string $wpRoot): iterable
{
    $excludedDirs = array_map(fn(string $d) => $wpRoot . '/' . $d . '/', EXCLUDED_DIRS);
    $excludedFiles = array_map(fn(string $f) => $wpRoot . '/' . $f, EXCLUDED_FILES);

    foreach (SCAN_DIRS as $dir) {
        $absDir = $wpRoot . '/' . $dir;
        if (!is_dir($absDir)) {
            continue;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->getExtension() !== 'php') {
                continue;
            }
            $path = $item->getPathname();
            if (in_array($path, $excludedFiles, true)) {
                continue;
            }
            foreach ($excludedDirs as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }
            yield $path;
        }
    }
}

/**
 * @param array<string, list<string>> $oldMethods
 * @param array<string, list<string>> $newMethods
 */
function reportDiff(array $oldMethods, array $newMethods): void
{
    $addedClasses = array_values(array_diff(array_keys($newMethods), array_keys($oldMethods)));
    $removedClasses = array_values(array_diff(array_keys($oldMethods), array_keys($newMethods)));

    fwrite(STDERR, "\n" . count($addedClasses) . ' new class(es), ' . count($removedClasses) . ' dropped class(es) (of ' . count($oldMethods) . " previously known).\n");
    if ($addedClasses !== []) {
        fwrite(STDERR, '  + ' . implode("\n  + ", array_slice($addedClasses, 0, 30)) . (count($addedClasses) > 30 ? "\n  ... and " . (count($addedClasses) - 30) . ' more' : '') . "\n");
    }
    if ($removedClasses !== []) {
        fwrite(STDERR, '  - ' . implode("\n  - ", array_slice($removedClasses, 0, 30)) . (count($removedClasses) > 30 ? "\n  ... and " . (count($removedClasses) - 30) . ' more' : '') . "\n");
    }
}

/** @param array<string, list<string>> $contractMethods */
function writeStubFile(array $contractMethods, string $wpVersion): void
{
    $date = date('Y-m-d');

    $groups = '';
    foreach ($contractMethods as $class => $methods) {
        $groups .= "        '" . addslashes($class) . "' => [\n";
        foreach ($methods as $method) {
            $groups .= "            '" . addslashes($method) . "',\n";
        }
        $groups .= "        ],\n";
    }
    $groups = rtrim($groups, "\n") . "\n";

    $content = <<<PHP
    <?php

    declare(strict_types=1);

    namespace WpSpecter\\Stubs;

    /**
     * Generated by tools/generate-wp-contract-methods-stub.php from WordPress core {$wpVersion}
     * on {$date}. Do not hand-edit — re-run `composer update-wp-stubs` to refresh instead.
     *
     * Base class short name => every public method declared on it that WP core also calls via
     * \$this->method() from elsewhere in that same class's own body (scanned with the project's
     * own PhpTokenParser/ScopedMethodCall machinery). Consulted as a fallback *alongside*
     * ClassAnalyzer's hand-curated BASE_CLASS_CONTRACT_METHODS, never in place of it — see
     * ContractMethodStub's own docblock for why this needs to stay additive rather than
     * authoritative.
     */
    final class WpCoreContractMethods implements ContractMethodStub
    {
        private const METHODS = [
    {$groups}    ];

        public static function methods(): array
        {
            return self::METHODS;
        }
    }

    PHP;

    file_put_contents(TARGET_FILE, $content);
}

// $_SERVER['argv'], not the bare $argv global: the CLI SAPI always populates both regardless
// of the register_argc_argv ini setting, but PHPStan's variable.undefined check for $argv goes
// off that setting — which defaults to Off in stock php.ini (e.g. Homebrew's), unlike some
// distros' CLI-specific ini that turns it on.
exit(main($_SERVER['argv']));
