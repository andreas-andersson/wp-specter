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

    public function testVariableHookTagResolvesToItsLastKnownLiteralValue(): void
    {
        // $tag = 'init'; add_action($tag, ...); -- the variable's last-known literal value
        // resolves the tag exactly the same way a literal directly in the call already would.
        $result = $this->parse('<?php
$tag = "init";
add_action( $tag, "handler" );
');
        self::assertCount(1, $result->hookRegistrations);
        self::assertSame('init', $result->hookRegistrations[0]->tag);
        self::assertFalse($result->hookRegistrations[0]->isDynamic);
    }

    public function testUnresolvableVariableHookTagStillMarksDynamic(): void
    {
        // $tag comes from a function call, not a literal assignment -- no known value to
        // resolve, so this must still fall back to the old "fully dynamic" behavior.
        $result = $this->parse('<?php
$tag = get_dynamic_tag();
add_action( $tag, "handler" );
');
        self::assertCount(1, $result->hookRegistrations);
        self::assertTrue($result->hookRegistrations[0]->isDynamic);
    }

    public function testSelfClassConstantHookTagResolvesToItsLiteralValue(): void
    {
        // const HOOK_NAME = 'my_plugin_loaded'; ... add_action(self::HOOK_NAME, ...) -- the
        // constant's literal value resolves the tag exactly the same way a literal directly in
        // the call already would.
        $result = $this->parse('<?php
class My_Plugin {
    const HOOK_NAME = "my_plugin_loaded";
    public function register() {
        add_action( self::HOOK_NAME, "handler" );
    }
}
');
        self::assertCount(1, $result->hookRegistrations);
        self::assertSame('my_plugin_loaded', $result->hookRegistrations[0]->tag);
        self::assertFalse($result->hookRegistrations[0]->isDynamic);
    }

    public function testStaticAndExplicitClassNameConstantHookTagsAlsoResolve(): void
    {
        $result = $this->parse('<?php
class My_Plugin {
    const HOOK_NAME = "my_plugin_loaded";
    public function registerStatic() {
        add_action( static::HOOK_NAME, "handler" );
    }
}
function bootstrap() {
    add_action( My_Plugin::HOOK_NAME, "handler" );
}
');
        self::assertCount(2, $result->hookRegistrations);
        foreach ($result->hookRegistrations as $reg) {
            self::assertSame('my_plugin_loaded', $reg->tag);
            self::assertFalse($reg->isDynamic);
        }
    }

    public function testParentClassConstantHookTagResolvesAgainstTheParentClass(): void
    {
        $result = $this->parse('<?php
class Base_Plugin {
    const HOOK_NAME = "base_hook";
}
class My_Plugin extends Base_Plugin {
    public function register() {
        add_action( parent::HOOK_NAME, "handler" );
    }
}
');
        self::assertCount(1, $result->hookRegistrations);
        self::assertSame('base_hook', $result->hookRegistrations[0]->tag);
        self::assertFalse($result->hookRegistrations[0]->isDynamic);
    }

    public function testConstantValueBuiltFromConcatenationIsNotResolved(): void
    {
        // const HOOK_PREFIX = 'my_plugin_' . SOME_SUFFIX; -- not a bare literal, so must not be
        // guessed at, same "don't guess" stance as everywhere else in this parser.
        $result = $this->parse('<?php
class My_Plugin {
    const HOOK_NAME = "my_plugin_" . "loaded";
    public function register() {
        add_action( self::HOOK_NAME, "handler" );
    }
}
');
        self::assertCount(1, $result->hookRegistrations);
        self::assertTrue($result->hookRegistrations[0]->isDynamic);
    }

    public function testUnrelatedClassConstantWithTheSameNameDoesNotLeak(): void
    {
        // Other_Class's own HOOK_NAME must not resolve My_Plugin::HOOK_NAME's tag -- the lookup
        // is scoped by class name, not a bare constant-name match.
        $result = $this->parse('<?php
class Other_Class {
    const HOOK_NAME = "other_hook";
}
class My_Plugin {
    public function register() {
        add_action( self::HOOK_NAME, "handler" );
    }
}
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
        self::assertSame('', $result->hookInvocations[0]->tagSuffix);
    }

    public function testInterpolatedHookInvocationKeepsLiteralSuffix(): void
    {
        // WP_Widget's own real shape: do_action("{$this->id_base}_widget_updated") -- dynamic
        // first, literal last. Rarer than the prefix case (WP convention overwhelmingly puts the
        // static part first), but real for per-widget-ID hook naming.
        $result = $this->parse('<?php
class My_Widget {
    public $id_base = "my_widget";
    public function update_callback() {
        do_action("{$this->id_base}_widget_updated");
    }
}
');
        self::assertCount(1, $result->hookInvocations);
        self::assertTrue($result->hookInvocations[0]->isDynamic);
        self::assertSame('', $result->hookInvocations[0]->tag);
        self::assertSame('', $result->hookInvocations[0]->tagPrefix);
        self::assertSame('_widget_updated', $result->hookInvocations[0]->tagSuffix);
    }

    public function testConcatenatedHookInvocationKeepsLiteralSuffix(): void
    {
        $result = $this->parse('<?php
do_action($dynamic_part . "_widget_updated");
');
        self::assertCount(1, $result->hookInvocations);
        self::assertTrue($result->hookInvocations[0]->isDynamic);
        self::assertSame('', $result->hookInvocations[0]->tagPrefix);
        self::assertSame('_widget_updated', $result->hookInvocations[0]->tagSuffix);
    }

    public function testLiteralPrefixTakesPriorityOverASuffixWhenBothArePresent(): void
    {
        // "foo_{$x}_bar" technically has both a literal prefix ("foo_") and a literal suffix
        // ("_bar") -- the prefix case is checked first and wins, the same "first match wins,
        // don't over-engineer" stance classifyArgTokens already takes elsewhere.
        $result = $this->parse('<?php
do_action("foo_{$x}_bar");
');
        self::assertCount(1, $result->hookInvocations);
        self::assertSame('foo_', $result->hookInvocations[0]->tagPrefix);
        self::assertSame('', $result->hookInvocations[0]->tagSuffix);
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

    public function testPrefixedTemplateLoaderWrapperFunctionsAreRecognizedGenerically(): void
    {
        // `<prefix>_get_template_part()`/`<prefix>_get_template()` is a widely-replicated
        // WordPress-ecosystem naming convention, not one specific plugin's own invention —
        // confirmed independently in WooCommerce (`wc_get_template_part`/`wc_get_template`,
        // plus its documented legacy aliases `woocommerce_get_template_part`/
        // `woocommerce_get_template`) and Sydney theme (`sydney_get_template_part`). Matched by
        // suffix (isTemplateLoaderFunc()) rather than a fixed name list, so any other plugin's
        // own wrapper following the same convention (e.g. Easy Digital Downloads'
        // `edd_get_template_part()`, not itself in this project's real-world corpus) is covered
        // too, with no per-plugin addition ever needed.
        $result = $this->parse("<?php
wc_get_template_part( 'content', 'product' );
wc_get_template( 'single-product.php' );
woocommerce_get_template_part( 'content', 'single-product' );
woocommerce_get_template( 'checkout/form-checkout.php' );
sydney_get_template_part( 'content', 'quick-view' );
edd_get_template_part( 'content', 'download' );
");
        self::assertCount(6, $result->templateRefs);
        // arg 0 becomes the ref, same as get_template_part('slug', $name) — 'content' repeats
        // across 4 of these 6 calls for exactly that reason, not a test mistake.
        $paths = array_column($result->templateRefs, 'path');
        self::assertContains('content', $paths);
        self::assertContains('single-product.php', $paths);
        self::assertContains('checkout/form-checkout.php', $paths);
    }

    public function testFunctionNameMerelyContainingGetTemplateIsNotTreatedAsALoader(): void
    {
        // The suffix match is deliberately exact ("_get_template_part"/"_get_template", not a
        // bare "contains get_template" substring check) — a function that just happens to share
        // some of those words, or that's plural ("templates"), must not false-positive into
        // treating an unrelated argument as a template reference.
        $result = $this->parse("<?php
get_template_directory();
wc_get_templates( 'not-a-loader' );
some_get_template_data( 'also-not-a-loader' );
");
        self::assertEmpty($result->templateRefs);
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
        self::assertSame('My_Widget', $result->classDefs[0]->fqcn);
        self::assertSame(['WP_Widget'], array_map(fn($ref) => $ref->short, $result->classDefs[0]->extends));
        self::assertSame(['Countable'], array_map(fn($ref) => $ref->short, $result->classDefs[0]->implements));

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

    public function testMethodsInsideInterfacesAreOwnedByTheInterface(): void
    {
        // Regression: interface method declarations never have a body, so there's no $this->
        // scoping to resolve *inside* them — but leaving ownerClass null made the declaration
        // itself unmatchable by anything (ClassAnalyzer::isContractMethod short-circuits on a
        // null ownerClass before checking any contract, and a scoped call through a
        // this-exact-interface-typed variable had nothing to match against). It must be owned by
        // the interface, same as a trait's own methods are owned by the trait.
        $result = $this->parse('<?php
interface My_Interface {
    public function do_it(): void;
}
');
        $methods = array_filter($result->functionDefs, fn($d) => $d->isMethod);
        foreach ($methods as $m) {
            self::assertSame('My_Interface', $m->ownerClass, "{$m->name} should be owned by My_Interface");
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
        self::assertSame(['Base_A', 'Base_B'], array_map(fn($ref) => $ref->short, $defsByName['Combined']->extends));
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
        self::assertSame(['Has_Label'], array_map(fn($ref) => $ref->short, $defsByName['Status']->implements));
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

    public function testFileLevelUseImportIsRecorded(): void
    {
        $result = $this->parse('<?php
use Some\Namespace\Thing;
use Other\Space\Base as Aliased;
class My_Class {}
');
        self::assertSame('Some\Namespace\Thing', $result->useImports['Thing']);
        self::assertSame('Other\Space\Base', $result->useImports['Aliased']);
        self::assertArrayNotHasKey('Base', $result->useImports);
    }

    public function testUseFunctionAndUseConstImportsAreNotRecordedAsClasses(): void
    {
        $result = $this->parse('<?php
use function My\Ns\helper;
use const My\Ns\SOME_CONST;
class My_Class {}
');
        self::assertEmpty($result->useImports);
    }

    public function testGroupUseImportDoesNotCorruptSubsequentParsing(): void
    {
        // Group use isn\'t supported for the import map (bails out without recording anything),
        // but its braces must not desync brace-depth tracking for the rest of the file.
        $result = $this->parse('<?php
use App\{Foo, Bar as B};
class My_Class {
    public function used() {}
}
$x = new My_Class();
$x->used();
');
        self::assertContains('My_Class', $result->classReferences);
        self::assertNotEmpty($result->scopedMethodCalls);
    }

    public function testAliasedUseImportIsResolvedToItsRealNameInClassReferences(): void
    {
        // Real-world shape (Elementor): `use Some\Namespace\Trigger_Value as TriggerValueValidator;`
        // then referencing the class only via its alias (`new`, `Alias::method()`, `extends
        // Alias`) — findUnusedClasses() matches $classReferences against each class's own
        // declared short name, so a reference recorded under the alias itself never matched the
        // real "Trigger_Value"/"Loader"/"Base" declaration and looked permanently unused despite
        // being genuinely referenced. Covers all three capture sites: captureClassNameAfter()
        // (`new`), the T_STRING `::` branch, and captureClassNameList() (`extends`).
        $result = $this->parse('<?php
namespace App;
use Some\Namespace\Trigger_Value as TriggerValueValidator;
use Some\Namespace\Loader as Assets_Loader;
use Some\Namespace\Base as Aliased_Base;
class Consumer {
    public function boot() {
        $x = new Assets_Loader();
        TriggerValueValidator::is_valid();
    }
}
class Child extends Aliased_Base {}
');
        self::assertContains('Trigger_Value', $result->classReferences);
        self::assertContains('Loader', $result->classReferences);
        self::assertContains('Base', $result->classReferences);
        self::assertNotContains('TriggerValueValidator', $result->classReferences);
        self::assertNotContains('Assets_Loader', $result->classReferences);
        self::assertNotContains('Aliased_Base', $result->classReferences);
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

    public function testFullyQualifiedAndNamespaceQualifiedStaticCallsResolveAsClassReferences(): void
    {
        // \SwiftQueue\License_Bridge::initialize() tokenizes the receiver as a single
        // T_NAME_FULLY_QUALIFIED token (PHP 8.0+), not T_STRING -- must still register as a
        // class reference and a scoped method call, the same as a bare `License_Bridge::` would.
        $result = $this->parse('<?php
namespace SwiftQueue;
class License_Bridge { public static function initialize() {} }
class Wp_Cli_Commands { public static function register() {} }
function bootstrap() {
    \SwiftQueue\License_Bridge::initialize();
    Ns\Wp_Cli_Commands::register();
}
');
        self::assertContains('License_Bridge', $result->classReferences);
        self::assertContains('Wp_Cli_Commands', $result->classReferences);

        $calls = array_map(
            fn($c) => $c->receiverClass . '::' . $c->method,
            $result->scopedMethodCalls,
        );
        // receiverClass is now the fully-resolved FQCN, not the bare short name: the fully-
        // qualified call resolves to SwiftQueue\License_Bridge (leading backslash stripped); the
        // merely-qualified one resolves relative to the current namespace, SwiftQueue\Ns\
        // Wp_Cli_Commands (no matching `use` import for its first segment "Ns").
        self::assertContains('SwiftQueue\License_Bridge::initialize', $calls);
        self::assertContains('SwiftQueue\Ns\Wp_Cli_Commands::register', $calls);
    }

    public function testFullyQualifiedAndNamespaceQualifiedBareFunctionCallsAreCredited(): void
    {
        // \My_Theme\my_helper() and Sub\my_other_helper() tokenize as a single
        // T_NAME_FULLY_QUALIFIED/T_NAME_QUALIFIED token, not T_STRING -- the only call-detection
        // branch fired on T_STRING before this fix, so a real, called function still looked
        // unused. Resolved to its unqualified tail the same way a qualified class reference
        // already is, since a function can only ever be *declared* with a bare name in PHP.
        $result = $this->parse('<?php
namespace My_Theme;
function bootstrap() {
    \My_Theme\my_helper();
    Sub\my_other_helper();
}
');
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('my_helper', $names);
        self::assertContains('my_other_helper', $names);
    }

    public function testFullyQualifiedWpCoreHookCallIsRecognized(): void
    {
        // \add_action(...) -- a namespaced file explicitly opting out of its own namespace for a
        // WP core global. Before this fix, the whole name-comparison dispatch (hook/template/
        // glob/define/existence-check) only ever ran for a T_STRING call, so this was invisible
        // to hook detection entirely, not just FunctionAnalyzer.
        $result = $this->parse('<?php
namespace My_Theme;
function boot() {
    \add_action( "my_namespaced_hook", "handler" );
}
');
        self::assertCount(1, $result->hookRegistrations);
        self::assertSame('my_namespaced_hook', $result->hookRegistrations[0]->tag);
        self::assertFalse($result->hookRegistrations[0]->isDynamic);
    }

    public function testFullyQualifiedWpCoreHookInvocationIsRecognized(): void
    {
        $result = $this->parse('<?php
namespace My_Theme;
function boot() {
    \do_action( "my_namespaced_hook" );
}
');
        self::assertCount(1, $result->hookInvocations);
        self::assertSame('my_namespaced_hook', $result->hookInvocations[0]->tag);
    }

    public function testNamespaceQualifiedTemplatePartCallIsRecognized(): void
    {
        $result = $this->parse('<?php
namespace My_Theme;
function render() {
    Sub\get_template_part( "template-parts/hero" );
}
');
        self::assertCount(1, $result->templateRefs);
        self::assertSame('template-parts/hero', $result->templateRefs[0]->path);
    }

    public function testFullyQualifiedClassExistsGuardIsStillExcludedFromClassReferences(): void
    {
        // \class_exists('X') guarding X's own definition must not count as a usage signal, the
        // same exclusion the plain class_exists() shape already gets.
        $result = $this->parse('<?php
namespace My_Theme;
if ( ! \class_exists( "My_Guarded_Class" ) ) {
    class My_Guarded_Class {}
}
');
        self::assertEmpty($result->functionCalls);
    }

    public function testBareStringClassMethodCallbackRegistersClassReferenceAndScopedCall(): void
    {
        // 'render_callback' => 'Astra_Customizer_Partials::render_partial_site_title' -- a WP
        // customizer/REST-controller callback given as a plain "Class::method" string, no array
        // involved. The trailing segment ("render_partial_site_title") was already extracted as
        // a callback, but the class segment before the "::" was discarded -- the class itself
        // looked permanently unused despite being reached exactly this way.
        $result = $this->parse("<?php
\$config = [
    'render_callback' => 'Astra_Customizer_Partials::render_partial_site_title',
];
");
        self::assertContains('Astra_Customizer_Partials', $result->classReferences);

        $calls = array_map(
            fn($c) => $c->receiverClass . '::' . $c->method,
            $result->scopedMethodCalls,
        );
        self::assertContains('Astra_Customizer_Partials::render_partial_site_title', $calls);
    }

    public function testNamespaceConcatenatedClassMethodCallbackUsesCurrentNamespace(): void
    {
        // LiteSpeed Cache's uninstall callback is written as `__NAMESPACE__ .
        // '\Activation::uninstall_litespeed_cache'`. Its literal fragment starts with a slash,
        // but that slash joins the namespace magic constant to the class suffix; it must not
        // resolve as globally-qualified Activation.
        $result = $this->parse('<?php
namespace LiteSpeed;
register_uninstall_hook(
    __FILE__,
    __NAMESPACE__ . "\Activation::uninstall_litespeed_cache",
);
');

        self::assertContains('Activation', $result->classReferences);
        $calls = array_map(
            fn($c) => $c->receiverClass . '::' . $c->method,
            $result->scopedMethodCalls,
        );
        self::assertContains('LiteSpeed\Activation::uninstall_litespeed_cache', $calls);
    }

    public function testConcatenatedArrayCallbackMethodNameEnumeratesExactCallsAcrossBoundedLoop(): void
    {
        // Real-world finding (Astra theme): `add_action('astra_footer_html_'.$i, array($this,
        // 'footer_html_'.$i))` inside a `for` loop wiring N numbered component slots. The loop
        // here is a clean bounded-ascending range (parseForLoopBoundedRange recognizes it), so
        // every concrete method name it actually produces is now enumerated exactly
        // (My_Builder::footer_html_1..4) instead of falling back to the coarser prefix match —
        // see the sibling test just below for the case where the bound genuinely isn't literal
        // and the prefix fallback is still the right, and only, available answer.
        $result = $this->parse('<?php
class My_Builder {
    public function wire() {
        for ($i = 1; $i <= 4; $i++) {
            add_action("astra_footer_html_" . $i, array($this, "footer_html_" . $i));
        }
    }
    public function footer_html_1() {}
}
');
        self::assertEmpty($result->scopedMethodCallPrefixes);
        $calls = array_map(
            fn($c) => $c->receiverClass . '::' . $c->method,
            $result->scopedMethodCalls,
        );
        self::assertSame([
            'My_Builder::footer_html_1',
            'My_Builder::footer_html_2',
            'My_Builder::footer_html_3',
            'My_Builder::footer_html_4',
        ], $calls);
    }

    public function testConcatenatedArrayCallbackMethodNameEnumeratesExactCallsAcrossForeachLiteralArray(): void
    {
        // Real-world finding (WooCommerce): includes/class-wc-breadcrumb.php's
        // `foreach ($conditionals as $conditional) { if (call_user_func($conditional)) {
        // call_user_func([$this, 'add_crumbs_' . substr($conditional, 3)]); break; } }` where
        // $conditionals is a plain literal array ('is_home', 'is_404', ...). The foreach-over-
        // literal-array sibling of the bounded for-loop mechanism above, plus the one additional
        // transform it has evidence for: substr($var, N) with a fixed literal offset (here
        // stripping the shared "is_" prefix) applied before concatenation.
        $result = $this->parse('<?php
class WC_Breadcrumb {
    public function wire() {
        $conditionals = array("is_home", "is_404");
        foreach ($conditionals as $conditional) {
            call_user_func(array($this, "add_crumbs_" . substr($conditional, 3)));
        }
    }
    public function add_crumbs_home() {}
    public function add_crumbs_404() {}
}
');
        $calls = array_map(
            fn($c) => $c->receiverClass . '::' . $c->method,
            $result->scopedMethodCalls,
        );
        self::assertContains('WC_Breadcrumb::add_crumbs_home', $calls);
        self::assertContains('WC_Breadcrumb::add_crumbs_404', $calls);
    }

    public function testSelfDispatchMethodRecordsSuffixTemplate(): void
    {
        // Real-world shape (Sydney theme): `get_section($section) { call_user_func([$this,
        // "{$section}_section"]); }` — a same-class self-dispatch built from the method's own
        // parameter.
        $result = $this->parse('<?php
class Style_Book {
    public function get_section( $section ) {
        call_user_func( array( $this, "{$section}_section" ) );
    }
}
');
        self::assertSame(['_section'], $result->selfDispatchSuffixes['Style_Book::get_section'] ?? null);
    }

    public function testScopedCallWithLiteralArgToSelfDispatchMethodRecordsPendingCall(): void
    {
        $result = $this->parse('<?php
class Style_Book {
    public function render() {
        $this->get_section( "colors" );
    }
}
');
        self::assertCount(1, $result->pendingSelfDispatchCalls);
        self::assertSame('Style_Book', $result->pendingSelfDispatchCalls[0]->receiverClass);
        self::assertSame('get_section', $result->pendingSelfDispatchCalls[0]->methodName);
        self::assertSame('colors', $result->pendingSelfDispatchCalls[0]->literalArg);
    }

    public function testPrefixVarSuffixSelfDispatchMethodRecordsTemplate(): void
    {
        // Real-world shape (WooCommerce): `render_columns( $column, $post_id ) { if (
        // is_callable( array( $this, 'render_' . $column . '_column' ) ) ) { $this->{"render_
        // {$column}_column"}(); } }` — the plain-concatenation counterpart to
        // testSelfDispatchMethodRecordsSuffixTemplate above, with both a prefix and a suffix.
        $result = $this->parse('<?php
class WC_Admin_List_Table {
    public function render_columns( $column, $post_id ) {
        if ( is_callable( array( $this, "render_" . $column . "_column" ) ) ) {
            echo 1;
        }
    }
}
');
        self::assertSame(
            [['render_', '_column']],
            $result->selfDispatchPrefixSuffixTemplates['WC_Admin_List_Table::render_columns'] ?? null,
        );
    }

    public function testArrayKeyLiteralAssignmentIsTrackedPerOwnerClass(): void
    {
        // Real-world shape (WooCommerce): `define_columns()` builds `$show_columns['thumb'] =
        // ...; $show_columns['name'] = ...;` — a local variable, unrelated to the dispatcher
        // method that later consumes the domain these keys establish.
        $result = $this->parse('<?php
class WC_Admin_List_Table_Products {
    public function define_columns( $columns ) {
        $show_columns = array();
        $show_columns["thumb"] = "Image";
        $show_columns["name"] = "Name";
        return $show_columns;
    }
}
');
        self::assertSame(
            ['thumb', 'name'],
            $result->classArrayKeyLiterals['WC_Admin_List_Table_Products'] ?? null,
        );
    }

    public function testConcatenatedArrayCallbackMethodNameRegistersAScopedPrefixWhenLoopBoundIsNotLiteral(): void
    {
        // Same shape as the sibling test above, but the loop's upper bound comes from a
        // variable, not a literal int — parseForLoopBoundedRange() deliberately doesn't guess
        // here (the whole point of only recognizing a *provably* finite range), so the suffix
        // stays unresolvable and must still fall back to the coarser prefix match, exactly as
        // before this mechanism existed.
        $result = $this->parse('<?php
class My_Builder {
    public function wire( $count ) {
        for ($i = 1; $i <= $count; $i++) {
            add_action("astra_footer_html_" . $i, array($this, "footer_html_" . $i));
        }
    }
}
');
        self::assertEmpty($result->scopedMethodCalls);
        $prefixes = array_map(
            fn($p) => $p->receiverClass . '::' . $p->prefix,
            $result->scopedMethodCallPrefixes,
        );
        self::assertContains('My_Builder::footer_html_', $prefixes);
    }

    public function testBareCallbackNameConcatenatedWithBoundedLoopVarEnumeratesEveryRealName(): void
    {
        // Real-world finding (Sydney theme): `'render_callback' =>
        // 'sydney_partial_slider_title_' . $i` inside `for ($i = 1; $i < 5; $i++)` — a bare
        // (no array, no receiver) callback-name literal split across a loop-counter
        // concatenation. Without loop-range tracking this either records the single truncated
        // prefix as a wrong exact-match name, or (once a receiver-based prefix mechanism exists)
        // still can't apply here since there's no resolvable receiver at all — every one of the
        // 4 real functions must be enumerated by name directly into the unscoped call pool.
        $result = $this->parse('<?php
for ($i = 1; $i < 5; $i++) {
    $x = array(
        "render_callback" => "sydney_partial_slider_title_" . $i,
    );
}
');
        // "render_callback" itself (the array key) also passes through this same general
        // string-literal handling and lands in the pool too — pre-existing, unrelated "coarse
        // net" behavior (any bare identifier-shaped literal anywhere is a candidate), not
        // something this test is about; only the 4 enumerated names matter here.
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('sydney_partial_slider_title_1', $names);
        self::assertContains('sydney_partial_slider_title_2', $names);
        self::assertContains('sydney_partial_slider_title_3', $names);
        self::assertContains('sydney_partial_slider_title_4', $names);
        self::assertNotContains('sydney_partial_slider_title_5', $names);
        self::assertNotContains('sydney_partial_slider_title_', $names);
    }

    public function testFilePathConcatenatedWithBoundedLoopVarEnumeratesEveryPhpPathString(): void
    {
        // General form of the file-path-via-concatenation shape a bounded loop can split a
        // literal across, mirroring the callback-name case above but for phpPathStrings instead
        // of functionCalls — the prefix alone ('icons-v6-') doesn't end in '.php', so the plain
        // single-literal check never fires without this.
        $result = $this->parse('<?php
for ($i = 0; $i <= 3; $i++) {
    $x = "icons-v6-" . $i . ".php";
}
');
        self::assertSame([
            'icons-v6-0.php',
            'icons-v6-1.php',
            'icons-v6-2.php',
            'icons-v6-3.php',
        ], $result->phpPathStrings);
    }

    public function testInterpolatedStringWithBoundedLoopVarEnumeratesEveryPhpPathString(): void
    {
        // Real-world shape (Astra theme): "{$icons_dir}/icons-v6-{$i}.php" — $icons_dir is
        // derived from a WP-core plugin_dir_path() call this parser can't (and doesn't need to)
        // resolve; only the "/icons-v6-" (adjacent literal before $i) and ".php" (adjacent
        // literal after) matter, since FileAnalyzer matches phpPathStrings by basename anyway.
        $result = $this->parse('<?php
$icons_dir = some_theme_dir_constant();
for ($i = 0; $i < 4; $i++) {
    $file = "{$icons_dir}/icons-v6-{$i}.php";
}
');
        self::assertSame([
            '/icons-v6-0.php',
            '/icons-v6-1.php',
            '/icons-v6-2.php',
            '/icons-v6-3.php',
        ], $result->phpPathStrings);
    }

    public function testInterpolatedStringWithSimpleDollarVarSyntaxAlsoEnumerates(): void
    {
        // Same mechanism, the other (bare "$var", no curly braces) interpolation syntax.
        $result = $this->parse('<?php
for ($i = 0; $i < 2; $i++) {
    $file = "icons-$i.php";
}
');
        self::assertSame(['icons-0.php', 'icons-1.php'], $result->phpPathStrings);
    }

    public function testInterpolatedStringWithoutTrailingPhpSuffixIsNotEnumerated(): void
    {
        // Only the '.php' file-path consumption context has real-world evidence — a
        // non-'.php'-suffixed interpolated string (a CSS class, a hook tag, ...) must be left
        // alone rather than guessed at.
        $result = $this->parse('<?php
for ($i = 0; $i < 3; $i++) {
    $class = "item-{$i}";
}
');
        self::assertEmpty($result->phpPathStrings);
    }

    public function testInterpolatedStringWithComplexExpressionBailsEntirely(): void
    {
        // {$obj->prop} — a property access inside the interpolation, not a bare variable — must
        // be left completely unresolved rather than mis-parsed.
        $result = $this->parse('<?php
for ($i = 0; $i < 3; $i++) {
    $file = "{$this->dir}/icons-{$i}.php";
}
');
        self::assertEmpty($result->phpPathStrings);
    }

    public function testUnboundedForLoopDoesNotEnumerateConcatenatedLiterals(): void
    {
        // A non-canonical for-loop (non-literal bound) must leave the loop variable completely
        // untracked — no enumeration, no crash, same behavior as before this mechanism existed.
        $result = $this->parse('<?php
function boot( $count ) {
    for ($i = 1; $i < $count; $i++) {
        my_helper( "item_" . $i );
    }
}
');
        $names = array_column($result->functionCalls, 'name');
        self::assertNotContains('item_1', $names);
        self::assertNotContains('item_2', $names);
    }

    public function testWpCliAddCommandRegistersTheClassForReflectionDispatch(): void
    {
        // WP_CLI::add_command('astra abilities', 'Astra_Abilities_CLI') hands WP-CLI a class
        // name it dispatches across by reflection — whichever public method matches the typed
        // subcommand runs, not a fixed method name a curated contract list could name up front.
        $result = $this->parse('<?php
WP_CLI::add_command( "astra abilities", "Astra_Abilities_CLI" );
');
        self::assertContains('Astra_Abilities_CLI', $result->reflectionDispatchedClassNames);
    }

    public function testWpCliAddCommandAcceptsClassConstantAsSecondArgument(): void
    {
        // Real-world shape (Elementor): WP_CLI::add_command('elementor experiments',
        // WP_CLI::class) — the idiomatic modern-PHP way to reference a class by name, instead
        // of a plain string literal.
        $result = $this->parse('<?php
namespace Elementor\Core\Experiments;
WP_CLI::add_command( "elementor experiments", WP_CLI::class );
');
        self::assertContains('Elementor\Core\Experiments\WP_CLI', $result->reflectionDispatchedClassNames);
    }

    public function testWpCliAddCommandIsRecognizedViaAFullyQualifiedCall(): void
    {
        // Real-world shape (Elementor): `\WP_CLI::add_command('elementor kit', WP_CLI::class)`,
        // explicitly opting out of the file's own namespace for the real global WP_CLI class —
        // tokenizes as T_NAME_FULLY_QUALIFIED, not T_STRING, a completely different dispatch
        // branch that previously had no equivalent WP_CLI-specific check at all.
        $result = $this->parse('<?php
namespace Elementor\Core\Experiments;
\WP_CLI::add_command( "elementor kit", WP_CLI::class );
');
        self::assertContains('Elementor\Core\Experiments\WP_CLI', $result->reflectionDispatchedClassNames);
    }

    public function testPropertyAssignedNewClassIsTrackedAndConsultedFromAnotherMethod(): void
    {
        // $this->service = new My_Service() in the constructor, read via $this->service->render()
        // from a different method entirely — the class-scoped counterpart to $varTypesStack's
        // per-function local-variable tracking.
        $result = $this->parse('<?php
class My_Controller {
    public function __construct() {
        $this->service = new My_Service();
    }
    public function boot() {
        $this->service->render();
    }
}
');
        self::assertSame(
            ['My_Controller' => ['service' => 'My_Service']],
            $result->propertyAssignedClasses,
        );
        self::assertCount(1, $result->propertyMethodCalls);
        self::assertSame('My_Controller', $result->propertyMethodCalls[0]->ownerClass);
        self::assertSame('service', $result->propertyMethodCalls[0]->property);
        self::assertSame('render', $result->propertyMethodCalls[0]->method);
    }

    public function testPropertyAssignedFromAStaticFactoryCallIsTracked(): void
    {
        // Real-world shape (LiteSpeed Cache): a shared base class's `cls()` factory method uses
        // late static binding (`get_called_class()`), so `Cloud::cls()` always returns a `Cloud`
        // instance regardless of `cls()`'s own (undeclared) return type. Previously invisible to
        // $propertyAssignedClasses, so $this->cloud->init_qc_cdn_cli() always fell back to the
        // unscoped pool.
        $result = $this->parse('<?php
class Report {
    private $cloud;
    public function __construct() {
        $this->cloud = Cloud::cls();
    }
    public function send() {
        $this->cloud->init_qc_cdn_cli();
    }
}
');
        self::assertSame(
            ['Report' => ['cloud' => 'Cloud']],
            $result->propertyAssignedClasses,
        );
        self::assertCount(1, $result->propertyMethodCalls);
        self::assertSame('init_qc_cdn_cli', $result->propertyMethodCalls[0]->method);
    }

    public function testLocalVariableAssignedFromAStaticFactoryCallIsTracked(): void
    {
        // Same static-factory convention as testPropertyAssignedFromAStaticFactoryCallIsTracked
        // above, but for a plain local variable (the $varTypesStack counterpart) rather than a
        // property — e.g. `WPCOM_JSON_API_Links::getInstance()` (Jetpack).
        $result = $this->parse('<?php
function boot() {
    $links = WPCOM_JSON_API_Links::getInstance();
    $links->wpcom_link_for(1);
}
');
        // Two calls recorded: WPCOM_JSON_API_Links::getInstance() itself (an ordinary scoped
        // static call, unrelated to this fix) and $links->wpcom_link_for() — resolved only
        // because assignedStaticFactoryClassName tracked $links as a WPCOM_JSON_API_Links.
        $methods = array_map(static fn($c) => [$c->receiverClass, $c->method], $result->scopedMethodCalls);
        self::assertContains(['WPCOM_JSON_API_Links', 'wpcom_link_for'], $methods);
    }

    public function testPropertyAssignedFromATypedParameterIsTracked(): void
    {
        // Real-world case (Elementor's Data\V2\Base\Base_Route): a type-hinted constructor
        // parameter manually assigned to a property -- not `new ClassName()`, not
        // constructor-promoted -- previously invisible to $propertyAssignedClasses entirely, so
        // $this->controller->get_permission_callback() (called from a completely different
        // method) always fell back to the unscoped pool no matter what.
        $result = $this->parse('<?php
class Base_Route {
    protected $controller;
    protected function __construct(Controller $controller) {
        $this->controller = $controller;
    }
    public function dispatch() {
        $this->controller->get_permission_callback();
    }
}
');
        self::assertSame(
            ['Base_Route' => ['controller' => 'Controller']],
            $result->propertyAssignedClasses,
        );
        self::assertCount(1, $result->propertyMethodCalls);
        self::assertSame('get_permission_callback', $result->propertyMethodCalls[0]->method);
    }

    public function testExternalPropertyAssignmentOfThisOntoATypedParameterIsTracked(): void
    {
        // Real-world shape (Wordfence's bundled Diff library): `function render(Diff_Renderer_
        // Abstract $renderer) { $renderer->diff = $this; return $renderer->render(); }` — the
        // exact inverse of testPropertyAssignedFromATypedParameterIsTracked above: here the
        // assignment TARGET is the external typed parameter, and the VALUE is the current
        // instance. Written into the same $propertyAssignedClasses map, keyed by the parameter's
        // own declared type rather than the current class.
        $result = $this->parse('<?php
class Diff {
    public function render(Diff_Renderer_Abstract $renderer) {
        $renderer->diff = $this;
        return $renderer->render();
    }
}
');
        self::assertSame(
            ['Diff_Renderer_Abstract' => ['diff' => 'Diff']],
            $result->propertyAssignedClasses,
        );
    }

    public function testExternalPropertyAssignmentOfAnythingOtherThanThisIsNotTracked(): void
    {
        // Only $this (a variable/property gaining the *current* instance) is evidenced — an
        // ordinary $renderer->diff = $some_other_var; must not be misread as this shape.
        $result = $this->parse('<?php
class Diff {
    public function render(Diff_Renderer_Abstract $renderer) {
        $renderer->diff = $some_other_var;
    }
}
');
        self::assertSame([], $result->propertyAssignedClasses);
    }

    public function testPropertyAssignedFromAnUntypedParameterIsNotTracked(): void
    {
        // No type hint at all -- nothing for $varTypesStack to have recorded, so there's no
        // class to propagate; must not be mistaken for anything.
        $result = $this->parse('<?php
class Base_Route {
    protected function __construct($controller) {
        $this->controller = $controller;
    }
}
');
        self::assertSame([], $result->propertyAssignedClasses);
    }

    public function testPropertyAssignedFromAMethodCallOnAVariableIsNotTracked(): void
    {
        // $this->prop = $var->method(); -- more than a bare "$var;" RHS, must bail rather than
        // guess (same "don't guess" stance as everywhere else in this parser).
        $result = $this->parse('<?php
class Base_Route {
    protected function __construct(Controller $controller) {
        $this->controller = $controller->clone();
    }
}
');
        self::assertSame([], $result->propertyAssignedClasses);
    }

    public function testConstructorPromotedPropertyIsTrackedAsAnImplicitAssignment(): void
    {
        // public function __construct(private My_Service $svc) {} auto-assigns $this->svc — same
        // effect as an explicit $this->svc = $svc; in the constructor body.
        $result = $this->parse('<?php
class My_Controller {
    public function __construct(private My_Service $svc) {}
}
');
        self::assertSame(
            ['My_Controller' => ['svc' => 'My_Service']],
            $result->propertyAssignedClasses,
        );
    }

    public function testUntypedOrPlainConstructorParameterIsNotTrackedAsAPropertyAssignment(): void
    {
        // No visibility modifier -> not promotion, just an ordinary typed parameter (already
        // covered by $varTypesStack, not $propertyAssignedClasses).
        $result = $this->parse('<?php
class My_Controller {
    public function __construct(My_Service $svc) {}
}
');
        self::assertSame([], $result->propertyAssignedClasses);
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
        // $s's `new My_Service()` type is invalidated by the reassignment to some_factory() — a
        // bare call now tracked as a *pending* return-typed call (see PendingReturnTypedCall)
        // rather than the old behavior of falling straight into the unscoped $functionCalls pool;
        // ClassAnalyzer resolves it later (or falls back to the unscoped pool itself if
        // some_factory's return type never resolves — see ClassAnalyzerTest).
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
        self::assertCount(1, $result->pendingReturnTypedCalls);
        self::assertNull($result->pendingReturnTypedCalls[0]->sourceReceiverClass);
        self::assertSame('some_factory', $result->pendingReturnTypedCalls[0]->sourceMethod);
        self::assertSame('render', $result->pendingReturnTypedCalls[0]->readMethod);
    }

    public function testMethodHasIncludeInBodyDetectsDirectRequire(): void
    {
        $result = $this->parse('<?php
class File_Loader {
    public function loadPhpFiles( $dir ) {
        require_once $dir . "/x.php";
    }
    public function noop() {}
}
');
        $defs = [];
        foreach ($result->functionDefs as $def) {
            $defs[$def->name] = $def;
        }
        self::assertTrue($defs['loadPhpFiles']->hasIncludeInBody);
        self::assertFalse($defs['noop']->hasIncludeInBody);
    }

    public function testMethodHasIncludeInBodyDetectsRequireInsideNestedClosure(): void
    {
        // Real-world shape (Flynt theme): loadPhpFiles() itself has no require directly in its
        // own body — it delegates to a closure passed to a directory-iteration helper, and the
        // require lives inside *that* closure instead.
        $result = $this->parse('<?php
class File_Loader {
    public function loadPhpFiles( $dir ) {
        $this->iterate( $dir, function ( $file ) {
            require_once $file;
        } );
    }
}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertTrue($defs['loadPhpFiles']->hasIncludeInBody);
    }

    public function testScopedCallWithLiteralFirstArgRecordsPendingDirectoryLoaderCall(): void
    {
        $result = $this->parse('<?php
File_Loader::loadPhpFiles( "inc" );
');
        self::assertCount(1, $result->pendingDirectoryLoaderCalls);
        self::assertSame('File_Loader', $result->pendingDirectoryLoaderCalls[0]->receiverClass);
        self::assertSame('loadPhpFiles', $result->pendingDirectoryLoaderCalls[0]->methodName);
        self::assertSame('inc', $result->pendingDirectoryLoaderCalls[0]->literalArg);
    }

    public function testTernaryAssignedVariablePassedToParamSuffixMethodRecordsPendingCall(): void
    {
        // Real-world shape (wp-nested-pages): `$row_view = ( $this->post->type !== 'np-redirect'
        // ) ? 'partials/row' : 'partials/row-link'; include( Helpers::view($row_view) );` where
        // `Helpers::view($file) { return dirname(__FILE__) . '/Views/' . $file . '.php'; }`.
        $result = $this->parse('<?php
$row_view = ( $cond !== "x" ) ? "partials/row" : "partials/row-link";
include( Helpers::view( $row_view ) );
');
        self::assertCount(1, $result->pendingParamSuffixCalls);
        self::assertSame('Helpers', $result->pendingParamSuffixCalls[0]->receiverClass);
        self::assertSame('view', $result->pendingParamSuffixCalls[0]->methodName);
        self::assertSame(['partials/row', 'partials/row-link'], $result->pendingParamSuffixCalls[0]->argumentCandidates);
    }

    public function testSiblingComparisonEstablishedVariableConcatenatedIntoParamSuffixCallArgument(): void
    {
        // Real-world shape (wp-nested-pages): `if ( $tab == 'general' ) ...; if ( $tab ==
        // 'posttypes' ) ...; include(NestedPages\Helpers::view('settings/settings-' . $tab));` —
        // $tab's domain comes from being tested against literals in sibling `if` conditions, not
        // from being assigned one.
        $result = $this->parse('<?php
if ( $tab == "general" ) { echo 1; }
if ( $tab == "posttypes" ) { echo 2; }
include( NestedPages\Helpers::view( "settings/settings-" . $tab ) );
');
        self::assertCount(1, $result->pendingParamSuffixCalls);
        self::assertSame('NestedPages\Helpers', $result->pendingParamSuffixCalls[0]->receiverClass);
        self::assertSame('view', $result->pendingParamSuffixCalls[0]->methodName);
        self::assertSame(
            ['settings/settings-general', 'settings/settings-posttypes'],
            $result->pendingParamSuffixCalls[0]->argumentCandidates,
        );
    }

    public function testReturnConcatenationWithOwnParamAndTrailingLiteralRecordsSuffixTemplate(): void
    {
        // Helpers::view()'s own body — see resolveReturnParamSuffixTemplate's own docblock. The
        // unresolvable `dirname(__FILE__)` prefix is simply never looked at.
        $result = $this->parse('<?php
class Helpers {
    public static function view($file) {
        return dirname(__FILE__) . "/Views/" . $file . ".php";
    }
}
');
        self::assertSame(['.php'], $result->functionParamSuffixReturns['Helpers::view'] ?? null);
    }

    public function testLiteralPathPropagationCapturesPositionalAndKeyedWrapperInputs(): void
    {
        // Blocksy supplies both shapes in production: a positional options slug and a keyed
        // `name` within dynamic-style arguments. Both form a fixed path, then hand it to the
        // same require helper in another function rather than including it directly.
        $result = $this->parse('<?php
function require_file( $file ) {
    require $file;
}
function load_options( $slug ) {
    $file = get_template_directory() . "/inc/options/" . $slug . ".php";
    require_file( $file );
}
function load_styles( $args ) {
    $args = wp_parse_args( $args, [] );
    $args["path"] = get_template_directory() . "/inc/styles/" . $args["name"] . ".php";
    require_file( $args["path"] );
}
load_options( "general/buttons" );
load_styles( [ "name" => "global-inline", "css" => $css ] );
');

        $inputs = [];
        foreach ($result->literalPathInputs as $input) {
            $inputs[$input->targetNode] = $input->literal;
        }
        self::assertSame('general/buttons', $inputs['load_options#param:0'] ?? null);
        self::assertSame('global-inline', $inputs['load_styles#param:0:key:name'] ?? null);

        $pathLinks = array_filter(
            $result->literalPathPropagationLinks,
            fn($link) => $link->prefix !== '' || $link->suffix !== '',
        );
        self::assertCount(2, $pathLinks);
        self::assertSame('/inc/options/', array_values($pathLinks)[0]->prefix);
        self::assertSame('.php', array_values($pathLinks)[0]->suffix);
        self::assertSame('/inc/styles/', array_values($pathLinks)[1]->prefix);
        self::assertSame('.php', array_values($pathLinks)[1]->suffix);
    }

    public function testLiteralPathPropagationResolvesBasenameWrappedParameterThroughAnUnresolvableConstantPrefix(): void
    {
        // Real-world shape (Akismet): `Akismet::view( $name ) { $file = AKISMET__PLUGIN_DIR .
        // 'views/' . basename( $name ) . '.php'; include $file; }`. Two tolerances at once:
        // basename() wrapping the parameter is transparent for matching purposes (FileAnalyzer's
        // own referenced-index already matches by basename anyway), and the unresolvable leading
        // `AKISMET__PLUGIN_DIR` constant is ignorable the same way a curated root-directory call
        // already is — a bare identifier in concatenation position is virtually always a
        // define()d bootstrap constant, not a genuinely unknowable value.
        $result = $this->parse('<?php
class Akismet {
    public static function view( $name ) {
        $file = AKISMET__PLUGIN_DIR . "views/" . basename( $name ) . ".php";
        include $file;
    }
}
Akismet::view( "activate" );
');
        // The prefix/suffix live on the assignment link (param -> local, where the
        // concatenation actually happens); the sink link itself (local -> null) carries none,
        // since `include $file;` has no concatenation of its own — the resolver accumulates
        // prefix/suffix while walking the graph, the same way testLiteralPathPropagation
        // CapturesPositionalAndKeyedWrapperInputs above checks it.
        $pathLinks = array_values(array_filter(
            $result->literalPathPropagationLinks,
            fn($link) => $link->prefix !== '' || $link->suffix !== '',
        ));
        self::assertCount(1, $pathLinks);
        self::assertSame('views/', $pathLinks[0]->prefix);
        self::assertSame('.php', $pathLinks[0]->suffix);
        self::assertTrue(array_any(
            $result->literalPathPropagationLinks,
            fn($link) => $link->isSink,
        ));

        $inputs = [];
        foreach ($result->literalPathInputs as $input) {
            $inputs[$input->targetNode] = $input->literal;
        }
        self::assertSame('activate', $inputs['Akismet::view#param:0'] ?? null);
    }

    public function testLiteralPathPropagationDoesNotTreatArbitraryTransformAsAPathTemplate(): void
    {
        // A wrapper that happens to pass its parameter through str_replace() into require is not
        // a fixed prefix + parameter + suffix path construction. Keeping its output unresolved
        // prevents ordinary transformations from suppressing unrelated unused-file findings.
        $result = $this->parse('<?php
function include_transformed( $name ) {
    $path = str_replace( "/", "-", $name );
    require $path;
}
include_transformed( "inc/orphan" );
');

        self::assertEmpty(array_filter(
            $result->literalPathPropagationLinks,
            fn($link) => $link->isSink,
        ));
    }

    public function testLiteralPathPropagationRejectsUnknownConcatenatedPathSegments(): void
    {
        // A fixed extension alone must not make `$base . $name . '.php'` a known project path:
        // the untracked base can point outside the project or at any sibling directory. Only the
        // explicitly recognized WordPress root-directory APIs may be omitted from a path shape.
        $result = $this->parse('<?php
function include_file( $name ) {
    $path = $base . $name . ".php";
    require $path;
}
include_file( "orphan" );
');

        self::assertEmpty(array_filter(
            $result->literalPathPropagationLinks,
            fn($link) => $link->prefix !== '' || $link->suffix !== '',
        ));
    }

    public function testLiteralPathPropagationCapturesStaticAndKnownInstanceMethodHops(): void
    {
        // Calls already resolved to their concrete receiver class must seed the same graph as
        // named calls. This covers late-static forwarding plus a type-hinted receiver and an
        // instance whose class was established by `new`, without granting that precision to an
        // arbitrary object variable.
        $result = $this->parse('<?php
class PathLoader {
    public static function load( $slug ) {}
    public static function through_static( $slug ) {
        static::load( $slug );
    }
}
function bootstrap( PathLoader $typed ) {
    PathLoader::through_static( "static" );
    $typed->load( "typed" );
    $created = new PathLoader();
    $created->load( "new" );
}
');

        $inputs = [];
        foreach ($result->literalPathInputs as $input) {
            $inputs[$input->targetNode][] = $input->literal;
        }
        self::assertSame(['static'], $inputs['PathLoader::through_static#param:0'] ?? null);
        self::assertSame(['typed', 'new'], $inputs['PathLoader::load#param:0'] ?? null);
        self::assertContains(
            'PathLoader::through_static#param:0',
            array_map(fn($link) => $link->fromNode, array_filter(
                $result->literalPathPropagationLinks,
                fn($link) => $link->toNode === 'PathLoader::load#param:0',
            )),
        );
    }

    public function testCronScheduleFuncPassThroughParamIsRecordedAgainstDeclaringFunction(): void
    {
        // Real-world shape (WooCommerce): `schedule_variation_summary_regeneration( $action_name,
        // ... ) { as_schedule_single_action( $timestamp, $action_name, $args, $group ); }` — the
        // hook name passes through unchanged, no concatenation, no transform. as_schedule_single_
        // action's own hook-tag argument is position 1 (see CRON_SCHEDULE_FUNCS).
        $result = $this->parse('<?php
class WC_Post_Data {
    private static function schedule_variation_summary_regeneration( $action_name, $timestamp, $args, $group ) {
        as_schedule_single_action( $timestamp, $action_name, $args, $group );
    }
}
');
        self::assertSame(
            0,
            $result->hookPassThroughParams['WC_Post_Data::schedule_variation_summary_regeneration'] ?? null,
        );
    }

    public function testClosureHookPassThroughParamResolvesAtItsOwnCallSite(): void
    {
        // Real-world shape (WooCommerce, wc-product-functions.php:575-611): a local closure
        // assigned to $schedule takes the hook name as its own parameter and passes it through
        // unchanged to as_schedule_single_action() — entirely local, resolved immediately at
        // $schedule(...)'s own call site rather than through cross-file merging.
        $result = $this->parse('<?php
function wc_schedule_product_sale_events( $product ) {
    $schedule = function ( $date, $hook ) {
        as_schedule_single_action( time(), $hook, array(), "woocommerce-sales" );
    };
    $schedule( $product->get_date_on_sale_from( "edit" ), "wc_product_start_scheduled_sale" );
    $schedule( $product->get_date_on_sale_to( "edit" ), "wc_product_end_scheduled_sale" );
}
');
        $tags = array_column($result->hookInvocations, 'tag');
        self::assertContains('wc_product_start_scheduled_sale', $tags);
        self::assertContains('wc_product_end_scheduled_sale', $tags);
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

    public function testPlainThreeElementStringArrayIsNotMisparsedAsACallback(): void
    {
        // Real-world regression (Sydney theme): apply_filters('tag', array('One', 'Two',
        // 'Three', ...)) — an ordinary list of class names (dynamically instantiated elsewhere
        // via `new $group()`), not a callback at all. Before this fix, the array-callback
        // detector only ever looked *backward* from a candidate "method name" string (a comma,
        // then a plausible receiver, then the array's own opening bracket) — every one of those
        // checks passes just as well for 'Two' here as it would for the real
        // array($this, 'method') shape, since it never checked whether 'Two' was also the
        // array's *last* element. The result: 'Two' was consumed into a fabricated
        // ScopedMethodCall('One', 'Two') and silently vanished from both $functionCalls and
        // $classReferences — invisible to every analyzer, network-wide, for any project with a
        // plain 3+-string-literal array anywhere.
        $result = $this->parse('<?php
$groups = apply_filters("x", array(
    "One",
    "Two",
    "Three",
));
');
        self::assertEmpty($result->scopedMethodCalls);
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('One', $names);
        self::assertContains('Two', $names);
        self::assertContains('Three', $names);
    }

    public function testFourElementArrayWhereFirstTwoLookLikeACallbackIsNotMisparsed(): void
    {
        // Same bug, general shape confirmed beyond exactly 3 elements: $this followed by a
        // string that looks exactly like a real array-callback method name, but a 3rd/4th
        // element after it proves this was never a 2-element callback pair at all.
        $result = $this->parse('<?php
$x = array($this, "not_really_a_method", "Three", "Four");
');
        self::assertEmpty($result->scopedMethodCalls);
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('not_really_a_method', $names);
        self::assertContains('Three', $names);
        self::assertContains('Four', $names);
    }

    public function testPlainTwoElementStringArrayAssignedToAVariableIsNotMisparsedAsACallback(): void
    {
        // Real-world regression (WooCommerce): `$controllers = array(
        // 'WC_REST_Product_Brands_V2_Controller', 'WC_REST_Product_Brands_Controller' );`
        // followed by `foreach ($controllers as $controller) { new $controller(); }` — a plain
        // 2-item list of class names, not a callback at all. Unlike the 3+-element regression
        // above, "last element" alone can't rule this shape out — a genuine ['Foo', 'method']
        // callback pair is also exactly 2 elements. Before this fix, the 2nd literal was
        // fabricated into ScopedMethodCall(1st, 2nd) and silently vanished from
        // $functionCalls/$classReferences, so `new $controller()` never found either literal
        // and both classes looked unused.
        $result = $this->parse('<?php
$controllers = array(
    "WC_REST_Product_Brands_V2_Controller",
    "WC_REST_Product_Brands_Controller",
);
');
        self::assertEmpty($result->scopedMethodCalls);
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('WC_REST_Product_Brands_V2_Controller', $names);
        self::assertContains('WC_REST_Product_Brands_Controller', $names);
    }

    public function testPlainTwoElementStringArrayShortSyntaxAssignedToAVariableIsNotMisparsedAsACallback(): void
    {
        // Same regression, short array syntax (`[...]` instead of `array(...)`).
        $result = $this->parse('<?php
$controllers = ["Foo_Controller", "Bar_Controller"];
');
        self::assertEmpty($result->scopedMethodCalls);
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('Foo_Controller', $names);
        self::assertContains('Bar_Controller', $names);
    }

    public function testBareTwoElementCallbackArrayAsCallArgumentIsStillRecognizedAsScoped(): void
    {
        // The genuine shape this whole branch exists for (Akismet): a bare-string callback
        // pair, but nested directly in a call's own argument list rather than assigned to a
        // variable first — must still resolve to a scoped call, not fall back to the generic
        // $functionCalls pool.
        $result = $this->parse('<?php
add_action("init", array("Akismet", "init"));
');
        self::assertCount(1, $result->scopedMethodCalls);
        self::assertSame('Akismet', $result->scopedMethodCalls[0]->receiverClass);
        self::assertSame('init', $result->scopedMethodCalls[0]->method);
    }

    public function testArrayCallbackWithTrailingCommaIsStillRecognizedAsScoped(): void
    {
        // A genuine 2-element callback with a trailing comma (`[$this, 'method',]`) must still
        // be recognized — the new "is this the array's last element" check has to tolerate one
        // optional trailing comma, not just a bare closing bracket immediately after.
        $result = $this->parse('<?php
add_action("init", [$this, "my_method",]);
');
        $names = array_column($result->functionCalls, 'name');
        self::assertContains('my_method', $names);
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

    // ── declared return-type resolution ─────────────────────────────────────────────────────

    public function testDeclaredReturnTypeIsResolvedOnFunctionDef(): void
    {
        $result = $this->parse('<?php
class My_Service {}
function create_service(): My_Service {
    return new My_Service();
}
class My_Factory {
    public static function make(): My_Service {
        return new My_Service();
    }
    private function makeNullable(): ?My_Service {
        return null;
    }
}
');
        $defsByName = [];
        foreach ($result->functionDefs as $def) {
            $defsByName[$def->ownerClass ?? ''][$def->name] = $def;
        }
        self::assertSame('My_Service', $defsByName['']['create_service']->returnType);
        self::assertSame('My_Service', $defsByName['My_Factory']['make']->returnType);
        // A nullable return type still resolves to the underlying class — a ?My_Service return
        // is still confidently My_Service whenever it isn't null.
        self::assertSame('My_Service', $defsByName['My_Factory']['makeNullable']->returnType);
        self::assertContains('My_Service', $result->classReferences);
    }

    public function testUnionOrIntersectionReturnTypeIsNotResolved(): void
    {
        // Ambiguous — an int|My_Service (or My_Service&Countable) return could be either at
        // runtime, so must not be trusted as a confident single class the way a plain My_Service
        // return already is.
        $result = $this->parse('<?php
class My_Service {}
interface Countable2 {}
function make_union(): int|My_Service {
    return new My_Service();
}
function make_intersection(): My_Service&Countable2 {
    return new My_Service();
}
');
        foreach ($result->functionDefs as $def) {
            self::assertNull($def->returnType, "{$def->name} should not resolve a union/intersection return type");
        }
    }

    public function testSelfAndStaticReturnTypesResolveAgainstTheOwnerClass(): void
    {
        $result = $this->parse('<?php
class My_Builder {
    public function withThing(): self {
        return $this;
    }
    public function withOtherThing(): static {
        return $this;
    }
}
');
        $defsByName = [];
        foreach ($result->functionDefs as $def) {
            $defsByName[$def->name] = $def;
        }
        self::assertSame('My_Builder', $defsByName['withThing']->returnType);
        self::assertSame('My_Builder', $defsByName['withOtherThing']->returnType);
    }

    public function testVoidAndPrimitiveReturnTypesAreNotResolved(): void
    {
        $result = $this->parse('<?php
function doA(): void {}
function doB(): int {}
function doC(): ?string { return null; }
function doD(): array { return []; }
');
        foreach ($result->functionDefs as $def) {
            self::assertNull($def->returnType);
        }
    }

    public function testAssignmentFromReturnTypedCallIsTrackedAsAPendingCall(): void
    {
        // $x = My_Factory::make(); $x->render(); — unresolved at parse time (make()'s own
        // return-type declaration might be in a different file's parse), so recorded as a
        // PendingReturnTypedCall rather than a ScopedMethodCall directly; see ClassAnalyzerTest
        // for the resolved-end-to-end behavior.
        $result = $this->parse('<?php
class My_Factory {
    public static function make() {}
}
function boot() {
    $x = My_Factory::make();
    $x->render();
}
');
        // My_Factory::make() itself is still credited as an ordinary scoped call (the natural
        // per-token scan reaches those tokens regardless of the pending-call tracking above them)
        // — only the *subsequent* $x->render() read is unresolved and deferred.
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertSame(['My_Factory::make'], $calls);
        self::assertCount(1, $result->pendingReturnTypedCalls);
        self::assertSame('My_Factory', $result->pendingReturnTypedCalls[0]->sourceReceiverClass);
        self::assertSame('make', $result->pendingReturnTypedCalls[0]->sourceMethod);
        self::assertSame('render', $result->pendingReturnTypedCalls[0]->readMethod);
    }

    public function testAssignmentFromBareFunctionCallIsTrackedWithANullReceiver(): void
    {
        $result = $this->parse('<?php
function boot() {
    $x = create_service();
    $x->render();
}
');
        self::assertCount(1, $result->pendingReturnTypedCalls);
        self::assertNull($result->pendingReturnTypedCalls[0]->sourceReceiverClass);
        self::assertSame('create_service', $result->pendingReturnTypedCalls[0]->sourceMethod);
        self::assertSame('render', $result->pendingReturnTypedCalls[0]->readMethod);
    }

    public function testChainedCallAsRhsIsNotTrackedAsAPendingCall(): void
    {
        // $x = Foo::make()->build(); — more than one call in the RHS; this parser only trusts
        // the simplest single-call shape, same "bail rather than guess" stance as everywhere else.
        $result = $this->parse('<?php
function boot() {
    $x = My_Factory::make()->build();
    $x->render();
}
');
        self::assertEmpty($result->pendingReturnTypedCalls);
    }

    // ── Namespace-aware FQCN resolution ─────────────────────────────────────

    public function testNamespacedClassResolvesFqcn(): void
    {
        $result = $this->parse('<?php
namespace My\App;
class Foo {}
');
        self::assertSame('Foo', $result->classDefs[0]->name);
        self::assertSame('My\App\Foo', $result->classDefs[0]->fqcn);
    }

    public function testNamespacedClassWithUseImportResolvesExtendsFqcn(): void
    {
        $result = $this->parse('<?php
namespace My\App;
use Vendor\Base;
class Foo extends Base {}
');
        $extends = $result->classDefs[0]->extends[0];
        self::assertSame('Base', $extends->short);
        self::assertSame('Vendor\Base', $extends->fqcn);
    }

    public function testNamespacedClassWithoutUseImportResolvesExtendsToCurrentNamespace(): void
    {
        // No `use` at all -- an unqualified reference resolves relative to the current
        // namespace, per PHP's own class-name-resolution rules (never a silent fallback to the
        // global namespace the way an unqualified function call gets at runtime).
        $result = $this->parse('<?php
namespace My\App;
class Foo extends Bar {}
');
        $extends = $result->classDefs[0]->extends[0];
        self::assertSame('Bar', $extends->short);
        self::assertSame('My\App\Bar', $extends->fqcn);
    }

    public function testLeadingBackslashFullyQualifiedExtendsResolvesVerbatim(): void
    {
        $result = $this->parse('<?php
namespace My\App;
class Foo extends \Vendor\Base {}
');
        $extends = $result->classDefs[0]->extends[0];
        self::assertSame('Base', $extends->short);
        self::assertSame('Vendor\Base', $extends->fqcn);
    }

    public function testUnnamespacedClassFqcnEqualsShortName(): void
    {
        // The common case (most real WP themes/plugins don't use namespaces at all) -- zero
        // behavior change from before namespace-awareness existed.
        $result = $this->parse('<?php
class Foo extends Bar {}
');
        self::assertSame('Foo', $result->classDefs[0]->fqcn);
        $extends = $result->classDefs[0]->extends[0];
        self::assertSame('Bar', $extends->fqcn);
    }

    public function testQualifiedExtendsNameWithUseImportOnFirstSegment(): void
    {
        // `use Vendor\Sub;` imports "Sub" as an alias for "Vendor\Sub" -- a later qualified
        // reference "Sub\Deep" substitutes that import for its own first segment only.
        $result = $this->parse('<?php
use Vendor\Sub;
class Foo extends Sub\Deep {}
');
        $extends = $result->classDefs[0]->extends[0];
        self::assertSame('Vendor\Sub\Deep', $extends->fqcn);
    }

    public function testInlineNewChainedCallIsRecordedAsScopedMethodCall(): void
    {
        // ( new Export() )->register_route(...) -- the real-world Elementor shape this feature
        // was built from.
        $result = $this->parse('<?php
namespace My\App;
class Export {
    public function register_route() {}
}
( new Export() )->register_route();
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My\App\Export::register_route', $calls);
    }

    public function testInlineNewChainedCallWithConstructorArgs(): void
    {
        $result = $this->parse('<?php
namespace My\App;
class Export {
    public function __construct($a, $b) {}
    public function register_route() {}
}
( new Export("a", "b") )->register_route();
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My\App\Export::register_route', $calls);
    }

    public function testInlineNewSelfChainedCallResolvesToOwnerClass(): void
    {
        $result = $this->parse('<?php
namespace My\App;
class Factory {
    public function make() {
        return ( new self() )->build();
    }
    public function build() {}
}
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertContains('My\App\Factory::build', $calls);
    }

    public function testBareNewAssignedToVariableIsUnaffectedByInlineChainFeature(): void
    {
        // $x = new Export(); $x->register_route(); -- the pre-existing two-statement pattern,
        // confirming the new inline-chain code path doesn't double-emit or otherwise interfere.
        $result = $this->parse('<?php
namespace My\App;
class Export {
    public function register_route() {}
}
$x = new Export();
$x->register_route();
');
        $calls = array_map(fn($c) => $c->receiverClass . '::' . $c->method, $result->scopedMethodCalls);
        self::assertCount(1, array_filter($calls, fn($c) => $c === 'My\App\Export::register_route'));
    }

    // ── function_exists() guard detection ───────────────────────────────────

    public function testFunctionDeclaredInsideItsOwnFunctionExistsGuardIsMarkedGuarded(): void
    {
        $result = $this->parse('<?php
if ( ! function_exists( "my_helper" ) ) {
    function my_helper() {}
}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertTrue($defs['my_helper']->guarded);
    }

    public function testUnguardedFunctionIsNotMarkedGuarded(): void
    {
        $result = $this->parse('<?php
function my_helper() {}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertFalse($defs['my_helper']->guarded);
    }

    public function testFunctionExistsGuardOnlyMarksTheMatchingNamedFunction(): void
    {
        // Two functions declared inside the same guard block -- only the one whose name
        // actually matches the guarded string is marked.
        $result = $this->parse('<?php
if ( ! function_exists( "my_helper" ) ) {
    function my_helper() {}
    function unrelated_helper() {}
}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertTrue($defs['my_helper']->guarded);
        self::assertFalse($defs['unrelated_helper']->guarded);
    }

    public function testFunctionExistsGuardWithoutNegationIsNotRecognized(): void
    {
        // `if ( function_exists('x') ) { function x() {} }` (no leading !) is a different,
        // logically backwards shape real code doesn't actually use this way -- deliberately not
        // matched, "don't guess" stance.
        $result = $this->parse('<?php
if ( function_exists( "my_helper" ) ) {
    function my_helper() {}
}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertFalse($defs['my_helper']->guarded);
    }

    public function testFunctionExistsGuardCombinedWithOtherConditionIsNotRecognized(): void
    {
        $result = $this->parse('<?php
if ( ! function_exists( "my_helper" ) && true ) {
    function my_helper() {}
}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertFalse($defs['my_helper']->guarded);
    }

    // ── FunctionDef/FunctionCall FQCN resolution ─────────────────────────────

    public function testNamespacedFunctionDefResolvesFqcn(): void
    {
        $result = $this->parse('<?php
namespace My\App;
function helper() {}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertSame('My\App\helper', $defs['helper']->fqcn);
    }

    public function testUnnamespacedFunctionDefFqcnEqualsName(): void
    {
        $result = $this->parse('<?php
function helper() {}
');
        $defs = array_column($result->functionDefs, null, 'name');
        self::assertSame('helper', $defs['helper']->fqcn);
    }

    public function testBareCallInsideNamespaceRecordsTheLocalCandidateFqcn(): void
    {
        $result = $this->parse('<?php
namespace My\App;
helper();
');
        self::assertSame('My\App\helper', $result->functionCalls[0]->extraCandidateFqcn);
    }

    public function testBareCallOutsideAnyNamespaceHasNoExtraCandidate(): void
    {
        $result = $this->parse('<?php
helper();
');
        self::assertNull($result->functionCalls[0]->extraCandidateFqcn);
    }

    public function testFullyQualifiedCallRecordsItsDeterministicCandidateFqcn(): void
    {
        $result = $this->parse('<?php
namespace My\App;
\Vendor\Pkg\helper();
');
        self::assertSame('Vendor\Pkg\helper', $result->functionCalls[0]->extraCandidateFqcn);
    }

    public function testUseFunctionImportedBareCallRecordsTheImportedCandidateFqcn(): void
    {
        // Real-world finding (Jetpack): `use function Automattic\Jetpack\Extensions\Map\
        // map_block_from_geo_points;` then a bare call to it elsewhere in the same file —
        // PHP fixes a `use function`-imported name to its target at compile time, shadowing the
        // usual current-namespace-then-global runtime fallback entirely. Deliberately declared
        // inside a *different* namespace than the import target, so a plain
        // currentNamespace-prefixed guess (the existing ambiguous-bare-call candidate) would
        // never have matched this on its own.
        $result = $this->parse('<?php
namespace My\App;
use function Automattic\Jetpack\Extensions\Map\map_block_from_geo_points;
map_block_from_geo_points();
');
        self::assertSame(
            'Automattic\Jetpack\Extensions\Map\map_block_from_geo_points',
            $result->functionCalls[0]->extraCandidateFqcn,
        );
    }

    public function testUseFunctionImportWithAliasRecordsTheImportedCandidateUnderTheAlias(): void
    {
        $result = $this->parse('<?php
use function My\Ns\helper as aliased_helper;
aliased_helper();
');
        self::assertSame('My\Ns\helper', $result->functionCalls[0]->extraCandidateFqcn);
    }

    public function testUseFunctionImportTakesPriorityOverNamespaceFallbackCandidate(): void
    {
        // If the import and the current namespace disagree on where the call resolves, the
        // import wins deterministically — PHP never falls back to the current namespace once a
        // `use function` import shadows the bare name.
        $result = $this->parse('<?php
namespace My\App;
use function Vendor\Pkg\helper;
helper();
');
        self::assertSame('Vendor\Pkg\helper', $result->functionCalls[0]->extraCandidateFqcn);
    }

    public function testParseAllParsesEveryFileInOrderAndReportsProgress(): void
    {
        $fileA = $this->tmp . '/a.php';
        $fileB = $this->tmp . '/b.php';
        $fileC = $this->tmp . '/c.php';
        file_put_contents($fileA, '<?php function a_func() {}');
        file_put_contents($fileB, '<?php function b_func() {}');
        file_put_contents($fileC, '<?php function c_func() {}');

        $progressCalls = [];
        $results = $this->parser->parseAll(
            [$fileA, $fileB, $fileC],
            function (int $current, int $total) use (&$progressCalls) {
                $progressCalls[] = [$current, $total];
            },
        );

        self::assertSame(['a_func', 'b_func', 'c_func'], array_map(
            fn($r) => $r->functionDefs[0]->name,
            $results,
        ));
        self::assertSame([[1, 3], [2, 3], [3, 3]], $progressCalls);
    }

    public function testParseAllNeverCallsProgressForAnEmptyFileList(): void
    {
        $called = false;
        $results = $this->parser->parseAll([], function () use (&$called) {
            $called = true;
        });

        self::assertSame([], $results);
        self::assertFalse($called);
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
