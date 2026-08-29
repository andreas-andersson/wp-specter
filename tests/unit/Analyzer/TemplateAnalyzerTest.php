<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\TemplateAnalyzer;
use WpSpecter\Detector\WpModeDetector;
use WpSpecter\Enum\WpMode;
use WpSpecter\Parser\PhpTokenParser;

final class TemplateAnalyzerTest extends TestCase
{
    private string $tmp;
    private TemplateAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-ta-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->analyzer = new TemplateAnalyzer(new PhpTokenParser(), new WpModeDetector());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testReportsUnreferencedTemplatePart(): void
    {
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/card.php');
        $main = $this->writeCode('<?php // no template refs');

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertCount(1, $findings);
        self::assertStringContainsString('card', $findings[0]->name);
    }

    public function testDoesNotReportReferencedTemplatePart(): void
    {
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/hero.php');
        $main = $this->writeCode("<?php get_template_part( 'template-parts/hero' );");

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testDoesNotReportTemplatePartReferencedThroughAVariableSlug(): void
    {
        // $slug = 'template-parts/hero'; get_template_part($slug); -- the variable's last-known
        // literal value resolves the slug exactly the same way a literal directly in the call
        // already would.
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/hero.php');
        $main = $this->writeCode("<?php
\$slug = 'template-parts/hero';
get_template_part( \$slug );
");

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testDoesNotReportTemplatePartReferencedThroughAClassConstantSlug(): void
    {
        // const SLUG = 'template-parts/hero'; get_template_part(self::SLUG); -- resolves the same
        // way a literal directly in the call already would.
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/hero.php');
        $main = $this->writeCode('<?php
class My_Theme {
    const SLUG = "template-parts/hero";
    public function render() {
        get_template_part( self::SLUG );
    }
}
');

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testDoesNotReportTemplatePartReferencedThroughANamespaceQualifiedCall(): void
    {
        // Sub\get_template_part(...) -- a namespaced or fully-qualified call, invisible to
        // template detection entirely before this fix (not just FunctionAnalyzer).
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/hero.php');
        $main = $this->writeCode('<?php
namespace My_Theme;
function render() {
    Sub\get_template_part( "template-parts/hero" );
}
');

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testExemptsWpHierarchyTemplatesInClassicMode(): void
    {
        $themeDir = $this->tmp;
        $single = $this->touch('single.php');
        $archive = $this->touch('archive.php');

        $findings = $this->analyzer->analyze([$single, $archive], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testDoesNotExemptHierarchyTemplatesInBlockMode(): void
    {
        $themeDir = $this->tmp;
        // In block mode, hierarchy templates are not auto-used (blocks handle routing)
        $part = $this->touch('parts/hero.php');

        $findings = $this->analyzer->analyze([$part], WpMode::Block, $themeDir);

        self::assertCount(1, $findings);
    }

    public function testDoesNotExemptHierarchyNamedTemplatesInPluginMode(): void
    {
        // Real-world case (WooCommerce): templates/taxonomy-product-cat.php is a plugin's own
        // bundled override template, never auto-located by WP core's theme hierarchy the way a
        // theme's own taxonomy.php would be — WP's locate_template() only ever looks in the
        // active theme. A plugin-bundled file whose name happens to start with a hierarchy
        // prefix must not get a free pass just for looking like one.
        $themeDir = $this->tmp;
        $taxonomy = $this->touch('templates/taxonomy-product-cat.php');

        $findings = $this->analyzer->analyze([$taxonomy], WpMode::Plugin, $themeDir);

        self::assertCount(1, $findings);
    }

    public function testStillExemptsHierarchyTemplatesInHybridMode(): void
    {
        // Hybrid (a block theme that still ships some classic PHP templates) is a real theme
        // scan -- must keep the hierarchy exemption, unlike Plugin mode above.
        $themeDir = $this->tmp;
        $single = $this->touch('single.php');

        $findings = $this->analyzer->analyze([$single], WpMode::Hybrid, $themeDir);

        self::assertEmpty($findings);
    }

    public function testFunctionsPhpNeverFlagged(): void
    {
        $themeDir = $this->tmp;
        $func = $this->touch('functions.php');

        $findings = $this->analyzer->analyze([$func], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testIncludeCountsAsReference(): void
    {
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/footer-widget.php');
        $main = $this->writeCode("<?php include 'template-parts/footer-widget.php';");

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testGetTemplatePartHyphenSuffixCountsAsReference(): void
    {
        // get_template_part('template-parts/content', 'search') resolves to content-search.php
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/content-search.php');
        $main = $this->writeCode("<?php get_template_part( 'template-parts/content', 'search' );");

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    public function testConfigArrayPhpPathCountsAsReference(): void
    {
        // ACF's 'render_template' => get_template_directory() . '/template-parts/hero.php'
        $themeDir = $this->tmp;
        $part = $this->touch('template-parts/hero.php');
        $main = $this->writeCode("<?php
acf_register_block_type(array(
    'render_template' => get_template_directory() . '/template-parts/hero.php',
));
");

        $findings = $this->analyzer->analyze([$part, $main], WpMode::Classic, $themeDir);

        self::assertEmpty($findings);
    }

    // ── Sage/Acorn Blade views (resources/views) ────────────────────────────────────────────

    public function testReportsUnreferencedBladeView(): void
    {
        $themeDir = $this->tmp;
        $view = $this->writeBlade('resources/views/partials/dead.blade.php', '<p>dead</p>');

        $findings = $this->analyzer->analyze([$view], WpMode::Hybrid, $themeDir);

        self::assertCount(1, $findings);
        self::assertStringContainsString('dead.blade.php', $findings[0]->name);
    }

    public function testDoesNotReportBladeViewReferencedViaExtends(): void
    {
        $themeDir = $this->tmp;
        $layout = $this->writeBlade('resources/views/layouts/app.blade.php', '<html></html>');
        $page = $this->writeBlade('resources/views/page.blade.php', "@extends('layouts.app')");

        $findings = $this->analyzer->analyze([$layout, $page], WpMode::Hybrid, $themeDir);

        self::assertEmpty(array_filter($findings, fn($f) => str_contains($f->name, 'app.blade.php')));
    }

    public function testDoesNotReportBladeViewReferencedViaInclude(): void
    {
        // "site-nav" is deliberately not a WP hierarchy name/prefix, so this only passes if the
        // @include directive itself is what resolved the reference.
        $themeDir = $this->tmp;
        $partial = $this->writeBlade('resources/views/partials/site-nav.blade.php', '<nav></nav>');
        $page = $this->writeBlade('resources/views/page.blade.php', "@include('partials.site-nav')");

        $findings = $this->analyzer->analyze([$partial, $page], WpMode::Hybrid, $themeDir);

        self::assertEmpty(array_filter($findings, fn($f) => str_contains($f->name, 'site-nav')));
    }

    public function testDoesNotReportBladeViewReferencedInsideIncludeFirstArray(): void
    {
        // @includeFirst(['partials.content-' . get_post_type(), 'partials.content-custom']) —
        // the first element is dynamic (unresolvable), the second is a real literal alongside
        // it in the same array; every quoted literal inside the parens must be captured.
        $themeDir = $this->tmp;
        $content = $this->writeBlade('resources/views/partials/content-custom.blade.php', '<article></article>');
        $index = $this->writeBlade(
            'resources/views/index.blade.php',
            "@includeFirst(['partials.content-' . get_post_type(), 'partials.content-custom'])",
        );

        $findings = $this->analyzer->analyze([$content, $index], WpMode::Hybrid, $themeDir);

        self::assertEmpty(array_filter($findings, fn($f) => str_contains($f->name, 'content-custom')));
    }

    public function testDoesNotReportBladeViewWithUnbalancedParenInReferencedName(): void
    {
        // A lone, unbalanced ')' inside a quoted view-name string must not be counted as the
        // directive's real closing paren by findMatchingParen() — otherwise the paren-matching
        // desyncs at that point, truncating the args substring mid-string (with the literal's
        // opening quote left unterminated), and the reference is never captured at all. A
        // balanced pair inside the string wouldn't actually expose this — it takes an unbalanced
        // one to desync the depth counter.
        $themeDir = $this->tmp;
        $partial = $this->writeBlade('resources/views/partials/a)custom.blade.php', '<div></div>');
        $page = $this->writeBlade('resources/views/page.blade.php', "@include('partials.a)custom')");

        $findings = $this->analyzer->analyze([$partial, $page], WpMode::Hybrid, $themeDir);

        self::assertEmpty(array_filter($findings, fn($f) => str_contains($f->name, 'a)custom')));
    }

    public function testDoesNotReportBladeComponentReferencedViaXTag(): void
    {
        $themeDir = $this->tmp;
        $component = $this->writeBlade('resources/views/components/alert.blade.php', '<div></div>');
        $page = $this->writeBlade('resources/views/page.blade.php', '<x-alert type="warning"></x-alert>');

        $findings = $this->analyzer->analyze([$component, $page], WpMode::Hybrid, $themeDir);

        self::assertEmpty(array_filter($findings, fn($f) => str_contains($f->name, 'alert')));
    }

    public function testExemptsBladeHierarchyTemplates(): void
    {
        // WP hierarchy names carried over verbatim as .blade.php basenames — same exemption as
        // classic root-level single.php/archive.php, just nested under resources/views and with
        // the double extension that templateBasename() has to strip as a unit.
        $themeDir = $this->tmp;
        $single = $this->writeBlade('resources/views/single.blade.php', '<article></article>');

        $findings = $this->analyzer->analyze([$single], WpMode::Hybrid, $themeDir);

        self::assertEmpty($findings);
    }

    public function testExemptsBladeCustomPageTemplateHeader(): void
    {
        // "template-custom" isn't a hierarchy name or prefix, so this only passes if the
        // Template Name: header check applies to TemplateAnalyzer's candidates too (previously
        // only FileAnalyzer had it) — and works through Blade's {{-- --}} comment syntax, since
        // the check is a raw-text regex that doesn't care which comment syntax wraps it.
        $themeDir = $this->tmp;
        $view = $this->writeBlade(
            'resources/views/template-custom.blade.php',
            "{{--\n  Template Name: Custom Template\n--}}\n\n@extends('layouts.app')",
        );

        $findings = $this->analyzer->analyze([$view], WpMode::Hybrid, $themeDir);

        self::assertEmpty($findings);
    }

    public function testResourcesViewsIndexIsNotMistakenForBootstrapIndexPhp(): void
    {
        // Block mode doesn't apply the hierarchy exemption, so this file can only end up NOT
        // reported by being wrongly filtered out during collection — the same
        // functions.php/index.php skip meant for the theme's root bootstrap files. Asserting it
        // IS reported here proves it was correctly collected as a real template candidate
        // instead.
        $themeDir = $this->tmp;
        $view = $this->writeBlade('resources/views/index.blade.php', '<div>home</div>');

        $findings = $this->analyzer->analyze([$view], WpMode::Block, $themeDir);

        self::assertCount(1, $findings);
        self::assertStringContainsString('index.blade.php', $findings[0]->name);
    }

    private function writeBlade(string $relative, string $content): string
    {
        $path = $this->tmp . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function touch(string $relative): string
    {
        $path = $this->tmp . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, '<?php');
        return $path;
    }

    private function writeCode(string $code): string
    {
        // Keep non-template PHP files in a subdirectory that won't be scanned as templates
        $dir = $this->tmp . '/src';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/test_' . uniqid() . '.php';
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
