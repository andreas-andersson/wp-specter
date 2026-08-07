<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;

final class GenerateStubsTest extends TestCase
{
    private Application $app;
    private string $tmp;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->tmp = sys_get_temp_dir() . '/wp-specter-genstubs-' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testWritesJsonFileWithDiscoveredHooks(): void
    {
        file_put_contents($this->tmp . '/plugin.php', "<?php
do_action('my_plugin_init');
apply_filters('my_plugin_content', \$content);
");
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $exit = $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        self::assertSame(0, $exit);
        self::assertFileExists($output_file);

        $data = json_decode(file_get_contents($output_file), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('hooks', $data);
        self::assertContains('my_plugin_init', $data['hooks']);
        self::assertContains('my_plugin_content', $data['hooks']);
    }

    public function testHooksAreSortedAlphabetically(): void
    {
        file_put_contents($this->tmp . '/plugin.php', "<?php
do_action('zebra_hook');
do_action('alpha_hook');
do_action('middle_hook');
");
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        $data = json_decode(file_get_contents($output_file), true);
        $hooks = $data['hooks'];
        $sorted = $hooks;
        sort($sorted);
        self::assertSame($sorted, $hooks, 'Hooks should be sorted alphabetically');
    }

    public function testOutputIncludesMetadata(): void
    {
        file_put_contents($this->tmp . '/plugin.php', '<?php do_action("hook");');
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        $data = json_decode(file_get_contents($output_file), true);
        self::assertArrayHasKey('generated', $data);
        self::assertArrayHasKey('source', $data);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['generated']);
    }

    public function testDeduplicatesHooks(): void
    {
        file_put_contents($this->tmp . '/plugin.php', "<?php
do_action('repeated_hook');
do_action('repeated_hook');
do_action('repeated_hook');
");
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        $data = json_decode(file_get_contents($output_file), true);
        self::assertSame(1, count(array_filter($data['hooks'], fn($h) => $h === 'repeated_hook')));
    }

    public function testDynamicHookTagsAreSkipped(): void
    {
        file_put_contents($this->tmp . '/plugin.php', "<?php
\$hook = 'dynamic_hook';
do_action(\$hook);
do_action('literal_hook');
");
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        $data = json_decode(file_get_contents($output_file), true);
        self::assertNotContains('dynamic_hook', $data['hooks']);
        self::assertContains('literal_hook', $data['hooks']);
    }

    public function testPrintsCountToStdout(): void
    {
        file_put_contents($this->tmp . '/plugin.php', "<?php
do_action('hook_one');
do_action('hook_two');
");
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        $output = ob_get_clean();

        self::assertStringContainsString('2', $output);
        self::assertStringContainsString($output_file, $output);
    }

    public function testReturnsErrorForMissingDirectory(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'generate-stubs', '/nonexistent/path/xyz']);
        ob_get_clean();

        self::assertSame(2, $exit);
    }

    public function testStubsFileSuppressesHooksInScan(): void
    {
        // Setup: theme with a hook fired only in a sibling "plugin" dir
        $themeDir = $this->tmp . '/theme';
        $pluginDir = $this->tmp . '/plugin';
        mkdir($themeDir, 0755, true);
        mkdir($pluginDir, 0755, true);

        file_put_contents($themeDir . '/style.css', "/*\nTheme Name: Test\n*/");
        file_put_contents($themeDir . '/functions.php', "<?php
add_action('plugin_fires_this', 'my_handler');
");
        file_put_contents($pluginDir . '/plugin.php', "<?php
do_action('plugin_fires_this');
");

        // Generate stubs from plugin dir
        $stubsFile = $this->tmp . '/stubs.json';
        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $pluginDir, "--output={$stubsFile}"]);
        ob_get_clean();

        // Scan theme WITHOUT stubs — hook should be reported
        ob_start();
        $this->app->run(['wp-specter', 'scan', $themeDir, '--no-color', '--type=hooks']);
        $outputWithout = ob_get_clean();

        // Scan theme WITH stubs — hook should be suppressed
        ob_start();
        $this->app->run(['wp-specter', 'scan', $themeDir, '--no-color', '--type=hooks', "--stubs={$stubsFile}"]);
        $outputWith = ob_get_clean();

        self::assertStringContainsString('plugin_fires_this', $outputWithout);
        self::assertStringNotContainsString('plugin_fires_this', $outputWith);
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
