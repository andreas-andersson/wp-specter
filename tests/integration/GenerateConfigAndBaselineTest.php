<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;
use WpSpecter\ProjectConfig\ProjectConfigLoader;

final class GenerateConfigAndBaselineTest extends TestCase
{
    private Application $app;
    private string $tmp;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->tmp = sys_get_temp_dir() . '/wp-specter-genconfig-' . uniqid();

        $this->write('functions.php', "<?php
function used_fn() { return 1; }
function unused_fn() { return 2; }
add_action( 'init', 'used_fn' );
add_action( 'never_fired_hook', 'used_fn' );
");
        // realpath() it now that it exists — the app resolves scan targets with realpath()
        // internally, and on macOS sys_get_temp_dir() returns a path through the /var ->
        // /private/var symlink, which would otherwise make expected/actual paths diverge only
        // by that unresolved prefix.
        $this->tmp = realpath($this->tmp) ?: $this->tmp;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testGenerateBaselineErrorsWithoutConfig(): void
    {
        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-baseline']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Run with --generate-config first', $output);
        self::assertFileDoesNotExist($this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME);
    }

    public function testGenerateConfigWritesResolvedTarget(): void
    {
        // Run from the project root (== $this->tmp here) — the realistic invocation pattern.
        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-config']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Config written to', $output);

        $configFile = $this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME;
        self::assertFileExists($configFile);

        $data = $this->readJson($configFile);
        self::assertSame([$this->tmp], $data['targets']);
        self::assertArrayNotHasKey('baseline', $data);
    }

    public function testGenerateConfigErrorsWhenCwdIsNotAncestorOfScannedTarget(): void
    {
        // Deliberately not chdir'd into $this->tmp — cwd is wherever the test runner started
        // (this repo), which is unrelated to $this->tmp. Writing the config to cwd here would
        // produce a file ProjectConfigLoader could never find again on a later scan.
        [$exit, $output] = $this->runApp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-config']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('is not an ancestor of the scanned path', $output);
        self::assertFileDoesNotExist($this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME);
    }

    public function testGenerateConfigWritesToCwdNotComposerRoot(): void
    {
        // A composer-detected project root used to win over cwd here. It shouldn't: a scanned
        // target with its own nested composer.json (a Roots Sage theme requiring Acorn, say)
        // would make detection stop there instead of at the real outer project, silently writing
        // the config file inside the theme. cwd is simple and predictable instead — same
        // convention generate-stubs' default output already uses — as long as it's an ancestor
        // of every scanned target.
        $project = $this->tmp . '/project';
        $this->writeJson('project/composer.json', [
            'extra' => ['installer-paths' => [
                'wp-content/themes/{$name}/' => ['type:wordpress-theme'],
                'wp-content/plugins/{$name}/' => ['type:wordpress-plugin'],
            ]],
        ]);
        $this->writeJson('project/vendor/composer/installed.json', ['packages' => []]);
        $this->write('project/wp-content/themes/mytheme/style.css', "/*\nTheme Name: My Theme\n*/");
        $this->write('project/wp-content/themes/mytheme/functions.php', '<?php function theme_unused() { return 1; }');

        // Run from $this->tmp — an ancestor of $project, but not $project (the composer root)
        // itself — so the two locations can't coincidentally agree.
        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $project, '--no-color', '--generate-config']);

        self::assertSame(0, $exit);
        $configFile = $this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME;
        self::assertStringContainsString($configFile, $output);
        self::assertFileExists($configFile);
        self::assertFileDoesNotExist($project . '/' . ProjectConfigLoader::CONFIG_FILENAME);

        $data = $this->readJson($configFile);
        self::assertContains('project/wp-content/themes/mytheme', $data['targets']);
    }

    public function testGenerateConfigErrorsWhenCwdIsNotAnAncestorOfTarget(): void
    {
        // cwd is unrelated to the scanned target entirely — ProjectConfigLoader only ever walks
        // upward from a scan path, so writing the config anywhere else would make it permanently
        // undiscoverable on the next run. Must error instead of silently picking somewhere else.
        $project = $this->tmp . '/project';
        $this->write('project/wp-content/themes/mytheme/style.css', "/*\nTheme Name: My Theme\n*/");
        $unrelated = $this->tmp . '/unrelated';
        mkdir($unrelated, 0755, true);

        $cwd = getcwd();
        self::assertIsString($cwd);
        chdir($unrelated);
        try {
            [$exit, $output] = $this->runApp(['wp-specter', 'scan', $project . '/wp-content/themes/mytheme', '--no-color', '--generate-config']);
        } finally {
            chdir($cwd);
        }

        self::assertSame(2, $exit);
        self::assertStringContainsString('not an ancestor', $output);
        self::assertFileDoesNotExist($unrelated . '/' . ProjectConfigLoader::CONFIG_FILENAME);
    }

    public function testGenerateConfigErrorsWhenAlreadyExists(): void
    {
        $configJson = json_encode(['targets' => []]);
        self::assertIsString($configJson);
        $this->write(ProjectConfigLoader::CONFIG_FILENAME, $configJson);

        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-config']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('already exists', $output);
    }

    public function testGenerateBaselineWritesFindingsAndSubsequentScanSuppressesThem(): void
    {
        $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-config']);

        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-baseline']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Baseline written to', $output);

        $data = $this->readJson($this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME);
        self::assertArrayHasKey('baseline', $data);
        $names = array_column($data['baseline'], 'name');
        self::assertContains('unused_fn', $names);
        self::assertContains('never_fired_hook', $names);

        // Baselined file paths are relative to the config dir, not absolute.
        self::assertSame('functions.php', $data['baseline'][0]['file']);

        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('All clear.', $output);
        self::assertStringContainsString('2 finding(s) suppressed by baseline', $output);
    }

    public function testGenerateBaselineRespectsTypeFlag(): void
    {
        $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-config']);
        $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-baseline', '--type=functions']);

        $data = $this->readJson($this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME);
        $names = array_column($data['baseline'], 'name');
        self::assertContains('unused_fn', $names);
        self::assertNotContains('never_fired_hook', $names); // --type=functions excluded hooks

        // A plain --type=hooks scan afterward still reports the never-baselined hook.
        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--type=hooks']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('never_fired_hook', $output);
    }

    public function testGenerateConfigAndGenerateBaselineTogetherErrors(): void
    {
        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', $this->tmp, '--no-color', '--generate-config', '--generate-baseline']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('cannot be used together', $output);
    }

    /** @return array<string,mixed> */
    private function readJson(string $file): array
    {
        $raw = file_get_contents($file);
        self::assertIsString($raw);
        $data = json_decode($raw, true);
        self::assertIsArray($data);
        return $data;
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string}
     */
    private function runApp(array $argv): array
    {
        ob_start();
        $exit = $this->app->run($argv);
        $output = ob_get_clean();
        self::assertIsString($output);
        return [$exit, $output];
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string}
     */
    private function runFromTmp(array $argv): array
    {
        $cwd = getcwd();
        self::assertIsString($cwd);
        chdir($this->tmp);
        try {
            return $this->runApp($argv);
        } finally {
            chdir($cwd);
        }
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->tmp . '/' . $relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    /** @param array<mixed> $data */
    private function writeJson(string $relative, array $data): void
    {
        $json = json_encode($data);
        self::assertIsString($json);
        $this->write($relative, $json);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
