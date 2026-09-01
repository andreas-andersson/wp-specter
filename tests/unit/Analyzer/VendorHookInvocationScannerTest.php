<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\VendorHookInvocationScanner;

final class VendorHookInvocationScannerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-vhis-' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    public function testFindsLiteralTagsAcrossAllFourInvokeFunctions(): void
    {
        $file = $this->write("<?php
apply_filters( 'jetpack_sync_home_url', \$url );
do_action( 'jetpack_sync_ready', \$data );
apply_filters_ref_array( 'jetpack_sync_ref', \$args );
do_action_ref_array( 'jetpack_sync_do_ref', \$args );
");
        $tags = VendorHookInvocationScanner::scan([$file]);

        self::assertArrayHasKey('jetpack_sync_home_url', $tags);
        self::assertArrayHasKey('jetpack_sync_ready', $tags);
        self::assertArrayHasKey('jetpack_sync_ref', $tags);
        self::assertArrayHasKey('jetpack_sync_do_ref', $tags);
    }

    public function testFindsDoubleQuotedTagToo(): void
    {
        $file = $this->write('<?php apply_filters( "jetpack_sync_home_url", $url );');

        $tags = VendorHookInvocationScanner::scan([$file]);

        self::assertArrayHasKey('jetpack_sync_home_url', $tags);
    }

    public function testIgnoresAnUnrelatedFunctionCall(): void
    {
        $file = $this->write("<?php some_other_function( 'not_a_hook' );");

        self::assertSame([], VendorHookInvocationScanner::scan([$file]));
    }

    public function testEmptyFileListReturnsEmptyArray(): void
    {
        self::assertSame([], VendorHookInvocationScanner::scan([]));
    }

    private function write(string $code): string
    {
        $file = $this->tmp . '/' . uniqid() . '.php';
        file_put_contents($file, $code);
        return $file;
    }
}
