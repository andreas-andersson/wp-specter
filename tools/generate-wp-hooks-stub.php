#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dev tool — regenerates src/Stubs/WpCoreHooks.php from actual WordPress core source.
 *
 * WP core hooks drift release to release, and developer.wordpress.org's hooks reference is a
 * JS-rendered app with no stable API to scrape. Instead this downloads a real WP core tarball
 * and scans wp-admin/, wp-includes/, and the root bootstrap files for do_action()/apply_filters()
 * (and their _ref_array variants) calls, reusing WpSpecter's own PhpTokenParser — the same
 * parser that recognizes hook invocations in a scanned project — so "what counts as a hook
 * invocation" stays defined in exactly one place.
 *
 * Usage:
 *   php tools/generate-wp-hooks-stub.php [--source=/path/to/wordpress] [--version=6.6.2] [--dry-run]
 *
 * --source   Path to an existing WP core checkout (must contain wp-includes/version.php).
 *            Skips the download. Useful offline or against a wordpress-develop checkout.
 * --version  Pin a specific release instead of the latest stable (ignored with --source).
 * --dry-run  Print the added/removed hook summary but don't overwrite WpCoreHooks.php.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\Stubs\WpCoreHooks;

const TARGET_FILE = __DIR__ . '/../src/Stubs/WpCoreHooks.php';
const SCAN_DIRS = ['wp-admin', 'wp-includes'];
const SCAN_ROOT_FILES = [
    'wp-load.php', 'wp-settings.php', 'wp-cron.php', 'wp-links-opml.php',
    'wp-trackback.php', 'wp-comments-post.php', 'wp-mail.php', 'wp-activate.php',
    'wp-signup.php', 'wp-login.php', 'xmlrpc.php',
];
const MIN_PREFIX_LENGTH = 4;
// Dynamic-tag prefixes that are real WP core hook families but can't be discovered by scanning
// for a literal leading string segment, because core builds the whole tag from runtime values
// with no literal part at all — e.g. wp-includes/post.php's transition_post_status() fires
// do_action( "{$new_status}_{$post->post_type}" ), so 'publish_post' only exists as the
// concatenation of two variables. Hand-maintained; always merged into the scanned PREFIXES.
const KNOWN_EXTRA_PREFIXES = [
    'publish_', // transition_post_status() -> "{$new_status}_{$post->post_type}"
];

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
        [$hooksByFile, $prefixes] = scanCore($wpRoot);
    } finally {
        $cleanup();
    }

    $totalHooks = array_sum(array_map('count', $hooksByFile));
    fwrite(STDERR, "Found {$totalHooks} literal hook tags across " . count($hooksByFile) . ' files, ' . count($prefixes) . " dynamic-tag prefixes.\n");

    $oldHooks = WpCoreHooks::hooks();
    $newHooks = array_merge(...array_values($hooksByFile));
    sort($newHooks);
    $newHooks = array_values(array_unique($newHooks));

    reportDiff($oldHooks, $newHooks);

    if ($opts['dryRun']) {
        fwrite(STDERR, "\n--dry-run: not writing " . TARGET_FILE . "\n");
        return 0;
    }

    writeStubFile($hooksByFile, $prefixes, $wpVersion);
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

    $tmpDir = sys_get_temp_dir() . '/wp-specter-hooks-' . uniqid();
    if (!mkdir($tmpDir, 0755, true)) {
        throw new \RuntimeException("Could not create temp dir {$tmpDir}");
    }
    $cleanup = function () use ($tmpDir): void {
        removeDir($tmpDir);
    };

    fwrite(STDERR, "Downloading {$url}...\n");
    $context = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: wp-specter-hooks-generator\r\n"]]);
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

