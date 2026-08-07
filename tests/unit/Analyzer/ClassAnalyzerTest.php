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
        $findings = $this->analyzer->analyze([$file]);
        $methods = array_values(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod));
        self::assertCount(1, $methods);
        self::assertSame('unused_method', $methods[0]->name);
        self::assertSame(FindingCertainty::Warning, $methods[0]->certainty);
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
        $findings = $this->analyzer->analyze([$file]);
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
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertNotContains('widget', $unusedMethods);
        self::assertNotContains('form', $unusedMethods);
        self::assertNotContains('update', $unusedMethods);
        self::assertContains('truly_unused', $unusedMethods);
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
        $findings = $this->analyzer->analyze([$file]);
        $unusedMethods = array_column(array_filter($findings, fn($f) => $f->type === FindingType::UnusedMethod), 'name');
        self::assertContains('widget', $unusedMethods);
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
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
