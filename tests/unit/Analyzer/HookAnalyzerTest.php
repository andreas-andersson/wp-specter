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

    public function testVariableHookTagResolvesAndReportsAsUnmatched(): void
    {
        // $tag's last-known literal value ('dynamic_hook') resolves the tag exactly the same way
        // a literal directly in the call already would — since nothing fires it anywhere, this
        // is now a real, reportable unmatched hook rather than a silent blind spot.
        $file = $this->write("<?php
\$tag = 'dynamic_hook';
add_action( \$tag, 'handler' );
");
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('dynamic_hook', $findings[0]->name);
    }

    public function testUnresolvableVariableHookTagStillSilentlySkipped(): void
    {
        // $tag comes from a function call, not a literal assignment -- genuinely no way to know
        // what it is, so this must still be silently skipped rather than reported.
        $file = $this->write('<?php
$tag = get_dynamic_tag();
add_action( $tag, "handler" );
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testFullyQualifiedAddActionCallIsRecognized(): void
    {
        // \add_action(...) -- a namespaced file explicitly opting out of its own namespace for a
        // WP core global. Before this fix, the whole hook-detection dispatch only ever ran for a
        // bare (non-namespaced) call.
        $file = $this->write('<?php
namespace My_Theme;
\add_action( "my_namespaced_hook", "handler" );
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('my_namespaced_hook', $findings[0]->name);
    }

    public function testFullyQualifiedDoActionMatchesAFullyQualifiedAddAction(): void
    {
        $file = $this->write('<?php
namespace My_Theme;
\add_action( "my_namespaced_hook", "handler" );
\do_action( "my_namespaced_hook" );
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testClassConstantHookTagResolvesAndReportsAsUnmatched(): void
    {
        // const HOOK_NAME = 'my_plugin_loaded'; add_action(self::HOOK_NAME, ...) -- resolves the
        // same way a literal directly in the call already would; nothing fires it, so this is a
        // real, reportable unmatched hook.
        $file = $this->write('<?php
class My_Plugin {
    const HOOK_NAME = "my_plugin_loaded";
    public function register() {
        add_action( self::HOOK_NAME, "handler" );
    }
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('my_plugin_loaded', $findings[0]->name);
    }

    public function testDoesNotReportClassConstantHookMatchedByALiteralDoAction(): void
    {
        $file = $this->write('<?php
class My_Plugin {
    const HOOK_NAME = "my_plugin_loaded";
    public function register() {
        add_action( self::HOOK_NAME, "handler" );
    }
}
do_action( "my_plugin_loaded" );
');
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

    // ── Action Scheduler (widely-bundled standalone library — WooCommerce, wpforms-lite, ...) ──

    public function testAsEnqueueAsyncActionCountsAsFiring(): void
    {
        $file = $this->write("<?php
add_action( 'my_async_task', 'handler' );
as_enqueue_async_action( 'my_async_task', array(), 'my-group' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testAsScheduleSingleActionCountsAsFiring(): void
    {
        // Real-world shape (WooCommerce): as_schedule_single_action(time()+10,
        // 'generate_category_lookup_table_wrapper', ...) — hook is the 2nd argument, not the 1st.
        $file = $this->write("<?php
add_action( 'generate_category_lookup_table_wrapper', 'handler' );
as_schedule_single_action( time() + 10, 'generate_category_lookup_table_wrapper' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testAsScheduleRecurringActionCountsAsFiring(): void
    {
        // Real-world shape (WooCommerce): as_schedule_recurring_action($tomorrow_3am,
        // DAY_IN_SECONDS, 'wc_admin_daily_wrapper', ...) — hook is the 3rd argument.
        $file = $this->write("<?php
add_action( 'wc_admin_daily_wrapper', 'handler' );
as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'wc_admin_daily_wrapper', array(), 'woocommerce', true );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testAsScheduleCronActionCountsAsFiring(): void
    {
        $file = $this->write("<?php
add_action( 'my_cron_task', 'handler' );
as_schedule_cron_action( time(), '*/5 * * * *', 'my_cron_task' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testCronScheduleFuncPassThroughWrapperCountsAsFiring(): void
    {
        // Real-world shape (WooCommerce, includes/class-wc-post-data.php): a private wrapper
        // takes the hook name as its own parameter and passes it through unchanged to
        // as_schedule_single_action() — the hook fires later inside Action Scheduler, never via a
        // literal argument visible at that call site itself. The literal only appears at the
        // wrapper's own call site, in a different file than the wrapper's declaration.
        $wrapper = $this->write('<?php
class WC_Post_Data {
    private static function schedule_variation_summary_regeneration( $action_name, $timestamp, $args, $group ) {
        as_schedule_single_action( $timestamp, $action_name, $args, $group );
    }
}
');
        $caller = $this->write('<?php
add_action( "wc_regenerate_attribute_variation_summaries", "handler" );
WC_Post_Data::schedule_variation_summary_regeneration( "wc_regenerate_attribute_variation_summaries", time(), array(), "woocommerce-db-updates" );
');
        self::assertEmpty($this->analyzer->analyze([$wrapper, $caller]));
    }

    public function testClosureHookPassThroughParamCountsAsFiring(): void
    {
        // Real-world shape (WooCommerce, includes/wc-product-functions.php:575-611): a local
        // closure — not a named function or method — takes the hook name as its own parameter
        // and passes it through unchanged to as_schedule_single_action(). Entirely local (a
        // closure has no way to leak its identity to another file), so declaration and call
        // sites are always in the same file, unlike the named-wrapper case above.
        $file = $this->write('<?php
add_action( "wc_product_start_scheduled_sale", "handler" );
add_action( "wc_product_end_scheduled_sale", "handler" );
function wc_schedule_product_sale_events( $product ) {
    $schedule = function ( $date, $hook ) {
        as_schedule_single_action( time(), $hook, array(), "woocommerce-sales" );
    };
    $schedule( $product->get_date_on_sale_from( "edit" ), "wc_product_start_scheduled_sale" );
    $schedule( $product->get_date_on_sale_to( "edit" ), "wc_product_end_scheduled_sale" );
}
');
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

    // ── dynamic hook dispatchers (interpolated / concatenated tag prefixes) ─

    public function testRegistrationMatchedByDynamicInvocationPrefixInSameProject(): void
    {
        // One dynamic dispatcher firing every acf/settings/* hook, ACF's actual shape.
        $file = $this->write('<?php
add_filter( "acf/settings/save_json", "handler" );
function acf_get_setting($name, $value = null) {
    return apply_filters("acf/settings/{$name}", $value);
}
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testRegistrationNotMatchedByUnrelatedDynamicPrefix(): void
    {
        $file = $this->write('<?php
add_filter( "acf/other/thing", "handler" );
function acf_get_setting($name, $value = null) {
    return apply_filters("acf/settings/{$name}", $value);
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('acf/other/thing', $findings[0]->name);
    }

    public function testRegistrationMatchedByDynamicInvocationSuffixInSameProject(): void
    {
        // WP_Widget's own real shape: do_action("{$this->id_base}_widget_updated") -- dynamic
        // first, literal last, the mirror of the ACF prefix case above.
        $file = $this->write('<?php
class My_Widget {
    public $id_base = "my_widget";
    public function update_callback() {
        do_action("{$this->id_base}_widget_updated");
    }
}
add_action( "my_widget_widget_updated", "handler" );
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testRegistrationNotMatchedByUnrelatedDynamicSuffix(): void
    {
        $file = $this->write('<?php
class My_Widget {
    public $id_base = "my_widget";
    public function update_callback() {
        do_action("{$this->id_base}_widget_updated");
    }
}
add_action( "totally_unrelated_hook", "handler" );
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('totally_unrelated_hook', $findings[0]->name);
    }

    public function testCustomStubsPrefixSuppressesHook(): void
    {
        // What generate-stubs writes after scanning a vendor plugin whose hooks fire through a
        // dynamic dispatcher — no exact tags known, only the prefix.
        $stubsFile = $this->tmp . '/project-stubs.json';
        file_put_contents($stubsFile, json_encode([
            'hooks' => [],
            'prefixes' => ['acf/settings/'],
        ]));
        StubRegistry::loadFile($stubsFile);

        $file = $this->write("<?php add_filter( 'acf/settings/save_json', 'handler' );");

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
