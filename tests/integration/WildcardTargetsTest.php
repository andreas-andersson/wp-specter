<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;
use WpSpecter\ProjectConfig\ProjectConfigLoader;

final class WildcardTargetsTest extends TestCase
{
    private Application $app;
    private string $tmp;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->tmp = sys_get_temp_dir() . '/wp-specter-wildcard-' . uniqid();

        $this->write('plugins/custom-analytics/custom-analytics.php', '<?php
/*
Plugin Name: Custom Analytics
*/
function analytics_unused() { return 1; }
');
        $this->write('plugins/custom-seo/custom-seo.php', '<?php
/*
Plugin Name: Custom SEO
*/
function seo_unused() { return 1; }
');
        $this->write('plugins/vendor-thing/vendor-thing.php', '<?php
/*
Plugin Name: Vendor Thing
*/
function vendor_unused() { return 1; }
');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testCliWildcardPathScansOnlyMatchingDirs(): void
    {
        [$exit, $output] = $this->runApp([
            'wp-specter', 'scan', $this->tmp . '/plugins/custom-*', '--no-color', '--type=functions',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('analytics_unused', $output);
        self::assertStringContainsString('seo_unused', $output);
        self::assertStringNotContainsString('vendor_unused', $output);
    }

    public function testCliWildcardWithNoMatchesErrors(): void
    {
        [$exit, $output] = $this->runApp([
            'wp-specter', 'scan', $this->tmp . '/plugins/nope-*', '--no-color',
        ]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('No directories matched pattern', $output);
    }

    public function testGenerateConfigWithCliWildcardWritesPatternNotExpandedList(): void
    {
        [$exit, $output] = $this->runFromTmp([
            'wp-specter', 'scan', 'plugins/custom-*', '--no-color', '--generate-config',
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('pattern matching 2 target(s)', $output);

        $data = $this->readJson($this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME);
        self::assertSame(['plugins/custom-*'], $data['targets']);
    }

    public function testConfigWildcardTargetPicksUpDirectoryAddedLater(): void
    {
        $this->runFromTmp(['wp-specter', 'scan', 'plugins/custom-*', '--no-color', '--generate-config']);

        $this->write('plugins/custom-cache/custom-cache.php', '<?php
/*
Plugin Name: Custom Cache
*/
function cache_unused() { return 1; }
');

        [$exit, $output] = $this->runFromTmp(['wp-specter', 'scan', '--no-color', '--type=functions']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('analytics_unused', $output);
        self::assertStringContainsString('seo_unused', $output);
        self::assertStringContainsString('cache_unused', $output);
        self::assertStringNotContainsString('vendor_unused', $output);
    }

    public function testGenerateConfigWithCliWildcardWritesToCwdNotComposerRoot(): void
    {
        $project = $this->tmp . '/project';
        $this->writeJson('project/composer.json', [
            'extra' => ['installer-paths' => [
                'wp-content/plugins/{$name}/' => ['type:wordpress-plugin'],
            ]],
        ]);
        $this->writeJson('project/vendor/composer/installed.json', ['packages' => []]);
        $this->write('project/wp-content/plugins/custom-a/custom-a.php', '<?php // a');
        $this->write('project/wp-content/plugins/custom-b/custom-b.php', '<?php // b');

        // Run from $this->tmp — an ancestor of $project, but not $project (the composer root)
        // itself — same reasoning as the non-wildcard case in GenerateConfigAndBaselineTest.
        [$exit, $output] = $this->runFromTmp([
            'wp-specter', 'scan', $project . '/wp-content/plugins/custom-*', '--no-color', '--generate-config',
        ]);

        self::assertSame(0, $exit);
        $configFile = $this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME;
        self::assertStringContainsString($configFile, $output);
        self::assertFileExists($configFile);
        self::assertFileDoesNotExist($project . '/' . ProjectConfigLoader::CONFIG_FILENAME);

        $data = $this->readJson($configFile);
        self::assertSame(['project/wp-content/plugins/custom-*'], $data['targets']);
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
