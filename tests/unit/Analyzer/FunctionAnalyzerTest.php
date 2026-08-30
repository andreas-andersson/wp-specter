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

    public function testWpObjectCacheDropinFunctionsAreNeverReportedUnused(): void
    {
        // Real-world finding (LiteSpeed Cache): a wp-content/object-cache.php drop-in's own
        // wp_cache_* function declarations are called by WP core directly at bootstrap, from a
        // file living outside the scanned project's own tree entirely — no call site can ever
        // exist in project code no matter how the backend is implemented.
        $file = $this->write('<?php
function wp_cache_init() {}
function wp_cache_add( $key, $data, $group = "", $expire = 0 ) {}
function wp_cache_get( $key, $group = "", $force = false, &$found = null ) {}
function wp_cache_flush_runtime() {}
function wp_cache_switch_to_blog( $blog_id ) {}
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testFunctionExistsGuardExcludesTheGuardedFunctionEntirely(): void
    {
        // `if ( ! function_exists( 'my_helper' ) ) { function my_helper() {} }` is the real WP
        // polyfill convention — only meant to exist if WP core/another plugin hasn't already
        // declared it, so it's never callable from this project's own code, and shouldn't be
        // reported at all (see FunctionDef::$guarded / FunctionAnalyzer::isExcluded).
        $file = $this->write("<?php
if ( ! function_exists( 'my_helper' ) ) {
    function my_helper() {}
}
");
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testUnguardedSameNamedFunctionIsStillReportedUnused(): void
    {
        // Only a function declared DIRECTLY inside its own matching guard is exempted — a
        // same-named function declared plainly elsewhere (no guard at all) must still be
        // evaluated normally.
        $file = $this->write('<?php
function my_helper() {}
');
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

    public function testFullyQualifiedFunctionCallCountsAsUse(): void
    {
        // \My_Theme\my_helper() tokenizes as a single T_NAME_FULLY_QUALIFIED token, not T_STRING
        // — the only call-detection branch fired on T_STRING before this fix, so the definition
        // looked unused despite the call.
        $file = $this->write('<?php
namespace My_Theme;
function my_helper() {}
function truly_unused_helper() {}
function bootstrap() {
    \My_Theme\my_helper();
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unused = array_column($findings, 'name');
        self::assertNotContains('my_helper', $unused);
        self::assertContains('truly_unused_helper', $unused);
    }

    public function testTwoNamespacedFunctionsWithTheSameShortNameAreTrackedSeparately(): void
    {
        // Two unrelated functions named "helper" in different namespaces -- before FQCN-keying,
        // $definitions['helper'] silently held only whichever was parsed last, and a genuine
        // call to one would falsely credit the other's identically-named, actually-dead sibling.
        $usedFile = $this->write('<?php
namespace My\App;
function helper() {}
helper();
');
        $deadFile = $this->write('<?php
namespace Other\App;
function helper() {}
');
        $findings = $this->analyzer->analyze([$usedFile, $deadFile]);
        $unused = array_column($findings, 'name', 'file');
        self::assertArrayNotHasKey($usedFile, $unused);
        self::assertSame('helper', $unused[$deadFile] ?? null);
    }

    public function testBareCallInsideNamespaceResolvesToTheLocalFunctionFirst(): void
    {
        $file = $this->write('<?php
namespace My\App;
function helper() {}
function bootstrap() { helper(); }
bootstrap();
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testBareCallInsideNamespaceFallsBackToTheGlobalFunction(): void
    {
        // PHP resolves an unqualified call by trying the current namespace first, falling back
        // to the global namespace only if nothing matches locally -- a real runtime decision
        // (unlike an unqualified class reference, which never falls back). "helper" isn't
        // declared inside My\App at all, so the bare call here must credit the global one.
        $globalFile = $this->write('<?php
function helper() {}
');
        $callerFile = $this->write('<?php
namespace My\App;
function bootstrap() { helper(); }
');
        $findings = $this->analyzer->analyze([$globalFile, $callerFile]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->name === 'helper'));
    }

    public function testUseFunctionImportedCallResolvesToItsRealDeclarationAcrossFiles(): void
    {
        // Real-world finding (Jetpack): a function declared in one namespace, imported via
        // `use function Fully\Qualified\Name;` into a file under a *different* namespace, then
        // called bare there. Neither of the two existing bare-call candidates (the plain name,
        // or the current-namespace-prefixed guess) names the real declaration's namespace, so
        // without the import being consulted this false-positives as unused.
        $declaringFile = $this->write('<?php
namespace Automattic\Jetpack\Extensions\Map;
function map_block_from_geo_points() {}
');
        $callerFile = $this->write('<?php
namespace Automattic\Jetpack\Json_Endpoints;
use function Automattic\Jetpack\Extensions\Map\map_block_from_geo_points;
function bootstrap() { map_block_from_geo_points(); }
bootstrap();
');
        $findings = $this->analyzer->analyze([$declaringFile, $callerFile]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->name === 'map_block_from_geo_points'));
    }

    public function testProjectOwnTemplateLoaderWrapperFunctionIsNotFlaggedUnusedByItsOwnCallSites(): void
    {
        // Real-world regression (Sydney theme): a project can both declare AND call its own
        // template-loader wrapper (sydney_get_template_part() — matches the general
        // "<prefix>_get_template_part" convention TemplateAnalyzer relies on). Every real call
        // site gets recorded as a template ref (for TemplateAnalyzer's own purposes) — but that
        // must not come at the cost of FunctionAnalyzer never seeing those same call sites as
        // genuine calls to the wrapper's own declaration.
        $file = $this->write('<?php
function sydney_get_template_part( $slug, $name = null ) {
    get_template_part( $slug, $name );
}
sydney_get_template_part( "content", "quick-view" );
');
        $names = array_column($this->analyzer->analyze([$file]), 'name');
        self::assertNotContains('sydney_get_template_part', $names);
    }

    public function testWpPrefixedFunctionsAreNotBlanketExcluded(): void
    {
        // A wp_/get_/the_/is_-prefixed name is no longer an automatic exemption on its own —
        // only a real function_exists()-guarded polyfill is (see the guard tests above).
        // Real-world regression this replaced: wp-smushit's own wp_smush_php_deprecated_notice()
        // was genuinely dead (every call site commented out) but invisible purely because of its
        // "wp_" prefix.
        $file = $this->write('<?php
function wp_my_function() {}
function get_my_data() {}
function the_title() {}
function is_active() {}
');
        $names = array_column($this->analyzer->analyze([$file]), 'name');
        self::assertContains('wp_my_function', $names);
        self::assertContains('get_my_data', $names);
        self::assertContains('the_title', $names);
        self::assertContains('is_active', $names);
    }

    public function testWpCoreNamePolyfillGuardedByFunctionExistsIsExempt(): void
    {
        // Real-world case (wp-smushit): a polyfill for a real WP-core function of the same name,
        // guarded so it only defines itself if WP core hasn't already — WP core calls it once it
        // exists, invisible to any single-plugin scan by design. Confirms the guard mechanism
        // (not the name prefix) is what correctly protects this legitimate case.
        $file = $this->write('<?php
if ( ! function_exists( "wp_sizes_attribute_includes_valid_auto" ) ) {
    function wp_sizes_attribute_includes_valid_auto( $sizes_attr ) {
        return false;
    }
}
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

    public function testDoesNotReportAFunctionCreditedThroughAnUnresolvedReturnTypedCall(): void
    {
        // $c = totally_unknown_function(); $c->plain_helper(); — before PendingReturnTypedCall
        // existed, this shape always landed directly in $functionCalls regardless of whether the
        // source call's return type was ever resolvable; this analyzer doesn't care about
        // classes at all, so it must keep crediting the name unconditionally the same way, or a
        // same-named real function starts looking wrongly unused now that this shape has its own
        // dedicated (class-analyzer-facing) tracking instead of folding into $functionCalls.
        $file = $this->write('<?php
function plain_helper() {}
class My_Controller {
    public function boot() {
        $c = totally_unknown_function();
        $c->plain_helper();
    }
}
');
        self::assertEmpty($this->analyzer->analyze([$file]));
    }

    public function testAnalyzeForwardsProgressCallbackToParseAll(): void
    {
        // Every analyzer's analyze() just delegates its own parsing to PhpTokenParser::
        // parseAll() (see that method's own test coverage for the throttling/reporting logic
        // itself) — this only confirms the callback actually reaches it end to end, using
        // FunctionAnalyzer as the representative case since all 5 analyzers share the identical
        // one-line plumbing.
        $fileA = $this->write('<?php function a() {}');
        $fileB = $this->write('<?php function b() {}');

        $calls = [];
        $this->analyzer->analyze([$fileA, $fileB], function (int $current, int $total) use (&$calls) {
            $calls[] = [$current, $total];
        });

        self::assertSame([[1, 2], [2, 2]], $calls);
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
