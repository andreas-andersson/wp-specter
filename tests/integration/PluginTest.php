<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;

final class PluginTest extends TestCase
{
    private Application $app;
    private string $fixture;

    protected function setUp(): void
    {
        $this->app = new Application();
        // Reuse the plugin sub-directory from the standard-wp fixture
        $this->fixture = dirname(__DIR__) . '/fixtures/standard-wp/plugins/test-plugin';
    }

    public function testDetectsUnusedFunction(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--target=plugin']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('stdwp_plugin_orphan', $output);
    }

    public function testUsedFunctionNotReported(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--target=plugin', '--type=functions']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringNotContainsString('stdwp_plugin_used_func', $output);
    }

    public function testTemplatesSkippedForPlugin(): void
    {
        // Deliberate, not a stale gap: TemplateAnalyzer's own hierarchy-exemption bug was fixed,
        // but actually running the check for Plugin mode was tried and reverted — real plugins
        // (confirmed: WooCommerce) commonly load their own templates/ directory through a custom
        // wrapper function (wc_get_template()/wc_get_template_part(), not the WP-core template
        // functions this parser tracks), so enabling the check produced more false positives than
        // genuine catches. See TODO.md's "Template detection" section.
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--target=plugin']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringNotContainsString('Unused Templates', $output);
    }

    public function testAutoDetectRecognisesPlugin(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringContainsString('Plugin', $output);
    }

    public function testTargetFlagOverridesAutoDetect(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--target=plugin']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringNotContainsString('Layout', $output);
    }

    public function testInvalidTargetReturnsError(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--target=invalid']);
        ob_get_clean();

        self::assertSame(2, $exit);
    }
}
