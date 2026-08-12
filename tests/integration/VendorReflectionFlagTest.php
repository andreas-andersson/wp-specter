<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;

final class VendorReflectionFlagTest extends TestCase
{
    private Application $app;
    private string $tmp;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->tmp = sys_get_temp_dir() . '/wp-specter-novendorreflect-' . uniqid();

        // A vendor autoload that, when required, declares a real base class with a "register"
        // method — enough for VendorClassReflector to recognize My_Provider::register() below as
        // a genuine override of a vendor contract, not dead code.
        $this->write('vendor/autoload.php', '<?php
namespace VendorReflectionFlagFixture;
class ServiceProvider {
    public function register() {}
}
');
        $this->write('composer.json', '{}');
        $this->write('style.css', "/*\nTheme Name: My Theme\n*/");
        $this->write('functions.php', '<?php
use VendorReflectionFlagFixture\ServiceProvider;
class My_Provider extends ServiceProvider {
    public function register() {}
}
new My_Provider();
');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testVendorAutoloadIsConsultedByDefault(): void
    {
        [$exit, $output] = $this->runApp(['wp-specter', 'scan', $this->tmp, '--no-color', '--type=classes']);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('register', $output);
    }

    public function testNoVendorReflectionFlagSkipsLoadingVendorAutoload(): void
    {
        // With the flag, the vendor autoload is never require_once'd — VendorClassReflector has
        // no way to know ServiceProvider::register() exists, so the override looks like an
        // ordinary unused method, exactly as it would if no vendor autoload existed at all.
        [$exit, $output] = $this->runApp([
            'wp-specter', 'scan', $this->tmp, '--no-color', '--type=classes', '--no-vendor-reflection',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('register', $output);
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string}
     */
    private function runApp(array $argv): array
    {
        ob_start();
        $exit = $this->app->run($argv);
        $output = ob_get_clean();
        self::assertIsString($output);
        return [$exit, $output];
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->tmp . '/' . $relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
