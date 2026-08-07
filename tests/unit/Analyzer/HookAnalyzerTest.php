<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\HookAnalyzer;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\Stubs\StubRegistry;

final class HookAnalyzerTest extends TestCase
{
    private string $tmp;
    private HookAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-ha-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->analyzer = new HookAnalyzer(new PhpTokenParser());
        $this->resetRegistry();
    }

    protected function tearDown(): void
    {
        $this->resetRegistry();
        $this->removeDir($this->tmp);
    }

    public function testReportsHookNotFiredWithinProject(): void
    {
        $file = $this->write("<?php add_action( 'my_custom_hook', 'handler' );");

        $findings = $this->analyzer->analyze([$file]);

        self::assertCount(1, $findings);
        self::assertSame('my_custom_hook', $findings[0]->name);
        self::assertSame(FindingCertainty::Warning, $findings[0]->certainty);
    }

    public function testDoesNotReportHookFiredWithinProject(): void
    {
        $file = $this->write("<?php
add_action( 'my_hook', 'handler' );
do_action( 'my_hook' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testAddFilterHandledSameAsAddAction(): void
    {
        $file = $this->write("<?php add_filter( 'my_filter', 'cb' );");

        $findings = $this->analyzer->analyze([$file]);

        self::assertCount(1, $findings);
        self::assertSame('my_filter', $findings[0]->name);
    }

    public function testApplyFiltersHandledSameAsDoAction(): void
    {
        $file = $this->write("<?php
add_filter( 'my_filter', 'cb' );
\$x = apply_filters( 'my_filter', 'default' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testDynamicTagSkipped(): void
    {
        $file = $this->write("<?php
\$tag = 'dynamic_hook';
add_action( \$tag, 'handler' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testHookFiredInDifferentFile(): void
    {
        $file1 = $this->write("<?php add_action( 'cross_file_hook', 'handler' );");
        $file2 = $this->write("<?php do_action( 'cross_file_hook' );");

        self::assertEmpty($this->analyzer->analyze([$file1, $file2]));
    }

    // ── WP-Cron scheduling ──────────────────────────────────────────────────

    public function testWpScheduleEventCountsAsFiring(): void
    {
        $file = $this->write("<?php
add_action( 'storm_cron', 'handler' );
wp_schedule_event( time(), 'hourly', 'storm_cron', array( false ) );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testWpScheduleSingleEventCountsAsFiring(): void
    {
        $file = $this->write("<?php
add_action( 'one_off_cron', 'handler' );
wp_schedule_single_event( time(), 'one_off_cron' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    // ── WP core stub suppression ───────────────────────────────────────────

    public function testWpCoreHooksSilentlyIgnored(): void
    {
        // 'init' is a WP core hook — should not be reported even without do_action
        $file = $this->write("<?php add_action( 'init', 'my_handler' );");

        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testWpCoreWpHeadSilentlyIgnored(): void
    {
        $file = $this->write("<?php add_action( 'wp_head', 'handler' );");

        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testUseWidgetsBlockEditorHookSilentlyIgnored(): void
    {
        $file = $this->write("<?php
add_filter( 'use_widgets_block_editor', '__return_false' );
add_filter( 'gutenberg_use_widgets_block_editor', '__return_false' );
add_filter( 'should_load_separate_core_block_assets', '__return_true' );
");

        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testWpAjaxPrefixHookSilentlyIgnored(): void
    {
        // wp_ajax_* is a dynamic-prefix WP core hook
        $file = $this->write("<?php add_action( 'wp_ajax_my_action', 'handler' );");

        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testOptionPrefixHookSilentlyIgnored(): void
    {
        $file = $this->write("<?php add_filter( 'option_my_setting', 'handler' );");

        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    // ── custom --stubs file ────────────────────────────────────────────────

    public function testCustomStubsFileSuppressesHook(): void
    {
        $stubsFile = $this->tmp . '/project-stubs.json';
        file_put_contents($stubsFile, json_encode([
            'hooks' => ['my_plugin_fired_hook'],
        ]));
        StubRegistry::loadFile($stubsFile);

        $file = $this->write("<?php add_action( 'my_plugin_fired_hook', 'handler' );");

        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testHookNotInStubsIsStillReported(): void
    {
        $stubsFile = $this->tmp . '/project-stubs.json';
        file_put_contents($stubsFile, json_encode(['hooks' => ['some_other_hook']]));
        StubRegistry::loadFile($stubsFile);

        $file = $this->write("<?php add_action( 'unreported_hook', 'handler' );");

        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('unreported_hook', $findings[0]->name);
    }

    private function resetRegistry(): void
    {
        $ref = new \ReflectionClass(StubRegistry::class);
        $ref->getProperty('hookIndex')->setValue(null, null);
        $ref->getProperty('prefixes')->setValue(null, null);
    }

    private function write(string $code): string
    {
        $file = $this->tmp . '/test_' . uniqid() . '.php';
        file_put_contents($file, $code);
        return $file;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
