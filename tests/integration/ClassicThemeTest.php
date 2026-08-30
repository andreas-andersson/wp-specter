<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;

final class ClassicThemeTest extends TestCase
{
    private Application $app;
    private string $fixture;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->fixture = dirname(__DIR__) . '/fixtures/classic-theme';
    }

    public function testFullScanFindsAllExpectedIssues(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('classic_orphaned_helper', $output);
        self::assertStringContainsString('classic_unused_hook', $output);
        self::assertStringContainsString('template-parts/orphaned-card', $output);
    }

    public function testTypeFilterFunctionsOnlyReportsFunctions(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=functions']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('classic_orphaned_helper', $output);
        self::assertStringNotContainsString('classic_unused_hook', $output);
        self::assertStringNotContainsString('orphaned-card', $output);
    }

    public function testTypeFilterHooksOnlyReportsHooks(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=hooks']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('classic_unused_hook', $output);
        self::assertStringNotContainsString('classic_orphaned_helper', $output);
        self::assertStringNotContainsString('orphaned-card', $output);
    }

    public function testTypeFilterTemplatesOnlyReportsTemplates(): void
    {
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=templates']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('template-parts/orphaned-card', $output);
        self::assertStringNotContainsString('classic_orphaned_helper', $output);
        self::assertStringNotContainsString('classic_unused_hook', $output);
    }

    public function testNoColorFlagStripsAnsiCodes(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringNotContainsString("\e[", $output);
    }

    public function testNoProgressbarFlagIsAcceptedAndDoesNotChangeFindings(): void
    {
        // The flag's actual effect (forcing the progress bar off even in a real interactive
        // terminal) isn't observable from an in-process test — stdout is never a real TTY while
        // PHPUnit captures output regardless of this flag (see WP_SPECTER_NO_PROGRESS in
        // phpunit.xml, which already suppresses it for every test the same way). This only
        // confirms the flag parses as a recognized option and leaves the actual scan unaffected.
        ob_start();
        $exit = $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--no-progressbar']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('classic_orphaned_helper', $output);
    }

    public function testColorOutputContainsAnsiCodes(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture]);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringContainsString("\e[", $output);
    }

    public function testUsedFunctionIsNotReported(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=functions']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringNotContainsString('classic_theme_setup', $output);
        self::assertStringNotContainsString('classic_enqueue_scripts', $output);
    }

    public function testUsedTemplateIsNotReported(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color', '--type=templates']);
        $output = ob_get_clean();
        self::assertIsString($output);

        // loop.php and nav.php are referenced via get_template_part in index.php
        self::assertStringNotContainsString('loop', $output);
        self::assertStringNotContainsString('nav', $output);
    }

    public function testModeDetectedAsClassic(): void
    {
        ob_start();
        $this->app->run(['wp-specter', 'scan', $this->fixture, '--no-color']);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringContainsString('Classic theme', $output);
    }
}
