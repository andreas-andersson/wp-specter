<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;

final class BedrockProjectTest extends TestCase
{
    private Application $app;
    private string $fixture;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->fixture = dirname(__DIR__) . '/fixtures/bedrock-project';
    }

    public function testProjectRootAutoDiscoversCustomTargets(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('composer-managed', $output);
        self::assertStringContainsString('test-theme', $output);
        self::assertStringContainsString('test-plugin', $output);
        self::assertStringContainsString('mu-plugins', $output);
    }

    public function testFindsIssuesAcrossAllDiscoveredTargets(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();

        self::assertStringContainsString('bedrock_orphaned_func', $output);       // theme
        self::assertStringContainsString('bedrock_plugin_orphan', $output);       // plugin
        self::assertStringContainsString('bedrock_mu_orphaned_func', $output);    // loose mu-plugin file
        self::assertStringContainsString('bedrock_unused_hook', $output);
        self::assertStringContainsString('template-parts/orphaned-section', $output);
    }

    public function testVendorInstalledThemeIsExcluded(): void
    {
        ob_start();
        $output = null;
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();

        // vendor-theme is declared in vendor/composer/installed.json as composer-installed —
        // its content must never be scanned or listed as a discovered target.
        self::assertStringNotContainsString('vendor_theme_orphaned_func', $output);
        self::assertStringNotContainsString('vendor-theme', $output);
    }

    public function testCrossTargetHookMatchingAvoidsFalsePositive(): void
    {
        // test-theme registers 'bedrock_cross_target_hook'; test-plugin fires it. Scanning
        // either directory alone would show it as unmatched — only a whole-project scan sees
        // both sides and correctly resolves it.
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=hooks']);
        $output = ob_get_clean();

        self::assertSame(1, $exit); // only bedrock_unused_hook, which is genuinely unfired
        self::assertStringContainsString('bedrock_unused_hook', $output);
        self::assertStringNotContainsString('bedrock_cross_target_hook', $output);
    }

    public function testPointingDirectlyAtOneTargetScansOnlyThatOne(): void
    {
        ob_start();
        $exit = $this->app->run([
            'wp-specter', 'scan', $this->fixture . '/web/app/themes/test-theme', '--no-color',
        ]);
        $output = ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringNotContainsString('composer-managed', $output); // single-target header, not project header
        self::assertStringNotContainsString('bedrock_plugin_orphan', $output); // test-plugin's own finding, out of scope
        self::assertStringContainsString('bedrock_orphaned_func', $output);
    }
}
