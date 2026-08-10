<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Parser;

use PHPUnit\Framework\TestCase;
use WpSpecter\Parser\PhpTokenParser;

final class PhpTokenParserTest extends TestCase
{
    private string $tmp;
    private PhpTokenParser $parser;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-parser-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->parser = new PhpTokenParser();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testExtractsFunctionDefinitions(): void
    {
        $result = $this->parse('<?php
function my_setup() {}
function another_func( $arg ) {}
$anon = function() {};
');
        $names = array_column($result->functionDefs, 'name');
        self::assertContains('my_setup', $names);
        self::assertContains('another_func', $names);
        self::assertCount(2, $result->functionDefs);
    }

    public function testExtractsFunctionCalls(): void
    {
        $result = $this->parse('<?php
my_setup();
wp_enqueue_style( "style" );
another_func( 1, 2 );
');
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('my_setup', $names);
        self::assertContains('wp_enqueue_style', $names);
        self::assertContains('another_func', $names);
    }

    public function testExtractsStringCallbacks(): void
    {
        $result = $this->parse("<?php
add_action( 'init', 'my_init_callback' );
");
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('my_init_callback', $names);
    }

    public function testExtractsHookRegistrationsLiteralTag(): void
    {
        $result = $this->parse("<?php
add_action( 'init', 'my_handler' );
add_filter( 'the_content', 'filter_content' );
");
        self::assertCount(2, $result->hookRegistrations);
        self::assertSame('init', $result->hookRegistrations[0]->tag);
        self::assertSame('add_action', $result->hookRegistrations[0]->function);
        self::assertFalse($result->hookRegistrations[0]->isDynamic);
        self::assertSame('the_content', $result->hookRegistrations[1]->tag);
    }

    public function testMarksVariableHookTagAsDynamic(): void
    {
        $result = $this->parse('<?php
$tag = "init";
add_action( $tag, "handler" );
');
        self::assertCount(1, $result->hookRegistrations);
        self::assertTrue($result->hookRegistrations[0]->isDynamic);
    }

    public function testExtractsHookInvocations(): void
    {
        $result = $this->parse("<?php
do_action( 'wp_head' );
apply_filters( 'the_content', \$content );
");
        self::assertCount(2, $result->hookInvocations);
        self::assertSame('wp_head', $result->hookInvocations[0]->tag);
        self::assertSame('the_content', $result->hookInvocations[1]->tag);
        self::assertFalse($result->hookInvocations[0]->isDynamic);
        self::assertSame('wp_head', $result->hookInvocations[0]->tagPrefix);
    }

    public function testInterpolatedHookInvocationKeepsLiteralPrefix(): void
    {
        // ACF's real shape: every acf/settings/* filter fires through this one dynamic
        // dispatcher — apply_filters("acf/settings/{$name}", $value) — so "acf/settings/save_json"
        // never appears as a literal string anywhere in ACF's own source.
        $result = $this->parse('<?php
function acf_get_setting($name, $value = null) {
    return apply_filters("acf/settings/{$name}", $value);
}
');
        self::assertCount(1, $result->hookInvocations);
        self::assertTrue($result->hookInvocations[0]->isDynamic);
        self::assertSame('', $result->hookInvocations[0]->tag);
        self::assertSame('acf/settings/', $result->hookInvocations[0]->tagPrefix);
    }

    public function testConcatenatedHookInvocationKeepsLiteralPrefix(): void
    {
        $result = $this->parse('<?php
do_action("acf/settings/" . $name);
');
        self::assertCount(1, $result->hookInvocations);
        self::assertTrue($result->hookInvocations[0]->isDynamic);
        self::assertSame('acf/settings/', $result->hookInvocations[0]->tagPrefix);
    }

    public function testFullyDynamicHookInvocationHasNoPrefix(): void
    {
        $result = $this->parse('<?php
do_action($fullyDynamicTag);
');
        self::assertCount(1, $result->hookInvocations);
        self::assertTrue($result->hookInvocations[0]->isDynamic);
        self::assertSame('', $result->hookInvocations[0]->tagPrefix);
    }

    public function testExtractsGetTemplatePart(): void
    {
        $result = $this->parse("<?php
get_template_part( 'template-parts/hero' );
get_template_part( 'parts/header', 'home' );
");
        self::assertCount(2, $result->templateRefs);
        self::assertSame('template-parts/hero', $result->templateRefs[0]->path);
        self::assertSame('get_template_part', $result->templateRefs[0]->function);
    }

    public function testExtractsGetHeaderFooterSidebar(): void
    {
        $result = $this->parse("<?php
get_header();
get_footer( 'shop' );
get_sidebar();
");
        self::assertCount(3, $result->templateRefs);
        $funcs = array_column($result->templateRefs, 'function');
        self::assertContains('get_header', $funcs);
        self::assertContains('get_footer', $funcs);
        self::assertContains('get_sidebar', $funcs);
    }

    public function testExtractsIncludeRequire(): void
    {
        $result = $this->parse("<?php
include 'template-parts/card.php';
require_once 'inc/helpers.php';
");
        self::assertCount(2, $result->templateRefs);
        self::assertSame('template-parts/card.php', $result->templateRefs[0]->path);
    }

    public function testExtractsConcatenatedIncludePath(): void
    {
        $result = $this->parse("<?php
include_once dirname(__FILE__) . '/storm.php';
require_once get_template_directory() . '/inc/setup.php';
include_once \$path . \"/gk-event.php\";
");
        self::assertCount(3, $result->templateRefs);
        self::assertSame('/storm.php', $result->templateRefs[0]->path);
        self::assertSame('/inc/setup.php', $result->templateRefs[1]->path);
        self::assertSame('/gk-event.php', $result->templateRefs[2]->path);
    }

    public function testGetTemplatePartInterpolatedStringKeepsLiteralPrefix(): void
    {
        $result = $this->parse('<?php
$variant = "lg";
get_template_part("inc/shortcodes/gk-card-list/variants/$variant", null);
');
        self::assertCount(1, $result->templateRefs);
        self::assertSame('inc/shortcodes/gk-card-list/variants/', $result->templateRefs[0]->path);
    }

    public function testFullyDynamicIncludeSkipped(): void
    {
        $result = $this->parse('<?php
$file = "x.php";
include $file;
');
        self::assertEmpty($result->templateRefs);
    }

    public function testGlobWithConcatenatedDirCapturesDirectory(): void
    {
        $result = $this->parse('<?php
foreach (glob(__DIR__ . "/inc/*.php") as $f) {
    require $f;
}
');
        // dirname() keeps the leading slash from the concatenated literal ("/inc/*.php") —
        // FileAnalyzer's resolveGlobExemptDir() trims it before joining, so this is fine as-is.
        self::assertSame(['/inc'], $result->globIncludeDirs);
        self::assertTrue($result->hasIncludeStatement);
    }

    public function testGlobWithLiteralPatternCapturesDirectory(): void
    {
        $result = $this->parse('<?php
foreach (glob("modules/*.php") as $f) {
    require_once $f;
}
');
        self::assertSame(['modules'], $result->globIncludeDirs);
    }

    public function testGlobWithNoDirectoryComponentCollapsesToCurrentDir(): void
    {
        $result = $this->parse('<?php
foreach (glob(__DIR__ . "/*.php") as $f) {
    require $f;
}
');
        // "/*.php" has no directory segment of its own — dirname() collapses it to "/",
        // signalling "this file\'s own directory" to FileAnalyzer.
        self::assertSame(['/'], $result->globIncludeDirs);
    }

    public function testFullyDynamicGlobIsSkipped(): void
    {
        $result = $this->parse('<?php
$pattern = "*.php";
foreach (glob($pattern) as $f) {
    require $f;
}
');
        self::assertEmpty($result->globIncludeDirs);
        self::assertTrue($result->hasIncludeStatement);
    }

    public function testHasIncludeStatementIsFalseWithNoIncludeOrRequire(): void
    {
        $result = $this->parse('<?php
$images = glob(__DIR__ . "/images/*.jpg");
');
        self::assertSame(['/images'], $result->globIncludeDirs);
        self::assertFalse($result->hasIncludeStatement);
    }

    public function testReportsCorrectLineNumbers(): void
    {
        $result = $this->parse("<?php
// line 2

function my_func() {}

add_action( 'init', 'my_func' );
");
        self::assertSame(4, $result->functionDefs[0]->line);
        self::assertSame(6, $result->hookRegistrations[0]->line);
    }

    public function testHandlesParseError(): void
    {
        $result = $this->parse('<?php function {');
        self::assertNotNull($result->error);
        self::assertEmpty($result->functionDefs);
    }

    public function testSkipsAnonymousFunctions(): void
    {
        $result = $this->parse('<?php $fn = function() {};');
        self::assertEmpty($result->functionDefs);
    }

    public function testClassMethodsMarkedAsMethod(): void
    {
        $result = $this->parse('<?php
class MyPlugin {
    public function setup() {}
    public static function enqueue() {}
}
function standalone() {}
');
        $names = array_column($result->functionDefs, 'name');
        self::assertContains('standalone', $names);
        // class methods present in defs but marked isMethod=true
        $methods = array_filter($result->functionDefs, fn($d) => $d->isMethod);
        $methodNames = array_column(array_values($methods), 'name');
        self::assertContains('setup', $methodNames);
        self::assertContains('enqueue', $methodNames);
        // standalone must NOT be a method
        $standalones = array_filter($result->functionDefs, fn($d) => !$d->isMethod);
        self::assertCount(1, $standalones);
    }

    public function testExtractsClassExtendsAndImplements(): void
    {
        $result = $this->parse('<?php
class My_Widget extends WP_Widget implements Countable {
    public function widget($args, $instance) {}
    public function count(): int { return 0; }
}
');
        self::assertCount(1, $result->classDefs);
        self::assertSame('My_Widget', $result->classDefs[0]->name);
        self::assertSame(['WP_Widget'], $result->classDefs[0]->extends);
        self::assertSame(['Countable'], $result->classDefs[0]->implements);

        $methods = array_column($result->functionDefs, 'ownerClass', 'name');
        self::assertSame('My_Widget', $methods['widget']);
        self::assertSame('My_Widget', $methods['count']);
    }

    public function testInterfaceTraitEnumProduceClassDefsWithKind(): void
    {
        $result = $this->parse('<?php
interface My_Interface {}
trait My_Trait {}
enum My_Enum {}
class My_Class {}
');
        $kindsByName = array_column($result->classDefs, 'kind', 'name');
        self::assertSame('interface', $kindsByName['My_Interface']);
        self::assertSame('trait', $kindsByName['My_Trait']);
        self::assertSame('enum', $kindsByName['My_Enum']);
        self::assertSame('class', $kindsByName['My_Class']);
    }

    public function testMethodsInsideInterfacesHaveNoOwnerClass(): void
    {
        // Interface method declarations never have a body, so there's no $this-> scoping to
        // resolve inside them — ownerClass stays null, same as before interfaces got their own
        // ClassDef.
        $result = $this->parse('<?php
interface My_Interface {
    public function do_it(): void;
}
');
        $methods = array_filter($result->functionDefs, fn($d) => $d->isMethod);
        foreach ($methods as $m) {
            self::assertNull($m->ownerClass, "{$m->name} should have no ownerClass");
        }
    }

    public function testMethodsInsideTraitsAreOwnedByTheTrait(): void
    {
        // A trait's own method IS scoped to the trait's own name — this lets an intra-trait
        // $this->method() call resolve precisely instead of leaking into the unscoped fallback
        // pool. A trait's methods are never called on the trait directly though; it's
        // ClassAnalyzer's job (via TraitUsage) to widen "used" to whatever class `use`s the
        // trait, not the parser's.
        $result = $this->parse('<?php
trait My_Trait {
    public function helper() {}
}
');
        $methods = array_filter($result->functionDefs, fn($d) => $d->isMethod);
        foreach ($methods as $m) {
            self::assertSame('My_Trait', $m->ownerClass, "{$m->name} should be owned by My_Trait");
        }
    }

    public function testInterfaceExtendingMultipleInterfacesCapturesAll(): void
    {
        $result = $this->parse('<?php
interface Base_A {}
interface Base_B {}
interface Combined extends Base_A, Base_B {}
');
        $defsByName = array_column($result->classDefs, null, 'name');
        self::assertSame(['Base_A', 'Base_B'], $defsByName['Combined']->extends);
    }

    public function testEnumWithBackingTypeAndImplementsIsParsedCorrectly(): void
    {
        $result = $this->parse('<?php
interface Has_Label {}
enum Status: string implements Has_Label {
    case Active = "active";
}
');
        $defsByName = array_column($result->classDefs, null, 'name');
        self::assertSame('enum', $defsByName['Status']->kind);
        self::assertSame(['Has_Label'], $defsByName['Status']->implements);
    }

    public function testTraitUseInsideClassBodyIsClassReference(): void
    {
        $result = $this->parse('<?php
trait My_Trait {}
class My_Class {
    use My_Trait;
}
');
        self::assertContains('My_Trait', $result->classReferences);
    }

    public function testMultipleTraitUseWithConflictResolutionCapturesBothNames(): void
    {
        $result = $this->parse('<?php
trait Trait_A { public function foo() {} }
trait Trait_B { public function foo() {} }
class My_Class {
    use Trait_A, Trait_B {
        Trait_A::foo insteadof Trait_B;
        Trait_B::foo as bar;
    }
}
');
        self::assertContains('Trait_A', $result->classReferences);
        self::assertContains('Trait_B', $result->classReferences);
    }

    public function testClosureUseIsNotMistakenForTraitUse(): void
    {
        $result = $this->parse('<?php
class My_Class {
    public function boot() {
        $var = 1;
        $fn = function() use ($var) { return $var; };
    }
}
');
        self::assertEmpty($result->classReferences);
    }

    public function testFileLevelUseImportIsNotMistakenForTraitUse(): void
    {
        $result = $this->parse('<?php
use Some\Namespace\Thing;
class My_Class {}
');
        self::assertEmpty($result->classReferences);
    }

    public function testStandaloneFunctionHasNoOwnerClass(): void
    {
        $result = $this->parse('<?php function standalone() {}');
        self::assertNull($result->functionDefs[0]->ownerClass);
    }

    public function testScopedMethodCallsResolveThisSelfParentStaticAndLiteralClass(): void
    {
        $result = $this->parse('<?php
class Base { public function base_method() {} }
class Child extends Base {
    public function self_call() { $this->helper(); }
    public function helper() {}
    public static function static_call() { self::other(); }
    public function other() {}
    public function calls_parent() { parent::base_method(); }
    public function calls_late_binding() { static::late_bound(); }
    public function late_bound() {}
}
Child::static_call();
');
        $calls = array_map(
            fn($c) => $c->receiverClass . '::' . $c->method,
            $result->scopedMethodCalls,
        );

        self::assertContains('Child::helper', $calls);
        self::assertContains('Child::other', $calls);
        self::assertContains('Base::base_method', $calls);
        self::assertContains('Child::late_bound', $calls);
        self::assertContains('Child::static_call', $calls);

        // None of these should also land in the generic, unscoped call pool — that would
        // defeat the point of scoping (an unrelated class's same-named method would still
        // look "used").
        self::assertEmpty($result->functionCalls);
    }

    public function testStaticModifierIsNotMistakenForStaticCall(): void
    {
        // "static" here is the method-visibility modifier, not `static::` late static binding —
        // must not be swallowed as part of a bogus scoped call, which would eat the actual
        // function definition tokens that follow.
        $result = $this->parse('<?php
class MyPlugin {
    public static function enqueue() {}
}
');
        $names = array_column($result->functionDefs, 'name');
        self::assertContains('enqueue', $names);
        self::assertEmpty($result->scopedMethodCalls);
    }

    public function testPropertyAccessAndClassConstAreNotScopedCalls(): void
    {
        $result = $this->parse('<?php
class My_Class {
    public function boot() {
        $x = $this->property;
        $y = self::CONST_VALUE;
        $z = My_Class::class;
    }
}
');
        self::assertEmpty($result->scopedMethodCalls);
    }

    public function testLocalVariableAssignedFromNewIsScoped(): void
    {
        $result = $this->parse('<?php
class My_Service {
    public function render() {}
}
function boot() {
    $s = new My_Service();
    $s->render();
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My_Service::render', $calls);
    }

    public function testReassignedLocalVariableInvalidatesTrackedType(): void
    {
        $result = $this->parse('<?php
class My_Service {
    public function render() {}
}
function boot() {
    $s = new My_Service();
    $s = some_factory();
    $s->render();
}
');
        self::assertEmpty($result->scopedMethodCalls);
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('render', $names);
    }

    public function testLocalVariableTypeDoesNotLeakAcrossFunctions(): void
    {
        $result = $this->parse('<?php
class My_Service {
    public function render() {}
}
function first() {
    $x = new My_Service();
}
function second() {
    $x->render();
}
');
        self::assertEmpty($result->scopedMethodCalls);
    }

    public function testLocalVariableTypeDoesNotLeakIntoSiblingMethod(): void
    {
        $result = $this->parse('<?php
class My_Service {
    public function render() {}
}
class My_Plugin {
    public function boot() {
        $y = new My_Service();
    }
    public function other($y) {
        $y->render();
    }
}
');
        self::assertEmpty($result->scopedMethodCalls);
    }

    public function testLocalVariableAssignedFromSelfAndParentIsScoped(): void
    {
        $result = $this->parse('<?php
class Base_Service {
    public function base_render() {}
}
class My_Service extends Base_Service {
    public function boot() {
        $a = new self();
        $a->boot();
        $b = new parent();
        $b->base_render();
    }
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My_Service::boot', $calls);
        self::assertContains('Base_Service::base_render', $calls);
    }

    public function testStringInterpolationDoesNotCorruptClassContext(): void
    {
        // "{{$k}}" emits a STRING "}" from the interpolation — must not corrupt brace depth.
        $result = $this->parse('<?php
class MyClass {
    public function first() {
        $keys = array_map(fn($k) => "{{$k}}", [1, 2]);
    }
    public function second() {}
}
function standalone() {}
');
        $methods = array_filter($result->functionDefs, fn($d) => $d->isMethod);
        $methodNames = array_column(array_values($methods), 'name');
        self::assertContains('first', $methodNames, '"first" must be detected as a method');
        self::assertContains('second', $methodNames, '"second" must be detected as a method after string interpolation');

        $standalones = array_filter($result->functionDefs, fn($d) => !$d->isMethod);
        $standaloneNames = array_column(array_values($standalones), 'name');
        self::assertContains('standalone', $standaloneNames);
        self::assertNotContains('second', $standaloneNames, '"second" must not leak into standalone functions');
    }

    public function testArrayCallbackThisMethodNameIsRecordedAsFunctionCall(): void
    {
        $result = $this->parse('<?php
add_action("init", [$this, "my_method"]);
');
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('my_method', $names);
    }

    public function testArrayCallbackWithClassConstReceiverIsScopedNotGeneric(): void
    {
        // [MyClass::class, 'method'] always refers to MyClass's static method in real PHP
        // semantics — never a global function of the same name — so it resolves to a scoped
        // call, not the generic bare-name pool.
        $result = $this->parse('<?php
add_action("init", [MyClass::class, "static_method"]);
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('MyClass::static_method', $calls);

        $names = array_column($result->functionCalls, 'name');
        self::assertNotContains('static_method', $names);
    }

    public function testArrayCallbackWithLiteralStringReceiverIsScopedNotGeneric(): void
    {
        $result = $this->parse('<?php
add_action("init", ["MyClass", "static_method"]);
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('MyClass::static_method', $calls);
    }

    public function testArrayCallbackWithUnresolvableVariableReceiverStaysGeneric(): void
    {
        // $obj isn't $this, so its type can't be resolved — must fall back to the generic pool,
        // same as before this scoping existed.
        $result = $this->parse('<?php
add_action("init", [$obj, "handler_method"]);
');
        self::assertEmpty($result->scopedMethodCalls);
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('handler_method', $names);
    }

    public function testGetHeaderWithNameBuildsCorrectPath(): void
    {
        $result = $this->parse("<?php get_header('kiosk');");
        self::assertCount(1, $result->templateRefs);
        self::assertSame('header-kiosk', $result->templateRefs[0]->path);
    }

    public function testGetFooterWithNameBuildsCorrectPath(): void
    {
        $result = $this->parse("<?php get_footer('shop');");
        self::assertCount(1, $result->templateRefs);
        self::assertSame('footer-shop', $result->templateRefs[0]->path);
    }

    public function testGetSidebarWithNameBuildsCorrectPath(): void
    {
        $result = $this->parse("<?php get_sidebar('woo');");
        self::assertCount(1, $result->templateRefs);
        self::assertSame('sidebar-woo', $result->templateRefs[0]->path);
    }

    public function testGetHeaderNoArgIsRecorded(): void
    {
        $result = $this->parse('<?php get_header();');
        self::assertCount(1, $result->templateRefs);
        self::assertSame('', $result->templateRefs[0]->path);
    }

    public function testDollarOpenCurlyBracesInterpolationDoesNotCorruptClassContext(): void
    {
        // "${var}" syntax (T_DOLLAR_OPEN_CURLY_BRACES) must not corrupt brace depth
        $result = $this->parse('<?php
class MyClass {
    public function first() {
        $s = "${prefix}_suffix";
    }
    public function second() {}
}
');
        $methods = array_filter($result->functionDefs, fn($d) => $d->isMethod);
        $methodNames = array_column(array_values($methods), 'name');
        self::assertContains('first', $methodNames);
        self::assertContains('second', $methodNames);
    }

    public function testDoesNotFlagShortStringsAsCallbacks(): void
    {
        // 'wp' would match but should it? It has no parens context.
        // 'init' as a standalone string should not become a FunctionCall.
        $result = $this->parse("<?php \$x = 'init';");
        $names = array_column($result->functionCalls, 'name');
        // 'init' looks like a valid callback name but has no () — it IS added as string callback;
        // this is a known trade-off per spec (acceptable false positives), asserted explicitly
        // here so a future tightening of looksLikeCallback() shows up as an intentional change.
        self::assertContains('init', $names);
    }

    public function testTypeHintedParameterSeedsScopedMethodCall(): void
    {
        $result = $this->parse('<?php
class My_Service {
    public function render() {}
}
function boot(My_Service $svc) {
    $svc->render();
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My_Service::render', $calls);
        self::assertContains('My_Service', $result->classReferences);
    }

    public function testNullableTypeHintedParameterSeedsScopedMethodCall(): void
    {
        $result = $this->parse('<?php
class My_Service {
    public function render() {}
}
function boot(?My_Service $svc) {
    $svc->render();
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My_Service::render', $calls);
    }

    public function testSelfAndParentTypeHintsResolveAgainstOwnerClass(): void
    {
        $result = $this->parse('<?php
class Base {
    public function base_method() {}
}
class Child extends Base {
    public function take_self(self $a) { $a->base_method(); }
    public function take_parent(parent $b) { $b->base_method(); }
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('Child::base_method', $calls);
        self::assertContains('Base::base_method', $calls);
    }

    public function testUnionTypeHintedParameterIsReferencedButNotScoped(): void
    {
        $result = $this->parse('<?php
class A { public function foo() {} }
class B { public function foo() {} }
function boot(A|B $x) {
    $x->foo();
}
');
        self::assertContains('A', $result->classReferences);
        self::assertContains('B', $result->classReferences);
        // Ambiguous — can\'t know which of A/B $x actually is, so no scoped call should be
        // recorded; the call falls back to the generic unscoped pool instead.
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertEmpty($calls);
        self::assertContains('foo', array_column($result->functionCalls, 'name'));
    }

    public function testPrimitiveTypeHintsAreNotTreatedAsClassReferences(): void
    {
        $result = $this->parse('<?php
function boot(int $a, ?string $b, array $c, callable $d, iterable $e, mixed $f) {}
');
        self::assertEmpty($result->classReferences);
    }

    public function testPromotedConstructorPropertySeedsScopedMethodCall(): void
    {
        $result = $this->parse('<?php
class My_Service {
    public function render() {}
}
class My_Controller {
    public function __construct(private readonly My_Service $svc) {
        $this->svc;
        $svc->render();
    }
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My_Service::render', $calls);
    }

    public function testTypeHintedParameterDoesNotLeakAcrossFunctions(): void
    {
        $result = $this->parse('<?php
class My_Service { public function render() {} }
function first(My_Service $svc) {}
function second() {
    $svc = "not a service";
    $svc->render();
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertNotContains('My_Service::render', $calls);
    }

    private function parse(string $code): \WpSpecter\Parser\ParseResult
    {
        $file = $this->tmp . '/test_' . uniqid() . '.php';
        file_put_contents($file, $code);
        return $this->parser->parse($file);
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
