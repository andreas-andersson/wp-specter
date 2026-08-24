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

    public function testCallInsideClosureIsNotSwallowedAsTheClosuresOwnName(): void
    {
        // Regression (found via Blocksy theme): $skipNextString exists to skip a *named*
        // declaration's own name token ("foo" in `function foo(`) so it's never mistaken for a
        // call to itself. It was being set unconditionally even for an anonymous closure/arrow
        // function, which has no name token to skip — so it silently ate whatever T_STRING token
        // came next instead, which is exactly the first real call inside the closure body for the
        // extremely common `add_action('x', function () { my_helper(); });` shape.
        $file = $this->write('<?php
function my_helper() {}
add_action("admin_notices", function () {
    my_helper();
});
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testFunctionExistsGuardDoesNotCountAsCall(): void
    {
        // Regression: `if ( ! function_exists( 'my_helper' ) ) { function my_helper() {} }` is
        // an extremely common WP redeclaration guard, self-referencing the very thing it's about
        // to define — not a real usage signal. Its own string argument was flowing into the same
        // generic name pool a real call would, permanently masking a genuinely-unused function.
        $file = $this->write("<?php
if ( ! function_exists( 'my_helper' ) ) {
    function my_helper() {}
}
");
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame('my_helper', $findings[0]->name);
    }

    public function testStringCallbackCountsAsCall(): void
    {
        $file = $this->write("<?php
function my_handler() {}
add_action( 'init', 'my_handler' );
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testNamespaceConcatenatedStringCallbackCountsAsCall(): void
    {
        // Real-world regression (Sakurairo theme): namespaced files commonly build a fully
        // qualified callback string as `__NAMESPACE__ . '\my_handler'`. The concatenation itself
        // isn't tracked, but the literal '\my_handler' must still count as a call to
        // my_handler() — its leading "\" was making the whole literal fail the callback-name
        // regex and silently going unmatched, so the definition looked unused despite the call.
        $file = $this->write("<?php
namespace My_Theme;
function my_handler() {}
add_action( 'init', __NAMESPACE__ . '\\my_handler' );
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

    public function testDoesNotReportUseFunctionImportOfHookRegisterFunc(): void
    {
        // Regression: `use function add_action;` (a namespaced file importing a WP core global
        // into scope, common in e.g. WP Rig) tokenizes as T_FUNCTION T_STRING just like a real
        // declaration, but with `;` instead of `(` after the name. Misparsing it as a definition
        // created a phantom "add_action" FunctionDef that add_action()'s own real calls below can
        // never satisfy, since those calls are diverted into hookRegistrations, not
        // functionCalls — so it was reported unused despite being called four lines down.
        $file = $this->write('<?php
namespace My_Theme;
use function add_action;
use function add_filter;
add_action("init", "my_handler");
add_filter("the_content", "my_filter");
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testDoesNotReportUnusedUseFunctionImportAsUnusedFunction(): void
    {
        // Same misparsing, but for an import that really is never called anywhere in the file —
        // it must not surface as "Unused Functions" either, since the project never defined it in
        // the first place; it's an unused import, a different (currently unimplemented) concern.
        $file = $this->write('<?php
namespace My_Theme;
use function pings_open;
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
