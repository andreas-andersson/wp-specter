<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Scanner;

use PHPUnit\Framework\TestCase;
use WpSpecter\Scanner\FileScanner;

final class FileScannerTest extends TestCase
{
    private string $tmp;
    private FileScanner $scanner;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-scan-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->scanner = new FileScanner();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testScansDirectoryRecursively(): void
    {
        $this->touch('functions.php');
        $this->touch('inc/helpers.php');
        $this->touch('template-parts/card.php');

        $result = $this->scanner->scan($this->tmp);

        self::assertCount(3, $result->files);
        self::assertNull($result->error);
    }

    public function testExcludesVendorByDefault(): void
    {
        $this->touch('functions.php');
        $this->touch('vendor/autoload.php');

        $result = $this->scanner->scan($this->tmp);

        self::assertCount(1, $result->files);
        self::assertStringContainsString('functions.php', $result->files[0]);
    }

    public function testExcludesNodeModules(): void
    {
        $this->touch('index.php');
        $this->touch('node_modules/dep/index.php');

        $result = $this->scanner->scan($this->tmp);

        self::assertCount(1, $result->files);
    }

    public function testIgnoreGlobsApplied(): void
    {
        $this->touch('functions.php');
        $this->touch('generated.php');

        $result = $this->scanner->scan($this->tmp, ['generated.php']);

        self::assertCount(1, $result->files);
        self::assertStringContainsString('functions.php', $result->files[0]);
    }

    public function testNonPhpFilesExcluded(): void
    {
        $this->touch('functions.php');
        $this->touch('style.css');
        $this->touch('README.md');

        $result = $this->scanner->scan($this->tmp);

        self::assertCount(1, $result->files);
    }

    public function testFilesReturnedSorted(): void
    {
        $this->touch('z-last.php');
        $this->touch('a-first.php');

        $result = $this->scanner->scan($this->tmp);

        self::assertCount(2, $result->files);
        self::assertStringContainsString('a-first.php', $result->files[0]);
        self::assertStringContainsString('z-last.php', $result->files[1]);
    }

    public function testReturnsErrorForMissingDir(): void
    {
        $result = $this->scanner->scan('/nonexistent/path');

        self::assertCount(0, $result->files);
        self::assertNotNull($result->error);
    }

    private function touch(string $relative): void
    {
        $path = $this->tmp . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, '<?php');
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
