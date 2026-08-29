<?php

declare(strict_types=1);

namespace WpSpecter\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpSpecter\Application;

final class SuppressUnusedClassMethodsFlagTest extends TestCase
{
    private Application $app;
    private string $tmp;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->tmp = sys_get_temp_dir() . '/wp-specter-suppressmethods-' . uniqid();

        $this->write('style.css', "/*\nTheme Name: My Theme\n*/");
        $this->write('functions.php', '<?php
class My_Dead_Class {
    public function truly_unused() {}
}
');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testUnusedMethodOnAnUnusedClassIsSuppressedByDefault(): void
    {
        [$exit, $output] = $this->runApp(['wp-specter', 'scan', $this->tmp, '--no-color', '--type=classes']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('My_Dead_Class', $output);
        self::assertStringNotContainsString('truly_unused', $output);
    }

    public function testNoSuppressFlagReportsBothFindings(): void
    {
        [$exit, $output] = $this->runApp([
            'wp-specter', 'scan', $this->tmp, '--no-color', '--type=classes', '--no-suppress-unused-class-methods',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('My_Dead_Class', $output);
        self::assertStringContainsString('truly_unused', $output);
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