/** @return array{0: array<string, list<string>>, 1: list<string>} [hooksByFile, prefixes] */
function scanCore(string $wpRoot): array
{
    $parser = new PhpTokenParser();
    $hooksByFile = [];
    $prefixes = [];

    foreach (collectPhpFiles($wpRoot) as $relPath => $absPath) {
        $result = $parser->parse($absPath);
        $literals = [];
        foreach ($result->hookInvocations as $invocation) {
            if (!$invocation->isDynamic) {
                $literals[] = $invocation->tag;
                continue;
            }
            if (strlen($invocation->tagPrefix) >= MIN_PREFIX_LENGTH) {
                $prefixes[$invocation->tagPrefix] = true;
            }
        }
        if ($literals !== []) {
            sort($literals);
            $hooksByFile[$relPath] = array_values(array_unique($literals));
        }
    }

    ksort($hooksByFile);
    foreach (KNOWN_EXTRA_PREFIXES as $prefix) {
        $prefixes[$prefix] = true;
    }
    $prefixList = array_keys($prefixes);
    sort($prefixList);

    return [$hooksByFile, $prefixList];
}

/** @return iterable<string, string> relative path => absolute path */
function collectPhpFiles(string $wpRoot): iterable
{
    foreach (SCAN_ROOT_FILES as $file) {
        $abs = $wpRoot . '/' . $file;
        if (is_file($abs)) {
            yield $file => $abs;
        }
    }

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
            $abs = $item->getPathname();
            yield substr($abs, strlen($wpRoot) + 1) => $abs;
        }
    }
}

/**
 * @param list<string> $oldHooks
 * @param list<string> $newHooks
 */
function reportDiff(array $oldHooks, array $newHooks): void
{
    $added = array_values(array_diff($newHooks, $oldHooks));
    $removed = array_values(array_diff($oldHooks, $newHooks));

    fwrite(STDERR, "\n" . count($added) . ' added, ' . count($removed) . ' removed (of ' . count($oldHooks) . " previously known).\n");
    if ($added !== []) {
        fwrite(STDERR, '  + ' . implode("\n  + ", array_slice($added, 0, 20)) . (count($added) > 20 ? "\n  ... and " . (count($added) - 20) . ' more' : '') . "\n");
    }
    if ($removed !== []) {
        fwrite(STDERR, '  - ' . implode("\n  - ", array_slice($removed, 0, 20)) . (count($removed) > 20 ? "\n  ... and " . (count($removed) - 20) . ' more' : '') . "\n");
    }
}

/**
 * @param array<string, list<string>> $hooksByFile
 * @param list<string> $prefixes
 */
function writeStubFile(array $hooksByFile, array $prefixes, string $wpVersion): void
{
    $date = date('Y-m-d');

    $groups = '';
    foreach ($hooksByFile as $relPath => $hooks) {
        $groups .= "        // --- {$relPath} ---\n";
        foreach ($hooks as $hook) {
            $groups .= "        '" . addslashes($hook) . "',\n";
        }
        $groups .= "\n";
    }
    $groups = rtrim($groups, "\n") . "\n";

    $prefixLines = '';
    foreach ($prefixes as $prefix) {
        $prefixLines .= "        '" . addslashes($prefix) . "',\n";
    }
    $prefixLines = rtrim($prefixLines, "\n") . "\n";

    $content = <<<PHP
    <?php

    declare(strict_types=1);

    namespace WpSpecter\\Stubs;

    /**
     * Generated by tools/generate-wp-hooks-stub.php from WordPress core {$wpVersion} on {$date}.
     * Do not hand-edit — re-run `composer update-wp-stubs` to refresh instead. Grouped by the
     * core file each literal hook tag was found in (do_action()/apply_filters() calls, scanned
     * with the project's own PhpTokenParser).
     */
    final class WpCoreHooks implements HookStub
    {
        private const HOOKS = [
    {$groups}    ];

        // Dynamic WP core hook prefixes — any hook starting with these is fired by WP core.
        // Resolved from interpolated/concatenated do_action()/apply_filters() tags where the
        // leading segment was a literal (e.g. "wp_ajax_{\$action}" -> 'wp_ajax_').
        private const PREFIXES = [
    {$prefixLines}    ];

        public static function hooks(): array
        {
            return self::HOOKS;
        }

        public static function prefixes(): array
        {
            return self::PREFIXES;
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
