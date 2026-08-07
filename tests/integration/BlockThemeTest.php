<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;

final class BlockThemeTest extends TestCase
{
    private Application $app;
    private string $fixture;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->fixture = dirname(__DIR__) . '/fixtures/block-theme';
    }

    public function testFullScanFindsAllExpectedIssues(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('block_theme_orphaned_util', $output);
        self::assertStringContainsString('block_theme_unused_hook', $output);
        self::assertStringContainsString('parts/orphaned-section', $output);
    }

    public function testBlockJsonRenderFileIsNotReportedAsUnused(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=templates']);
        $output = ob_get_clean();

        // parts/my-block-render.php is declared in block.json render field
        self::assertStringNotContainsString('my-block-render', $output);
    }

    public function testUnusedFunctionDetected(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=functions']);
        $output = ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('block_theme_orphaned_util', $output);
    }

    public function testUnusedHookDetected(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=hooks']);
        $output = ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('block_theme_unused_hook', $output);
    }

    public function testModeDetectedAsHybrid(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();

        // block-theme has both theme.json and functions.php → Hybrid
        self::assertStringContainsString('Hybrid', $output);
    }
}
