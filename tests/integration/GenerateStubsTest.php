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

        $data = $this->readJson($output_file);
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

        $data = $this->readJson($output_file);
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

        $data = $this->readJson($output_file);
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

        $data = $this->readJson($output_file);
        self::assertSame(1, count(array_filter($data['hooks'], fn($h) => $h === 'repeated_hook')));
    }

    public function testVariableHookTagResolvesInGeneratedStubs(): void
    {
        // $hook's last-known literal value ('dynamic_hook') resolves the tag exactly the same
        // way a literal directly in the call already would, so it's now captured too.
        file_put_contents($this->tmp . '/plugin.php', "<?php
\$hook = 'dynamic_hook';
do_action(\$hook);
do_action('literal_hook');
");
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        $data = $this->readJson($output_file);
        self::assertContains('dynamic_hook', $data['hooks']);
        self::assertContains('literal_hook', $data['hooks']);
    }

    public function testUnresolvableDynamicHookTagsAreSkipped(): void
    {
        // $hook comes from a function call, not a literal assignment -- genuinely no way to know
        // what it is, so this must still be skipped rather than guessed at.
        file_put_contents($this->tmp . '/plugin.php', "<?php
\$hook = get_dynamic_tag();
do_action(\$hook);
do_action('literal_hook');
");
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        $data = $this->readJson($output_file);
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
        self::assertIsString($output);

        self::assertStringContainsString('2', $output);
        self::assertStringContainsString($output_file, $output);
    }

    public function testDiscoversDynamicHookPrefix(): void
    {
        // ACF's real shape: every acf/settings/* filter fires through one dynamic dispatcher,
        // so no individual tag ever appears as a literal string anywhere in the source.
        file_put_contents($this->tmp . '/plugin.php', '<?php
function acf_get_setting($name, $value = null) {
    return apply_filters("acf/settings/{$name}", $value);
}
');
        $output_file = $this->tmp . '/stubs.json';

        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $this->tmp, "--output={$output_file}"]);
        ob_get_clean();

        $data = $this->readJson($output_file);
        self::assertArrayHasKey('prefixes', $data);
        self::assertContains('acf/settings/', $data['prefixes']);
        self::assertEmpty($data['hooks']);
    }

    public function testStubsFilePrefixSuppressesHooksInScan(): void
    {
        // Same scenario as testStubsFileSuppressesHooksInScan, but for a hook family fired only
        // through a dynamic dispatcher in the "plugin" dir — generate-stubs can only capture a
        // prefix here, never an exact tag.
        $themeDir = $this->tmp . '/theme';
        $pluginDir = $this->tmp . '/plugin';
        mkdir($themeDir, 0755, true);
        mkdir($pluginDir, 0755, true);

        file_put_contents($themeDir . '/style.css', "/*\nTheme Name: Test\n*/");
        file_put_contents($themeDir . '/functions.php', "<?php
add_filter('acf/settings/save_json', 'my_handler');
");
        file_put_contents($pluginDir . '/plugin.php', '<?php
function acf_get_setting($name, $value = null) {
    return apply_filters("acf/settings/{$name}", $value);
}
');

        $stubsFile = $this->tmp . '/stubs.json';
        ob_start();
        $this->app->run(['wp-specter', 'generate-stubs', $pluginDir, "--output={$stubsFile}"]);
        ob_get_clean();

        ob_start();
        $this->app->run(['wp-specter', 'scan', $themeDir, '--no-color', '--type=hooks']);
        $outputWithout = ob_get_clean();
        self::assertIsString($outputWithout);

        ob_start();
        $this->app->run(['wp-specter', 'scan', $themeDir, '--no-color', '--type=hooks', "--stubs={$stubsFile}"]);
        $outputWith = ob_get_clean();
        self::assertIsString($outputWith);

        self::assertStringContainsString('acf/settings/save_json', $outputWithout);
        self::assertStringNotContainsString('acf/settings/save_json', $outputWith);
    }

    public function testReturnsErrorForMissingDirectory(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'generate-stubs', '/nonexistent/path/xyz']);
        ob_get_clean();

        self::assertSame(2, $exit);
    }

    public function testDefaultOutputFilenameIsDotPrefixedToMatchAutoLoadConvention(): void
    {
        // With no --output and no project config, the default output filename must match the
        // dot-prefixed convention `scan` auto-loads (ProjectConfigLoader::STUBS_FILENAME) — not
        // the old undotted "wp-specter-stubs.json", which `scan` never picked up on its own.
        file_put_contents($this->tmp . '/plugin.php', "<?php\ndo_action('my_plugin_init');\n");

        $cwd = getcwd();
        self::assertIsString($cwd);
        chdir($this->tmp);
        try {
            ob_start();
            $exit = $this->app->run(['wp-specter', 'generate-stubs', $this->tmp]);
            ob_get_clean();
        } finally {
            chdir($cwd);
        }

        self::assertSame(0, $exit);
        self::assertFileExists($this->tmp . '/.wp-specter.stubs.json');
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
        self::assertIsString($outputWithout);

        // Scan theme WITH stubs — hook should be suppressed
        ob_start();
        $this->app->run(['wp-specter', 'scan', $themeDir, '--no-color', '--type=hooks', "--stubs={$stubsFile}"]);
        $outputWith = ob_get_clean();
        self::assertIsString($outputWith);

        self::assertStringContainsString('plugin_fires_this', $outputWithout);
        self::assertStringNotContainsString('plugin_fires_this', $outputWith);
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $data = json_decode($raw, true);
        self::assertIsArray($data);
        return $data;
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
