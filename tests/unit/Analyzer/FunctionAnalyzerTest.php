<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\FunctionAnalyzer;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Parser\PhpTokenParser;

final class FunctionAnalyzerTest extends TestCase
{
    private string $tmp;
    private FunctionAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-fa-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->analyzer = new FunctionAnalyzer(new PhpTokenParser());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testReportsUnusedFunction(): void
    {
        $file = $this->write('<?php function my_unused_func() {}');

        $findings = $this->analyzer->analyze([$file]);

        self::assertCount(1, $findings);
        self::assertSame('my_unused_func', $findings[0]->name);
        self::assertSame(FindingCertainty::Error, $findings[0]->certainty);
    }

    public function testDoesNotReportCalledFunction(): void
    {
        $file = $this->write('<?php
function my_func() {}
my_func();
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testStringCallbackCountsAsCall(): void
    {
        $file = $this->write("<?php
function my_handler() {}
add_action( 'init', 'my_handler' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testExcludesWpPrefixedFunctions(): void
    {
        $file = $this->write('<?php
function wp_my_function() {}
function get_my_data() {}
function the_title() {}
function is_active() {}
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testExcludesMagicMethods(): void
    {
        $file = $this->write('<?php
class Foo {
    function __construct() {}
    function __toString() {}
}
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testFunctionCalledInAnotherFile(): void
    {
        $file1 = $this->write('<?php function shared_helper() {}');
        $file2 = $this->write('<?php shared_helper();');

        self::assertEmpty($this->analyzer->analyze([$file1, $file2]));
    }

    public function testDoesNotReportClassMethods(): void
    {
        $file = $this->write('<?php
class MyPlugin {
    public function setup() {}
    protected function render() {}
    private static function enqueue() {}
}
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testArrayCallbackCountsAsCall(): void
    {
        $file = $this->write('<?php
function my_handler() {}
add_action("init", [$this, "my_handler"]);
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testClassConstArrayCallbackDoesNotCountAsStandaloneFunctionCall(): void
    {
        // [MyClass::class, 'my_static'] always calls MyClass's static method, never a global
        // function of the same name — so it must NOT suppress this standalone function's
        // unused-function finding.
        $file = $this->write('<?php
function my_static() {}
add_action("init", [MyClass::class, "my_static"]);
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('my_static', $findings[0]->name);
    }

    public function testClassMethodAfterStringInterpolationNotReported(): void
    {
        // Regression: string interpolation "{{$k}}" was corrupting brace depth,
        // causing methods defined later to be treated as standalone functions.
        $file = $this->write('<?php
class MyClass {
    public function first() {
        $keys = array_map(fn($k) => "{{$k}}", [1, 2]);
    }
    public function second() {}
}
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testReportsFileAndLineForUnused(): void
    {
        $file = $this->write("<?php\n\nfunction lonely_func() {}");

        $findings = $this->analyzer->analyze([$file]);

        self::assertCount(1, $findings);
        self::assertSame($file, $findings[0]->file);
        self::assertSame(3, $findings[0]->line);
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
