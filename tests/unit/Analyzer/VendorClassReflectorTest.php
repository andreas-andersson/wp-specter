<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\VendorClassReflector;

final class VendorClassReflectorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-vcr-' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testClassHasMethodResolvesAnOrdinaryVendorClass(): void
    {
        $vendorFile = $this->write('<?php
class My_Vendor_Class {
    public function doSomething() {}
}
');
        $reflector = new VendorClassReflector([$vendorFile]);
        self::assertTrue($reflector->classHasMethod('My_Vendor_Class', 'doSomething'));
        self::assertFalse($reflector->classHasMethod('My_Vendor_Class', 'nonexistentMethod'));
    }

    public function testAbspathGuardedVendorFileDoesNotKillTheScan(): void
    {
        // Real-world regression (WooCommerce): `defined('ABSPATH') || exit;` sits at the top of
        // 927 real WooCommerce files, including ones that are also part of its own Composer
        // PSR-4 autoload map — reachable the instant class_exists()/ReflectionClass triggers
        // autoloading on them. exit() isn't a catchable \Throwable, so before this fix, loading
        // a class guarded this way silently killed the *entire* scan process (confirmed against
        // a real WooCommerce checkout: Automattic\WooCommerce\Admin\API\Reports\Query, resolved
        // while checking a completely unrelated extends chain, terminated the whole scan mid-run
        // with no error output and exit code 0 — just the header, then nothing). If this
        // assertion never runs at all, the fix has regressed and this whole test process died
        // silently the same way the real scan once did — that itself is the signal.
        $vendorFile = $this->write('<?php
defined("ABSPATH") || exit;
class My_Guarded_Vendor_Class {
    public function doSomething() {}
}
');
        $reflector = new VendorClassReflector([$vendorFile]);
        self::assertTrue($reflector->classHasMethod('My_Guarded_Vendor_Class', 'doSomething'));
    }

    public function testUnavailableWithNoAutoloadPaths(): void
    {
        $reflector = new VendorClassReflector([]);
        self::assertFalse($reflector->isAvailable());
        self::assertFalse($reflector->classHasMethod('Anything', 'anything'));
    }

    private function write(string $code): string
    {
        $file = $this->tmp . '/vendor_' . uniqid() . '.php';
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
