<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Stubs;

use PHPUnit\Framework\TestCase;
use WpSpecter\Stubs\StubRegistry;

final class StubRegistryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-stubs-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->resetRegistry();
    }

    protected function tearDown(): void
    {
        $this->resetRegistry();
        $this->removeDir($this->tmp);
    }

    // ── WP core exact hooks ────────────────────────────────────────────────

    public function testWpCoreInitHookIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('init'));
    }

    public function testWpCoreWpHeadHookIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('wp_head'));
    }

    public function testWpCoreWpEnqueueScriptsHookIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('wp_enqueue_scripts'));
    }

    public function testUnknownHookReturnsFalse(): void
    {
        self::assertFalse(StubRegistry::contains('my_totally_custom_action_xyz'));
    }

    // ── plugin hooks are no longer built in ─────────────────────────────────
    // Composer-project scanning and generate-stubs/stubsFrom can now produce accurate,
    // up-to-date stubs from the plugin's actual installed code — hardcoded per-plugin lists
    // (formerly ACF Pro, ElasticPress) would just go stale, so they were removed. Third-party
    // plugin hooks are only known if the project generates stubs for them.

    public function testThirdPartyPluginHookIsNotKnownByDefault(): void
    {
        self::assertFalse(StubRegistry::contains('acf/init'));
        self::assertFalse(StubRegistry::contains('ep_formatted_args'));
    }

    // ── WP core prefix matching ────────────────────────────────────────────

    public function testWpAjaxPrefixIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('wp_ajax_my_custom_action'));
    }

    public function testWpAjaxNoprivPrefixIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('wp_ajax_nopriv_my_custom_action'));
    }

    public function testOptionPrefixIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('option_my_setting'));
    }

    public function testSavePostPrefixIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('save_post_event'));
    }

    public function testPublishPrefixIsKnown(): void
    {
        self::assertTrue(StubRegistry::contains('publish_post'));
    }

    // ── loadFile ───────────────────────────────────────────────────────────

    public function testLoadFileAddsHooksFromJsonFile(): void
    {
        $file = $this->tmp . '/custom-stubs.json';
        file_put_contents($file, json_encode([
            'generated' => '2026-01-01',
            'source' => '/srv/plugins',
            'hooks' => ['my_plugin_action', 'my_plugin_filter'],
        ]));

        StubRegistry::loadFile($file);

        self::assertTrue(StubRegistry::contains('my_plugin_action'));
        self::assertTrue(StubRegistry::contains('my_plugin_filter'));
    }

    public function testLoadFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        StubRegistry::loadFile($this->tmp . '/nonexistent.json');
    }

    public function testLoadFileThrowsOnInvalidJson(): void
    {
        $file = $this->tmp . '/bad.json';
        file_put_contents($file, 'not json {{{');

        $this->expectException(\RuntimeException::class);
        StubRegistry::loadFile($file);
    }

    public function testLoadFileSupportsFlatArrayFormat(): void
    {
        $file = $this->tmp . '/flat-stubs.json';
        file_put_contents($file, json_encode(['flat_hook_one', 'flat_hook_two']));

        StubRegistry::loadFile($file);

        self::assertTrue(StubRegistry::contains('flat_hook_one'));
        self::assertTrue(StubRegistry::contains('flat_hook_two'));
    }

    public function testLoadFileAddsPrefixesFromJsonFile(): void
    {
        // What generate-stubs writes for a dynamically-dispatched hook family, e.g. ACF's
        // apply_filters("acf/settings/{$name}", ...) — no exact tag, just a prefix.
        $file = $this->tmp . '/prefix-stubs.json';
        file_put_contents($file, json_encode([
            'hooks' => [],
            'prefixes' => ['acf/settings/'],
        ]));

        StubRegistry::loadFile($file);

        self::assertTrue(StubRegistry::contains('acf/settings/save_json'));
        self::assertTrue(StubRegistry::contains('acf/settings/load_json'));
        self::assertFalse(StubRegistry::contains('acf/other/thing'));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function resetRegistry(): void
    {
        $ref = new \ReflectionClass(StubRegistry::class);

        $idx = $ref->getProperty('hookIndex');
        $idx->setValue(null, null);

        $pre = $ref->getProperty('prefixes');
        $pre->setValue(null, null);
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
