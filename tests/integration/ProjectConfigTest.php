<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;
use WpSpecter\ProjectConfig\ProjectConfigLoader;

final class ProjectConfigTest extends TestCase
{
    private Application $app;
    private string $tmp;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->tmp = sys_get_temp_dir() . '/wp-specter-projectcfg-' . uniqid();

        // theme/ — the only configured target
        $this->write('theme/style.css', "/*\nTheme Name: Test\n*/");
        $this->write('theme/functions.php', "<?php
function configured_orphaned_func() {}
add_action( 'vendor_fired_hook', 'configured_handler' );
function configured_handler() {}
");

        // other-plugin/ — present on disk but NOT listed in config targets; must never be scanned
        $this->write('other-plugin/other-plugin.php', '<?php
/*
Plugin Name: Other Plugin
*/
function other_plugin_orphan() {}
');

        // vendor-plugins/ — only referenced via stubsFrom, not a scan target
        $this->write('vendor-plugins/a-plugin.php', "<?php
do_action( 'vendor_fired_hook' );
");

        $configJson = json_encode([
            'targets' => ['theme'],
            'stubsFrom' => ['vendor-plugins'],
        ]);
        self::assertIsString($configJson);
        $this->write(ProjectConfigLoader::CONFIG_FILENAME, $configJson);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testScanUsesOnlyConfiguredTargets(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->tmp, '--no-color']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('configured via ' . ProjectConfigLoader::CONFIG_FILENAME, $output);
        self::assertStringContainsString('configured_orphaned_func', $output);
        self::assertStringNotContainsString('other_plugin_orphan', $output); // not a configured target
    }

    public function testHookFiredOnlyInStubsFromDirIsReportedWithoutGeneratedStubs(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->tmp, '--no-color', '--type=hooks']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringContainsString('vendor_fired_hook', $output);
    }

    public function testExcludePrunesConfiguredDirectory(): void
    {
        $this->write('theme/tests/FixtureTest.php', '<?php
function should_not_be_scanned() {}
');

        $configJson = json_encode([
            'targets' => ['theme'],
            'stubsFrom' => ['vendor-plugins'],
            'exclude' => ['tests'],
        ]);
        self::assertIsString($configJson);
        $this->write(ProjectConfigLoader::CONFIG_FILENAME, $configJson);

        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->tmp, '--no-color']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('configured_orphaned_func', $output);
        self::assertStringNotContainsString('should_not_be_scanned', $output);
    }

    public function testGenerateStubsWithNoPathUsesConfiguredStubsFrom(): void
    {
        $cwd = getcwd();
        self::assertIsString($cwd);
        chdir($this->tmp);
        try {
            ob_start();
            $exit = $this->app->run(['wp-specter', 'generate-stubs']);
            $output = ob_get_clean();
            self::assertIsString($output);
        } finally {
            chdir($cwd);
        }

        self::assertSame(0, $exit);
        $stubsFile = $this->tmp . '/' . ProjectConfigLoader::STUBS_FILENAME;
        self::assertFileExists($stubsFile);
        self::assertStringContainsString($stubsFile, $output);

        $raw = file_get_contents($stubsFile);
        self::assertIsString($raw);
        $data = json_decode($raw, true);
        self::assertIsArray($data);
        self::assertContains('vendor_fired_hook', $data['hooks']);
    }

    public function testScanAutoLoadsGeneratedProjectStubsFile(): void
    {
        file_put_contents(
            $this->tmp . '/' . ProjectConfigLoader::STUBS_FILENAME,
            json_encode(['hooks' => ['vendor_fired_hook']]),
        );

        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->tmp, '--no-color', '--type=hooks']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('vendor_fired_hook', $output);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->tmp . '/' . $relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
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
