<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\ClassAnalyzer;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\PhpTokenParser;

final class ClassAnalyzerTest extends TestCase
{
    private string $tmp;
    private ClassAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-ca-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->analyzer = new ClassAnalyzer(new PhpTokenParser());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testReportsUnusedClass(): void
    {
        $file = $this->write('<?php class My_Unused_Class {}');

        $findings = $this->analyzer->analyze([$file]);

        self::assertCount(1, $findings);
        self::assertSame(FindingType::UnusedClass, $findings[0]->type);
        self::assertSame('My_Unused_Class', $findings[0]->name);
        self::assertSame(FindingCertainty::Error, $findings[0]->certainty);
    }

    public function testDoesNotReportClassPassedAsStringToCustomizerRegisterType(): void
    {
        // Real-world finding (Astra theme): WP_Customize_Manager::register_panel_type()/
        // register_section_type()/register_control_type() take a class name as a plain string —
        // WP core does `new $that_string(...)` internally, never a `new`/`instanceof`/`extends`/
        // `implements`/`::` reference this parser tracks directly.
        $file = $this->write('<?php
class Astra_WP_Customize_Panel extends WP_Customize_Panel {}
function register_custom_types( $wp_customize ) {
    $wp_customize->register_panel_type( "Astra_WP_Customize_Panel" );
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testDoesNotReportClassReturnedAsStringFromFilterCallback(): void
    {
        // Real-world finding (Blocksy theme): the 'block_parser_class'/
        // 'customize_dynamic_partial_class' WP core filters expect the callback to *return* a
        // class name string, which WP then instantiates itself — same "string, not a visible
        // new/instanceof/::" shape as the Customizer register_*_type() case above, just via a
        // filter's return value instead of a direct method argument.
        $file = $this->write('<?php
class Blocksy_WP_Block_Parser {}
add_filter( "block_parser_class", function () {
    return "Blocksy_WP_Block_Parser";
} );
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testReportsClassWrappedInOwnClassExistsGuard(): void
    {
        // Regression found while verifying the fallback above: `if ( ! class_exists( 'X' ) ) {
        // class X {} }` is an extremely common WP redeclaration guard, self-referencing the very
        // thing it's about to define — not a real usage signal, the opposite in fact (it exists
        // specifically to avoid a fatal redeclaration error). Real-world finding (GeneratePress
        // theme): all 8 of its deprecated Customizer control classes use exactly this guard and
        // must still be flagged, or the class-name-via-string fallback above would permanently
        // mask genuinely dead deprecated code.
        $file = $this->write('<?php
if ( ! class_exists( "My_Deprecated_Control" ) ) {
    class My_Deprecated_Control extends WP_Customize_Control {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedClasses = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass), 'name');
        self::assertContains('My_Deprecated_Control', $unusedClasses);
    }

    public function testDoesNotReportClassPassedAsBareStringToRegisterWidget(): void
    {
        // register_widget('My_Widget') — the class name string, same as any other bare string
        // literal, flows into the generic $functionCalls pool (see PhpTokenParser's blanket
        // T_CONSTANT_ENCAPSED_STRING handling) and is trusted as a class reference by the same
        // fallback that covers register_panel_type()/the filter-return shape above — this isn't
        // register_widget-specific, it works for any function taking a bare class-name string.
        $file = $this->write('<?php
class My_Widget extends WP_Widget {
    public function widget($args, $instance) {}
}
function register_my_widgets() {
    register_widget("My_Widget");
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testDoesNotReportClassPassedAsBareStringToIsAOrIsSubclassOf(): void
    {
        $file = $this->write('<?php
class My_Base {}
function check($x) {
    return is_a($x, "My_Base") || is_subclass_of($x, "My_Base");
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testDoesNotReportClassNamePassedAsAPlainConfigArrayValue(): void
    {
        // A class name string used as an ordinary associative-array value (not the special
        // [Foo::class, 'method'] callback shape) — e.g. a customizer/control config array —
        // still flows through the same generic string-literal handling.
        $file = $this->write('<?php
class My_Custom_Control {}
$config = array(
    "type"  => "control",
    "class" => "My_Custom_Control",
);
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testDoesNotReportClassInstantiatedWithNew(): void
    {
        $file = $this->write('<?php
class My_Class {}
$x = new My_Class();
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testDoesNotReportClassUsedWithInstanceof(): void
    {
        $file = $this->write('<?php
class My_Class {}
if ($x instanceof My_Class) {}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testDoesNotReportClassUsedViaStaticAccess(): void
    {
        $file = $this->write('<?php
class My_Class {
    public static function make() {}
}
My_Class::make();
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testDoesNotReportBaseClassExtendedElsewhere(): void
    {
        $file = $this->write('<?php
class Base_Class {}
class Child_Class extends Base_Class {}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedClasses = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass), 'name');
        self::assertContains('Child_Class', $unusedClasses);
        self::assertNotContains('Base_Class', $unusedClasses);
    }

    public function testReportsUnusedInterface(): void
    {
        $file = $this->write('<?php interface My_Unused_Interface {}');

        $findings = $this->analyzer->analyze([$file]);

        $unusedClasses = array_values(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
        self::assertCount(1, $unusedClasses);
        self::assertSame('My_Unused_Interface', $unusedClasses[0]->name);
        self::assertSame('unused interface', $unusedClasses[0]->note);
    }

    public function testReportsUnusedTrait(): void
    {
        $file = $this->write('<?php trait My_Unused_Trait {}');

        $findings = $this->analyzer->analyze([$file]);

        $unusedClasses = array_values(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
        self::assertCount(1, $unusedClasses);
        self::assertSame('My_Unused_Trait', $unusedClasses[0]->name);
        self::assertSame('unused trait', $unusedClasses[0]->note);
    }

    public function testReportsUnusedEnum(): void
    {
        $file = $this->write('<?php enum My_Unused_Enum {}');

        $findings = $this->analyzer->analyze([$file]);

        $unusedClasses = array_values(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
        self::assertCount(1, $unusedClasses);
        self::assertSame('My_Unused_Enum', $unusedClasses[0]->name);
        self::assertSame('unused enum', $unusedClasses[0]->note);
    }

    public function testDoesNotReportTraitUsedViaUseStatement(): void
    {
        $file = $this->write('<?php
trait My_Trait {}
class My_Class {
    use My_Trait;
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedClasses = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass), 'name');
        self::assertNotContains('My_Trait', $unusedClasses);
    }

    public function testDoesNotReportTraitMethodCalledViaThisFromConsumingClass(): void
    {
        // greet() is never called on the trait itself — only via $this->greet() inside the
        // class that use()s the trait. Its own FunctionDef has ownerClass = the trait's name,
        // so this only passes via the trait-consumer widening in isUsedByTraitConsumer().
        $file = $this->write('<?php
trait Greetable {
    public function greet() {
        return "hi";
    }
}
class Person {
    use Greetable;

    public function hello() {
        return $this->greet();
    }
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('greet', $unusedMethods);
    }

    public function testReportsTraitMethodNeverCalledByAnyConsumer(): void
    {
        $file = $this->write('<?php
trait Greetable {
    public function greet() {
        return "hi";
    }
    public function never_called() {
        return "dead";
    }
}
class Person {
    use Greetable;

    public function hello() {
        return $this->greet();
    }
}
$p = new Person();
$p->hello();
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('never_called', $unusedMethods);
        self::assertNotContains('greet', $unusedMethods);
    }

    public function testDoesNotReportTraitMethodUsedThroughTransitiveTraitUse(): void
    {
        // Consumer uses Mid, which itself uses Base — base_method() must be recognized as used
        // even though Consumer never `use`s Base directly.
        $file = $this->write('<?php
trait Base {
    public function base_method() {
        return "base";
    }
}
trait Mid {
    use Base;
}
class Consumer {
    use Mid;

    public function run() {
        return $this->base_method();
    }
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('base_method', $unusedMethods);
    }

    public function testTraitMethodScopingDoesNotLeakToUnrelatedClass(): void
    {
        // Other_Class doesn't use Greetable at all — greet() being called on it must not count
        // as evidence that the trait method is used.
        $file = $this->write('<?php
trait Greetable {
    public function greet() {
        return "hi";
    }
}
class Person {
    use Greetable;
}
class Other_Class {
    public function greet() {
        return "unrelated";
    }
}
$o = new Other_Class();
$o->greet();
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('greet', $unusedMethods);
    }

    public function testDoesNotReportInterfaceImplementedElsewhere(): void
    {
        $file = $this->write('<?php
interface My_Interface {}
class My_Impl implements My_Interface {}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedClasses = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass), 'name');
        self::assertNotContains('My_Interface', $unusedClasses);
    }

    public function testClassReferencedInAnotherFile(): void
    {
        $file1 = $this->write('<?php class Shared_Class {}');
        $file2 = $this->write('<?php $x = new Shared_Class();');

        $findings = $this->analyzer->analyze([$file1, $file2]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testReportsUnusedMethod(): void
    {
        $file = $this->write('<?php
class My_Class {
    public function unused_method() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $methods = array_values(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
        self::assertCount(1, $methods);
        self::assertSame('unused_method', $methods[0]->name);
        self::assertSame(FindingCertainty::Warning, $methods[0]->certainty);
    }

    public function testUnusedMethodOnAnAlreadyUnusedClassIsSuppressedByDefault(): void
    {
        // The class finding already says nothing on it is reachable — reporting its methods too
        // is redundant. $suppressUnusedClassMethods defaults to true (see --no-suppress-unused-
        // class-methods).
        $file = $this->write('<?php
class My_Dead_Class {
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertCount(1, $findings);
        self::assertSame(FindingType::UnusedClass, $findings[0]->type);
    }

    public function testUnusedMethodOnAnAlreadyUnusedClassIsReportedWhenSuppressionIsDisabled(): void
    {
        $file = $this->write('<?php
class My_Dead_Class {
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testDoesNotReportMethodCalledViaObjectOperator(): void
    {
        $file = $this->write('<?php
class My_Class {
    public function used_method() {}
}
$obj = new My_Class();
$obj->used_method();
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testDoesNotReportMethodCalledViaArrayCallback(): void
    {
        $file = $this->write('<?php
class My_Class {
    public function callback_method() {}
}
add_action("init", [$this, "callback_method"]);
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testDoesNotReportMethodCalledViaClassConstArrayCallback(): void
    {
        $file = $this->write('<?php
class My_Class {
    public static function static_callback_method() {}
}
add_action("init", [My_Class::class, "static_callback_method"]);
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testMagicClassConcatenatedStringCallbackCountsAsCall(): void
    {
        // Real-world regression (GeneratePress theme): `__CLASS__ . '::admin_updates'` builds a
        // "ClassName::method" callable string via concatenation, same shape as the already-fixed
        // `__NAMESPACE__ . '\name'` case but with "::" instead of "\" as the separator. The
        // literal token alone is '::admin_updates' — its leading "::" was making the whole
        // literal fail looksLikeCallback()'s identifier regex, so the method looked unused
        // despite being registered as an add_action callback right there.
        $file = $this->write('<?php
class My_Theme_Update {
    public static function admin_updates() {}
    public function truly_unused(): void {}
    public function register() {
        add_action( "admin_init", __CLASS__ . "::admin_updates", 1 );
    }
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('admin_updates', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    // ── class-scoped call matching ──────────────────────────────────────────

    public function testClassScopedMatchingDoesNotLeakBetweenUnrelatedClasses(): void
    {
        // Both classes declare a "render" method. Used_Service's is genuinely called (via
        // $this->), Dead_Service's never is — before class-scoped matching, the bare-name
        // "render" call would have suppressed both findings; now only the real one is used.
        $file = $this->write('<?php
class Used_Service {
    public function boot() { $this->render(); }
    public function render() {}
}
class Dead_Service {
    public function boot() {}
    public function render() {}
}
$s = new Used_Service();
$s->boot();
$d = new Dead_Service();
$d->boot();
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('render', $unusedMethods);
        self::assertCount(1, $unusedMethods, 'Only Dead_Service::render should be flagged, not Used_Service::render');
    }

    public function testLocalVariableScopingDoesNotLeakBetweenUnrelatedClasses(): void
    {
        // Both classes have a "render" method. Used_Service's is called through a local
        // variable known to hold that exact class ($s = new Used_Service()); Dead_Service's
        // never is — before local-variable type tracking, the bare-name "render" call would
        // have suppressed both findings.
        $file = $this->write('<?php
class Used_Service {
    public function render() {}
}
class Dead_Service {
    public function render() {}
}
function boot() {
    $s = new Used_Service();
    $s->render();
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('render', $unusedMethods);
        self::assertCount(1, $unusedMethods, 'Only Dead_Service::render should be flagged, not Used_Service::render');
    }

    public function testDoesNotReportMethodCalledViaSelf(): void
    {
        // "boot" itself is never called anywhere in this fixture, so it's legitimately
        // reported too — only "helper" (called via self::) is under test here.
        $file = $this->write('<?php
class My_Class {
    public function boot() { self::helper(); }
    public static function helper() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('helper', $unusedMethods);
    }

    public function testDoesNotReportMethodCalledViaStaticLateBinding(): void
    {
        $file = $this->write('<?php
class My_Class {
    public function boot() { static::helper(); }
    public static function helper() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('helper', $unusedMethods);
    }

    public function testDoesNotReportParentMethodCalledViaParentKeyword(): void
    {
        $file = $this->write('<?php
class Base_Class {
    public function base_helper() {}
}
class Child_Class extends Base_Class {
    public function boot() { parent::base_helper(); }
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('base_helper', $unusedMethods);
    }

    public function testDoesNotReportMethodCalledViaLiteralClassName(): void
    {
        $file = $this->write('<?php
class My_Class {
    public static function helper() {}
}
My_Class::helper();
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testArrayCallbackScopingDoesNotLeakBetweenUnrelatedClasses(): void
    {
        // Both classes have a same-named "on_init" hook callback method. Only Used_Hooks's is
        // actually wired up via [Used_Hooks::class, 'on_init'] — before array-callback scoping,
        // the bare-name "on_init" call would have suppressed both findings.
        $file = $this->write('<?php
class Used_Hooks {
    public function on_init() {}
}
class Dead_Hooks {
    public function on_init() {}
}
add_action("init", [Used_Hooks::class, "on_init"]);
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('on_init', $unusedMethods);
        self::assertCount(1, $unusedMethods, 'Only Dead_Hooks::on_init should be flagged, not Used_Hooks::on_init');
    }

    public function testDoesNotReportMethodCalledViaThisArrayCallback(): void
    {
        $file = $this->write('<?php
class My_Plugin {
    public function boot() {
        add_action("init", [$this, "on_init"]);
    }
    public function on_init() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('on_init', $unusedMethods);
    }

    public function testExcludesWpWidgetContractMethods(): void
    {
        // WP core calls widget()/form()/update() by reflection on any WP_Widget subclass —
        // never by a visible name reference anywhere in project code.
        $file = $this->write('<?php
class My_Widget extends WP_Widget {
    public function widget($args, $instance) {}
    public function form($instance) {}
    public function update($new_instance, $old_instance) { return $new_instance; }
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('widget', $unusedMethods);
        self::assertNotContains('form', $unusedMethods);
        self::assertNotContains('update', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testExcludesWalkerNavMenuContractMethodsThroughCoreSubclass(): void
    {
        // WP core dispatches start_lvl/end_lvl/start_el/end_el by calling them on the object
        // passed into wp_nav_menu()'s 'walker' arg — never by a visible name reference in
        // project code. The class extends Walker_Nav_Menu (a WP core class one level below
        // Walker itself, never present as a project ClassDef or reflectable vendor class), so
        // the exemption must be resolved from the curated list rather than reflection.
        $file = $this->write('<?php
class My_Nav_Walker extends Walker_Nav_Menu {
    public function start_lvl(&$output, $depth = 0, $args = null) {}
    public function end_lvl(&$output, $depth = 0, $args = null) {}
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {}
    public function end_el(&$output, $item, $depth = 0, $args = null) {}
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('start_lvl', $unusedMethods);
        self::assertNotContains('end_lvl', $unusedMethods);
        self::assertNotContains('start_el', $unusedMethods);
        self::assertNotContains('end_el', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testExcludesWpCustomizeControlContractMethods(): void
    {
        // Real-world finding from Twenty Twenty-One's own Customizer controls: render_content()/
        // enqueue()/to_json() are called by the Customizer's own rendering pipeline, never by a
        // visible name reference in theme code. Covers both extending WP_Customize_Control
        // directly and through a WP core subclass one level below it (WP_Customize_Color_Control,
        // never a project ClassDef or reflectable vendor class), same two-level-chain situation
        // already solved for Walker_Nav_Menu.
        $file = $this->write('<?php
class My_Notice_Control extends WP_Customize_Control {
    public function render_content() {}
    public function truly_unused_notice() {}
}
class My_Color_Control extends WP_Customize_Color_Control {
    public function enqueue() {}
    public function to_json() {
        return [];
    }
    public function truly_unused_color() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('render_content', $unusedMethods);
        self::assertNotContains('enqueue', $unusedMethods);
        self::assertNotContains('to_json', $unusedMethods);
        self::assertContains('truly_unused_notice', $unusedMethods);
        self::assertContains('truly_unused_color', $unusedMethods);
    }

    public function testExcludesWpCustomizeSectionAndPanelContractMethods(): void
    {
        // Real-world findings: GeneratePress's own upsell section overrides render_template(),
        // Hello Elementor's overrides render() — both are WP_Customize_Section's own rendering
        // pipeline override points (a different WP core base class than WP_Customize_Control,
        // with an overlapping but not identical method set), called by the Customizer, never by
        // a visible name reference in theme code.
        $file = $this->write('<?php
class My_Upsell_Section extends WP_Customize_Section {
    public function render_template() {}
    public function truly_unused_section(): void {}
}
class My_Upsell_Panel extends WP_Customize_Panel {
    public function render() {}
    public function content_template() {}
    public function truly_unused_panel(): void {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('render_template', $unusedMethods);
        self::assertNotContains('render', $unusedMethods);
        self::assertNotContains('content_template', $unusedMethods);
        self::assertContains('truly_unused_section', $unusedMethods);
        self::assertContains('truly_unused_panel', $unusedMethods);
    }

    public function testExcludesWpUpgraderSkinContractMethodsThroughCoreSubclass(): void
    {
        // Real-world finding confirmed independently in both Blocksy and OceanWP: their own
        // plugin-upgrade skin classes extend Plugin_Installer_Skin (a WP core class one level
        // below WP_Upgrader_Skin, never a project ClassDef or reflectable vendor class — same
        // two-level-chain situation already solved for Walker_Nav_Menu) and override
        // feedback()/bulk_header()/bulk_footer()/decrement_update_count(), all called internally
        // by WP_Upgrader during an admin install/update action.
        $file = $this->write('<?php
class My_Upgrader_Skin extends Plugin_Installer_Skin {
    public function feedback( $string, ...$args ) {}
    public function decrement_update_count( $type ) {}
    public function bulk_header() {}
    public function bulk_footer() {}
    public function truly_unused_skin(): void {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('feedback', $unusedMethods);
        self::assertNotContains('decrement_update_count', $unusedMethods);
        self::assertNotContains('bulk_header', $unusedMethods);
        self::assertNotContains('bulk_footer', $unusedMethods);
        self::assertContains('truly_unused_skin', $unusedMethods);
    }

    public function testExcludesWalkerContractMethodsWhenBaseClassNameHasWrongCase(): void
    {
        // Real-world regression found in bootscore's own navwalker: `extends Walker_Nav_menu`
        // (lowercase "menu" — a typo in the theme's own code). PHP class-name resolution is
        // case-insensitive, so this legitimately extends WP core's Walker_Nav_Menu and runs fine
        // at runtime — but BASE_CLASS_CONTRACT_METHODS is keyed by the exact-case string
        // 'Walker_Nav_Menu', so an exact-case lookup against the parsed (mistyped) extends name
        // would silently miss the exemption and produce a false "unused method".
        $file = $this->write('<?php
class My_Nav_Walker extends Walker_Nav_menu {
    public function start_lvl(&$output, $depth = 0, $args = null) {}
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {}
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('start_lvl', $unusedMethods);
        self::assertNotContains('start_el', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testExcludesWalkerDisplayElementOverride(): void
    {
        // display_element() (plus walk/paged_walk/get_number_of_root_elements/unset_children) is
        // public on WP core's Walker and legally overridable, not just the four documented
        // start_lvl/end_lvl/start_el/end_el hooks. Real starter themes override display_element
        // to patch a WP core current-menu-item bug (e.g. Understrap's bootstrap navwalker), so it
        // must be exempt from the unused-method check the same way the four are.
        $file = $this->write('<?php
class My_Nav_Walker extends Walker_Nav_Menu {
    public function display_element($element, &$children_elements, $max_depth, $depth, $args, &$output) {}
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('display_element', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testExcludesWalkerContractMethodsThroughWpAdminCoreSubclasses(): void
    {
        // Walker_Nav_Menu_Edit, Walker_Nav_Menu_Checklist and Walker_Category_Checklist are
        // WP core classes too (wp-admin/includes/class-walker-*.php, used by the nav-menus.php
        // screen and the category checklist metabox) — same never-reflectable, never-a-ClassDef
        // situation as the wp-includes Walker subclasses, so they need their own curated entries.
        $file = $this->write('<?php
class My_Edit_Walker extends Walker_Nav_Menu_Edit {
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {}
    public function truly_unused_edit() {}
}
class My_Checklist_Walker extends Walker_Nav_Menu_Checklist {
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {}
    public function truly_unused_checklist() {}
}
class My_Category_Checklist_Walker extends Walker_Category_Checklist {
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {}
    public function truly_unused_category_checklist() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('start_el', $unusedMethods);
        self::assertContains('truly_unused_edit', $unusedMethods);
        self::assertContains('truly_unused_checklist', $unusedMethods);
        self::assertContains('truly_unused_category_checklist', $unusedMethods);
    }

    public function testExcludesJsonSerializableMethodOnBackedEnum(): void
    {
        // Regression: enum method bodies used to get ownerClass=null (a documented gap —
        // "enum-method scoping isn't attempted yet"), which made isContractMethod() short-circuit
        // before ever checking whether the enum satisfies JsonSerializable — so jsonSerialize()
        // was always flagged unused on any enum implementing it, despite the interface exemption
        // that already works for plain classes.
        $file = $this->write("<?php
enum Status: string implements JsonSerializable {
    case Active = 'active';
    case Inactive = 'inactive';

    public function jsonSerialize(): mixed {
        return \$this->value;
    }

    public function truly_unused(): void {}
}
");
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('jsonSerialize', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testDoesNotFlagInterfaceMethodCalledThroughInterfaceTypedParam(): void
    {
        // Regression: interface method declarations (no body) also got ownerClass=null, so they
        // were universally flagged "unused" — nothing could ever scope-match them, even a call
        // through a variable typed exactly as the interface itself.
        $file = $this->write('<?php
interface Shippable {
    public function calculate_shipping(): float;
}

function checkout(Shippable $method) {
    echo $method->calculate_shipping();
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('calculate_shipping', $unusedMethods);
    }

    public function testCreditsConcreteImplementerCalledThroughInterfaceTypedParam(): void
    {
        // A call resolved to a concrete receiver class only ever names the *static* type at the
        // call site — `function checkout(Shippable $method) { $method->calc(); }` records a call
        // against 'Shippable', never 'FlatRate', even though FlatRate is what actually runs.
        // Without isUsedByPolymorphicCall(), FlatRate::calculate_shipping() would falsely look
        // unused despite being reachable through the only call site that exists for it — the
        // interface's own declaration got fixed already (previous test), but the concrete
        // implementation is a separate FunctionDef that needs its own resolution.
        $file = $this->write('<?php
interface Shippable {
    public function calculate_shipping(): float;
}

class FlatRate implements Shippable {
    public function calculate_shipping(): float {
        return 5.0;
    }
    public function truly_unused(): void {}
}

function checkout(Shippable $method) {
    echo $method->calculate_shipping();
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('calculate_shipping', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testCreditsConcreteImplementerThroughAbstractClassTypedParam(): void
    {
        // Same polymorphic-dispatch gap, for an abstract base class instead of an interface.
        $file = $this->write('<?php
abstract class Base_Shipping {
    abstract public function calculate_shipping(): float;
}

class FlatRate extends Base_Shipping {
    public function calculate_shipping(): float {
        return 5.0;
    }
}

function checkout(Base_Shipping $method) {
    echo $method->calculate_shipping();
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('calculate_shipping', $unusedMethods);
    }

    public function testCreditsImplementerWhenInterfaceIsSatisfiedByAnIntermediateAncestor(): void
    {
        // The interface is `implements`-ed on an intermediate abstract class, not the concrete
        // leaf class itself — isUsedByPolymorphicCall() must walk FlatRate's full extends chain
        // (same structure as isContractMethod()) to find it on Base_Shipping, one level up.
        $file = $this->write('<?php
interface Shippable {
    public function calc(): float;
}
abstract class Base_Shipping implements Shippable {}
class FlatRate extends Base_Shipping {
    public function calc(): float { return 5.0; }
    public function truly_unused(): void {}
}
function checkout(Shippable $s) { echo $s->calc(); }
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('calc', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testCreditsSharedBaseClassMethodCalledThroughConcreteSubclassReceiver(): void
    {
        // Real-world finding (Astra theme): a shared, concrete (non-abstract) method declared
        // once on an abstract base class, called from every concrete subclass via its own
        // receiver — `Subclass::register()`, `$this->build_output_schema()` from inside the
        // subclass — never `Base_Ability::` directly. Every one of those calls resolves its
        // receiver to the subclass, so scopedCalled[ownerClass] (ownerClass being the base
        // class) never matches on its own; isUsedByDescendantReceiver() must widen the check to
        // any known descendant.
        $file = $this->write('<?php
abstract class Base_Ability {
    public static function register() {}
    public function build_output_schema() {}
    public function truly_unused() {}
}
class Concrete_Ability extends Base_Ability {
    public function run() {
        $this->build_output_schema();
    }
}
Concrete_Ability::register();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('register', $unusedMethods);
        self::assertNotContains('build_output_schema', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testDescendantReceiverCreditDoesNotLeakToUnrelatedBaseClass(): void
    {
        // Not_Related's chain never reaches Base_Ability at any depth — register() on
        // Not_Related must still be reported, even though a same-named method IS used elsewhere
        // through a genuinely related descendant.
        $file = $this->write('<?php
abstract class Base_Ability {
    public static function register() {}
}
class Concrete_Ability extends Base_Ability {}
Concrete_Ability::register();

class Not_Related {
    public static function register() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_values(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
        self::assertCount(1, $unusedMethods);
        self::assertSame('register', $unusedMethods[0]->name);
        self::assertSame(9, $unusedMethods[0]->line, 'Should flag Not_Related::register(), not Base_Ability::register()');
    }

    public function testExcludesInterfaceContractMethods(): void
    {
        $file = $this->write('<?php
class My_Collection implements Countable, Iterator {
    public function count(): int { return 0; }
    public function current(): mixed { return null; }
    public function key(): mixed { return null; }
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool { return false; }
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testContractMethodExemptionDoesNotLeakToUnrelatedClasses(): void
    {
        // A method literally named "widget" on a class that has nothing to do with WP_Widget
        // should still be reported — the exemption is scoped to the declaring class's own
        // extends/implements, not the method name in isolation.
        $file = $this->write('<?php
class Not_A_Widget {
    public function widget() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('widget', $unusedMethods);
    }

    public function testExcludesWpWidgetContractMethodsThroughMultiLevelInheritance(): void
    {
        // My_Widget doesn't extend WP_Widget directly — it extends My_Base_Widget, which
        // extends WP_Widget. The widget()/form()/update() exemption must still apply, since
        // WP core reaches these methods via reflection on the final concrete subclass,
        // regardless of how many intermediate base classes sit in between.
        $file = $this->write('<?php
class My_Base_Widget extends WP_Widget {}
class My_Widget extends My_Base_Widget {
    public function widget($args, $instance) {}
    public function form($instance) {}
    public function update($new_instance, $old_instance) { return $new_instance; }
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('widget', $unusedMethods);
        self::assertNotContains('form', $unusedMethods);
        self::assertNotContains('update', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testExcludesInterfaceContractMethodsAttachedHigherInChain(): void
    {
        // Countable is implemented by the base class, not re-declared on the subclass — the
        // exemption should still reach count() on Child.
        $file = $this->write('<?php
class Collection_Base implements Countable {
    public function count(): int { return 0; }
}
class Collection_Child extends Collection_Base {
    public function count(): int { return 1; }
}
');
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('count', $unusedMethods);
    }

    public function testContractMethodExemptionDoesNotLeakThroughUnrelatedChain(): void
    {
        // Not_A_Widget's chain never reaches WP_Widget at any depth — widget() must still be
        // reported as unused.
        $file = $this->write('<?php
class Not_A_Widget_Base {}
class Not_A_Widget extends Not_A_Widget_Base {
    public function widget() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('widget', $unusedMethods);
    }

    // ── return-type inference ───────────────────────────────────────────────────────────────

    public function testCreditsMethodCalledThroughAVariableTypedByAFactoryMethodsReturnType(): void
    {
        // $x = My_Factory::make(); $x->render(); — make()'s own declared `: My_Service` return
        // type resolves $x's type the same way `new My_Service()` already would, even though
        // make()'s declaration and this call site could be in entirely different files.
        $file = $this->write('<?php
class My_Service {
    public function render() {}
    public function truly_unused() {}
}
class My_Factory {
    public static function make(): My_Service {
        return new My_Service();
    }
}
class My_Controller {
    public function boot() {
        $x = My_Factory::make();
        $x->render();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('render', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testCreditsMethodCalledThroughATopLevelFunctionsReturnType(): void
    {
        $file = $this->write('<?php
class My_Service {
    public function render() {}
}
function create_service(): My_Service {
    return new My_Service();
}
class My_Controller {
    public function boot() {
        $x = create_service();
        $x->render();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('render', $unusedMethods);
    }

    public function testCreditsMethodCalledThroughAThisMethodsReturnType(): void
    {
        $file = $this->write('<?php
class My_Service {
    public function render() {}
}
class My_Controller {
    private function makeService(): My_Service {
        return new My_Service();
    }
    public function boot() {
        $x = $this->makeService();
        $x->render();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('render', $unusedMethods);
    }

    public function testReturnTypeCreditDoesNotLeakToAnUnrelatedClassWithTheSameMethodName(): void
    {
        // Dead_Service's own "render" must still be reported — the return-type credit is scoped
        // to My_Service (what make() actually declares), not a bare-name match against every
        // class with a same-named method.
        $file = $this->write('<?php
class My_Service {
    public function render() {}
}
class Dead_Service {
    public function render() {}
}
class My_Factory {
    public static function make(): My_Service {
        return new My_Service();
    }
}
class My_Controller {
    public function boot() {
        $x = My_Factory::make();
        $x->render();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('render', $unusedMethods);
        self::assertCount(1, $unusedMethods, 'Only Dead_Service::render should be flagged, not My_Service::render');
    }

    public function testUnresolvableSourceCallFallsBackToTheUnscopedPoolInsteadOfLosingTheCall(): void
    {
        // $c = totally_unknown_function(); $c->maybe_used(); — the source call's return type
        // never resolves (the function doesn't exist at all here), so this must degrade to the
        // same unscoped-pool credit the call always got before PendingReturnTypedCall existed,
        // not silently disappear — a same-named real method elsewhere must still be credited.
        $file = $this->write('<?php
class Some_Class {
    public function maybe_used() {}
}
class My_Controller {
    public function boot() {
        $c = totally_unknown_function();
        $c->maybe_used();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('maybe_used', $unusedMethods);
    }

    // ── generated contract-method fallback (WpCoreContractMethods) ─────────────────────────

    public function testExcludesContractMethodFromGeneratedWpCoreStubNotInTheHandCuratedList(): void
    {
        // Custom_Image_Header (wp-includes/class-wp-customize-manager.php's Custom_Image_Header,
        // the classic pre-Customizer-era custom-header API) isn't in ClassAnalyzer's hand-curated
        // BASE_CLASS_CONTRACT_METHODS at all — its init() override point is only known through
        // WpCoreContractMethods (tools/generate-wp-contract-methods-stub.php), which found it by
        // scanning real WP core for a public method declared on the class and also called via
        // $this->method() from elsewhere in that same class's body. Content-dependent on the
        // generated stub's current real-world data, same as HookAnalyzerTest's own reliance on
        // 'init'/'wp_head' actually being in WpCoreHooks — Custom_Image_Header::init() being an
        // overridable hook is stable, long-standing WP core API, not expected to disappear.
        $file = $this->write('<?php
class My_Custom_Header extends Custom_Image_Header {
    public function init() {}
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('init', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testGeneratedContractMethodFallbackDoesNotLeakToAnUnrelatedClass(): void
    {
        // A method literally named "init" on a class with no relation to Custom_Image_Header
        // must still be reported — same leak-guard shape as the hand-curated lists' own tests.
        $file = $this->write('<?php
class Not_A_Custom_Header {
    public function init() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('init', $unusedMethods);
    }

    // ── whole-class exemption (Acorn/Sage-style reflection-dispatch base classes) ──────────

    public function testExcludesFullyExemptBaseClassMethods(): void
    {
        // Roots\Acorn\View\Composer subclasses (recorded here by its short name, "Composer",
        // same as every other curated base-class list) are called entirely by matching an
        // author-chosen method name against a Blade view's requested variable at render time —
        // no fixed contract method name exists to check against.
        $file = $this->write('<?php
class App extends Composer {
    public function siteName() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testExcludesFullyExemptBaseClassFromUnusedClasses(): void
    {
        $file = $this->write('<?php
class App extends Composer {
    public function siteName() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testFullyExemptBaseClassDoesNotLeakToUnrelatedClasses(): void
    {
        $file = $this->write('<?php
class Not_A_Composer {
    public function siteName() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('siteName', $unusedMethods);
    }

    public function testFullyExemptBaseClassStillAppliesWhenImportResolvesToTheRealFqcn(): void
    {
        // Same shape as the collision test below, but the `use` import this time resolves
        // "Composer" to the actual Roots\Acorn\View\Composer FQCN — the exemption must still
        // apply, same as when there's no import at all (the bare short-name fallback case
        // covered by testExcludesFullyExemptBaseClassMethods above).
        $file = $this->write('<?php
use Roots\Acorn\View\Composer;
class App extends Composer {
    public function siteName() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass));
    }

    public function testFullyExemptBaseClassNameCollisionWithUnrelatedImportIsNotExempted(): void
    {
        // A project's own "Composer" class, unrelated to Roots\Acorn\View\Composer, explicitly
        // imported from a different namespace via `use`. The short name matches
        // FULLY_EXEMPT_BASE_CLASSES, but the resolved FQCN doesn't — this must NOT be treated as
        // the Acorn base class, or every subclass's methods (and the subclass itself, if
        // unreferenced) would be silently exempted by name collision alone.
        $file = $this->write('<?php
use My\App\Composer;
class Not_Acorn extends Composer {
    public function siteName() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('siteName', $unusedMethods);
        self::assertContains(
            'Not_Acorn',
            array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedClass), 'name'),
        );
    }

    public function testNamespacedComposerCollisionInSameNamespaceAsBaseIsNotExempted(): void
    {
        // The gap merely being namespace-aware closes as a side effect: no `use` import at all,
        // so before namespace-awareness existed there was no way to tell this file's own local
        // "Composer" class apart from a real Roots\Acorn\View\Composer subclass -- both looked
        // like the exact same bare short-name match. Now My\App\Composer !== the file's own
        // resolved extends target only when there really is a mismatch; here the extending class
        // is namespaced too, so its own Composer reference resolves to My\App\Composer, not the
        // Acorn FQCN, and must NOT be exempted.
        $file = $this->write('<?php
namespace My\App;
class Composer {
    public function siteName() {}
}
class Not_Acorn extends Composer {
    public function ownMethod() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('ownMethod', $unusedMethods);
    }

    public function testNamespacedSubclassStillGetsWpCoreContractMethodExemption(): void
    {
        // Guards against the FQCN-aware redesign accidentally requiring an FQCN match against
        // WP-core names (which are always global-namespace, keyed by bare short name in
        // BASE_CLASS_CONTRACT_METHODS) -- widget()/form()/update() are WP_Widget's own contract,
        // called by WP core itself, never by name in project code.
        $file = $this->write('<?php
namespace My_Plugin;
class My_Widget extends \WP_Widget {
    public function widget($args, $instance) {}
    public function form($instance) {}
    public function update($new_instance, $old_instance) {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testTwoProjectClassesWithSameShortNameInDifferentNamespacesAreTrackedSeparately(): void
    {
        // Modeled directly on the real Elementor bug this whole effort was built from: two
        // unrelated classes both named Base_Route, in different namespaces, each declaring their
        // own register_route() method. Before FQCN-aware keying, $classDefsByName['Base_Route']
        // silently held only whichever ClassDef was parsed last, and $scopedCalled['Base_Route']
        // was one shared bucket -- a genuine call to one would falsely credit the other's method
        // as used too.
        $routesFile = $this->write('<?php
namespace Elementor\App\Modules\ImportExportCustomization\Data\Routes;
abstract class Base_Route {
    abstract protected function get_args(): array;
}
class Export extends Base_Route {
    public function register_route($ns, $base) {}
    protected function get_args(): array { return []; }
}
');
        $v2BaseFile = $this->write('<?php
namespace Elementor\Data\V2\Base;
abstract class Base_Route {
    protected function register_route($route = "", $methods = "GET", $args = []) {}
}
');
        $callerFile = $this->write('<?php
namespace Elementor\App\Modules\ImportExportCustomization\Data;
use Elementor\App\Modules\ImportExportCustomization\Data\Routes\Export;
class Controller {
    private static function register_routes() {
        ( new Export() )->register_route("ns", "base");
    }
}
');
        $findings = $this->analyzer->analyze(
            [$routesFile, $v2BaseFile, $callerFile],
            suppressUnusedClassMethods: false,
        );
        // The genuinely-called one, on the correct Base_Route, must not be reported.
        self::assertNotContains(
            'register_route',
            array_column(
                array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod && $f->file === $routesFile),
                'name',
            ),
        );
        // The unrelated, never-called Base_Route::register_route on Data\V2\Base must still be
        // reported -- this is the assertion that would have passed "by accident" pre-fix (both
        // would have looked used via the short-name collision) and is the real proof this works.
        self::assertContains(
            'register_route',
            array_column(
                array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod && $f->file === $v2BaseFile),
                'name',
            ),
        );
    }

    public function testInlineNewChainedCallExemptsMethodFromUnusedReport(): void
    {
        $file = $this->write('<?php
namespace My\App;
class Export {
    public function register_route() {}
}
( new Export() )->register_route();
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testPropertyMethodCallViaTypedConstructorParameterExemptsTheMethod(): void
    {
        // Real-world case (Elementor's Data\V2\Base\Base_Route/Controller): a type-hinted
        // constructor parameter manually assigned to a property, then called from a completely
        // different method -- $this->controller->get_permission_callback(). Previously invisible
        // to $propertyAssignedClasses (only new ClassName() and constructor-promoted properties
        // were tracked), so every override of get_permission_callback() anywhere in the
        // Controller hierarchy looked unused regardless of real usage.
        $file = $this->write('<?php
class Controller {
    public function get_permission_callback() {}
}
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
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('get_permission_callback', $unusedMethods);
    }

    // ── vendor reflection fallback (contract methods on classes outside the scan) ──────────

    public function testExcludesMethodOverridingVendorClassViaReflection(): void
    {
        // Simulates a Composer dependency: a base class the token parser never sees because
        // it isn't part of the files handed to analyze() — only reachable via the vendor
        // autoloader path, resolved through the `use` import on the project file below.
        $vendorAutoload = $this->write('<?php
namespace Vendor\Acme;
class ServiceProvider {
    public function register() {}
}
');
        $file = $this->write('<?php
use Vendor\Acme\ServiceProvider;
class My_Provider extends ServiceProvider {
    public function register() {}
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], [$vendorAutoload], false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('register', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testVendorReflectionFallbackIsUnusedWithoutAnAutoloadPath(): void
    {
        // Same shape as above, but analyze() is called without a vendor autoload path (the
        // common case — no composer.json/vendor found) — the override should be reported like
        // any other unresolved method, exactly as it always was before the reflection fallback.
        // Deliberately no vendor autoload fixture written: ServiceProvider is unresolvable
        // either way, with or without one, since analyze() never gets a path to it.
        $file = $this->write('<?php
use Vendor\Acme2\ServiceProvider;
class My_Provider2 extends ServiceProvider {
    public function register() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('register', $unusedMethods);
    }

    public function testExcludesMethodOverridingVendorInterfaceViaReflection(): void
    {
        $vendorAutoload = $this->write('<?php
namespace Vendor\Acme3;
interface Renderable {
    public function render(): string;
}
');
        $file = $this->write('<?php
use Vendor\Acme3\Renderable;
class My_View implements Renderable {
    public function render(): string { return ""; }
}
');
        $findings = $this->analyzer->analyze([$file], [$vendorAutoload]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testExcludesMultipleVendorAutoloadPaths(): void
    {
        // The Bedrock-style case: two separate vendor/ directories (the project root's, and a
        // theme's own) each define a piece of the picture — the reflector must consult both.
        $baseAutoload = $this->write('<?php
namespace Vendor\Base;
class Base {
    public function boot() {}
}
');
        $subAutoload = $this->write('<?php
namespace Vendor\Sub;
use Vendor\Base\Base;
class Sub extends Base {}
');
        $file = $this->write('<?php
use Vendor\Sub\Sub;
class My_App extends Sub {
    public function boot() {}
}
');
        $findings = $this->analyzer->analyze([$file], [$baseAutoload, $subAutoload]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testExcludesMagicMethodsFromUnusedMethods(): void
    {
        $file = $this->write('<?php
class My_Class {
    public function __construct() {}
    public function __toString() {}
}
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    // ── dynamic concatenated callback prefixes ──────────────────────────────────────────────

    public function testCreditsMethodMatchedByADynamicConcatenatedCallbackPrefix(): void
    {
        // Real-world finding (Astra theme): `add_action('astra_footer_html_'.$i,
        // array($this, 'footer_html_'.$i))` inside a `for` loop wiring N numbered component
        // slots. footer_html_1..4 are only ever reached through the runtime-built suffix, never
        // a literal exact name — the prefix ('footer_html_') resolved against $this must still
        // credit them.
        $file = $this->write('<?php
class Astra_Builder_Footer {
    public function wire() {
        for ($i = 1; $i <= 2; $i++) {
            add_action("astra_footer_html_" . $i, array($this, "footer_html_" . $i));
        }
    }
    public function footer_html_1() {}
    public function footer_html_2() {}
    public function truly_unused() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('footer_html_1', $unusedMethods);
        self::assertNotContains('footer_html_2', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testDynamicCallbackPrefixCreditDoesNotLeakToAnUnrelatedClass(): void
    {
        // Other_Class's own similarly-prefixed method must still be reported — the prefix credit
        // is scoped to the receiver class it was actually resolved against ($this inside
        // My_Builder), not a bare-name match against every class.
        $file = $this->write('<?php
class My_Builder {
    public function wire() {
        for ($i = 1; $i <= 1; $i++) {
            add_action("hook_" . $i, array($this, "slot_" . $i));
        }
    }
    public function slot_1() {}
}
(new My_Builder())->wire();

class Other_Class {
    public function slot_1() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_values(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
        self::assertCount(1, $unusedMethods);
        self::assertSame('slot_1', $unusedMethods[0]->name);
        self::assertSame(13, $unusedMethods[0]->line, 'Should flag Other_Class::slot_1(), not My_Builder::slot_1()');
    }

    // ── reflection-dispatched class names (WP_CLI::add_command) ────────────────────────────

    public function testCreditsEveryPublicMethodOfAClassRegisteredWithWpCliAddCommand(): void
    {
        // Real-world finding (Astra theme): WP_CLI::add_command('astra abilities',
        // 'Astra_Abilities_CLI') hands WP-CLI a class name it dispatches across by reflection —
        // whichever public method matches the typed subcommand runs. No fixed method name
        // exists to check per class the way BASE_CLASS_CONTRACT_METHODS does, so every method on
        // the registered class must be exempt.
        $file = $this->write('<?php
class Astra_Abilities_CLI {
    public function enable($args, $assoc_args) {}
    public function disable($args, $assoc_args) {}
}
WP_CLI::add_command( "astra abilities", "Astra_Abilities_CLI" );
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        self::assertEmpty(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
    }

    public function testWpCliAddCommandExemptionDoesNotLeakToUnrelatedClasses(): void
    {
        $file = $this->write('<?php
class Astra_Abilities_CLI {
    public function enable($args, $assoc_args) {}
}
WP_CLI::add_command( "astra abilities", "Astra_Abilities_CLI" );

class Not_A_Cli_Command {
    public function enable() {}
}
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('enable', $unusedMethods);
    }

    // ── property-type tracking ──────────────────────────────────────────────────────────────

    public function testCreditsMethodCalledThroughAPropertySetInAnotherMethod(): void
    {
        // $this->service = new My_Service() in the constructor, $this->service->render() called
        // from a different method entirely — the biggest remaining precision gap before this
        // fix: property types weren't tracked at all, so this fell back to the unscoped pool.
        $file = $this->write('<?php
class My_Service {
    public function render() {}
    public function truly_unused() {}
}
class My_Controller {
    private $service;
    public function __construct() {
        $this->service = new My_Service();
    }
    public function boot() {
        $this->service->render();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('render', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
    }

    public function testCreditsMethodCalledThroughAConstructorPromotedProperty(): void
    {
        // public function __construct(private My_Service $svc) {} auto-assigns $this->svc — same
        // effect as an explicit assignment, credited the same way.
        $file = $this->write('<?php
class My_Service {
    public function render() {}
}
class My_Controller {
    public function __construct(private My_Service $svc) {}
    public function boot() {
        $this->svc->render();
    }
}
(new My_Controller(new My_Service()))->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('render', $unusedMethods);
    }

    public function testPropertyTypeCreditDoesNotLeakToAnUnrelatedClassWithTheSameMethodName(): void
    {
        // Dead_Service's own "render" must still be reported — the property-type credit is
        // scoped to My_Service (what $this->service was actually assigned), not a bare-name
        // match against every class with a same-named method.
        $file = $this->write('<?php
class My_Service {
    public function render() {}
}
class Dead_Service {
    public function render() {}
}
class My_Controller {
    public function __construct() {
        $this->service = new My_Service();
    }
    public function boot() {
        $this->service->render();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('render', $unusedMethods);
        self::assertCount(1, $unusedMethods, 'Only Dead_Service::render should be flagged, not My_Service::render');
    }

    public function testReassigningAPropertyToAnUnresolvableValueInvalidatesItsTrackedType(): void
    {
        // $this->service is reassigned to a plain function-call result partway through — its
        // previously tracked type must not survive to credit an unrelated later call.
        $file = $this->write('<?php
class My_Service {
    public function render() {}
}
class My_Controller {
    public function __construct() {
        $this->service = new My_Service();
        $this->service = some_factory();
    }
    public function boot() {
        $this->service->render();
    }
}
(new My_Controller())->boot();
');
        $findings = $this->analyzer->analyze([$file], suppressUnusedClassMethods: false);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('render', $unusedMethods);
    }

    public function testDoesNotReportStandaloneFunctionsAsUnusedMethods(): void
    {
        $file = $this->write('<?php
function standalone_func() {}
standalone_func();
');
        $findings = $this->analyzer->analyze([$file]);
        self::assertEmpty($findings);
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
