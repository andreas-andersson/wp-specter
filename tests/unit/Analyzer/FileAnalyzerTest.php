<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\FileAnalyzer;
use WpSpecter\Parser\PhpTokenParser;

final class FileAnalyzerTest extends TestCase
{
    private string $tmp;
    private FileAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-fa-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->analyzer = new FileAnalyzer(new PhpTokenParser());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testReportsFileNeverIncluded(): void
    {
        $orphan = $this->write('inc/orphan.php', '<?php function orphan_fn() {}');

        $findings = $this->analyzer->analyze([$orphan], $this->tmp);

        self::assertCount(1, $findings);
        self::assertSame('orphan.php', $findings[0]->name);
    }

    public function testDoesNotReportFileReferencedByLiteralInclude(): void
    {
        $entry = $this->write('functions.php', "<?php include_once 'inc/setup.php';");
        $setup = $this->write('inc/setup.php', '<?php // setup');

        self::assertEmpty($this->analyzer->analyze([$entry, $setup], $this->tmp));
    }

    public function testDoesNotReportFileReferencedByConcatenatedInclude(): void
    {
        $index = $this->write('inc/crons/index.php', "<?php
\$path = dirname(__FILE__);
include_once \$path . '/storm.php';
");
        $storm = $this->write('inc/crons/storm.php', '<?php // cron');

        self::assertEmpty($this->analyzer->analyze([$index, $storm], $this->tmp));
    }

    public function testIndexPhpFilesAreExempt(): void
    {
        $silence = $this->write('uploads/index.php', '<?php // Silence is golden');

        self::assertEmpty($this->analyzer->analyze([$silence], $this->tmp));
    }

    public function testRootLevelFilesAreExempt(): void
    {
        // functions.php / single.php etc. belong to TemplateAnalyzer's hierarchy check
        $root = $this->write('single.php', '<?php get_header();');

        self::assertEmpty($this->analyzer->analyze([$root], $this->tmp));
    }

    public function testTemplateDirsAreExempt(): void
    {
        // templates/template-parts/parts belong to TemplateAnalyzer
        $part = $this->write('template-parts/card.php', '<?php // card');

        self::assertEmpty($this->analyzer->analyze([$part], $this->tmp));
    }

    public function testSageResourcesViewsDirIsExempt(): void
    {
        // resources/views (Roots Sage/Acorn's Blade views root) belongs to TemplateAnalyzer too.
        $view = $this->write('resources/views/partials/content.blade.php', '<article></article>');

        self::assertEmpty($this->analyzer->analyze([$view], $this->tmp));
    }

    public function testPageTemplateHeaderIsExempt(): void
    {
        // WP Page Templates are selected from the admin UI via this header — never included
        $tpl = $this->write('page-templates/no-title.php', "<?php /** Template Name: No title */\n");

        self::assertEmpty($this->analyzer->analyze([$tpl], $this->tmp));
    }

    public function testConfigArrayPhpPathIsTreatedAsReference(): void
    {
        // ACF's 'render_template' => get_template_directory() . '/acf-blocks/hero.php' pattern
        $register = $this->write('acf-blocks/register.php', "<?php
acf_register_block_type(array(
    'render_template' => get_template_directory() . '/acf-blocks/hero.php',
));
");
        $hero = $this->write('acf-blocks/hero.php', '<?php // render');

        $names = array_column($this->analyzer->analyze([$register, $hero], $this->tmp), 'name');
        self::assertNotContains('hero.php', $names);
    }

    public function testDynamicGetTemplatePartSuffixMatchesSiblingFiles(): void
    {
        $caller = $this->write('inc/shortcodes/card-list.php', '<?php
$variant = "lg";
get_template_part("inc/shortcodes/variants/$variant", null);
');
        $variant = $this->write('inc/shortcodes/variants/lg.php', '<?php // variant');

        $names = array_column($this->analyzer->analyze([$caller, $variant], $this->tmp), 'name');
        self::assertNotContains('lg.php', $names);
    }

    public function testFileReferencedByBlockJsonRenderIsNotReported(): void
    {
        mkdir($this->tmp . '/blocks/hero', 0755, true);
        file_put_contents($this->tmp . '/blocks/hero/block.json', json_encode([
            'render' => 'file:./render.php',
        ]));
        $render = $this->write('blocks/hero/render.php', '<?php // render');

        self::assertEmpty($this->analyzer->analyze([$render], $this->tmp));
    }

    public function testPsr4AutoloadedFileIsExempt(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['My_Plugin\\' => 'src/']],
        ]);
        $class = $this->write('src/Service.php', '<?php namespace My_Plugin; class Service {}');

        self::assertEmpty($this->analyzer->analyze([$class], $this->tmp));
    }

    public function testPsr4WithMultipleDirsPerPrefixExemptsAll(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['My_Plugin\\' => ['src/', 'lib/']]],
        ]);
        $a = $this->write('src/A.php', '<?php // a');
        $b = $this->write('lib/B.php', '<?php // b');

        self::assertEmpty($this->analyzer->analyze([$a, $b], $this->tmp));
    }

    public function testAutoloadDevPsr4IsExempt(): void
    {
        $this->writeComposerJson([
            'autoload-dev' => ['psr-4' => ['My_Plugin\\Tests\\' => 'tests/']],
        ]);
        $test = $this->write('tests/ServiceTest.php', '<?php // test');

        self::assertEmpty($this->analyzer->analyze([$test], $this->tmp));
    }

    public function testClassmapDirIsExempt(): void
    {
        $this->writeComposerJson([
            'autoload' => ['classmap' => ['legacy/']],
        ]);
        $legacy = $this->write('legacy/Old_Class.php', '<?php // legacy');

        self::assertEmpty($this->analyzer->analyze([$legacy], $this->tmp));
    }

    public function testClassmapSingleFileIsExempt(): void
    {
        $this->writeComposerJson([
            'autoload' => ['classmap' => ['inc/bootstrap.php']],
        ]);
        $bootstrap = $this->write('inc/bootstrap.php', '<?php // bootstrap');

        self::assertEmpty($this->analyzer->analyze([$bootstrap], $this->tmp));
    }

    public function testAutoloadFilesEntryIsExempt(): void
    {
        $this->writeComposerJson([
            'autoload' => ['files' => ['inc/helpers.php']],
        ]);
        $helpers = $this->write('inc/helpers.php', '<?php // helper functions');

        self::assertEmpty($this->analyzer->analyze([$helpers], $this->tmp));
    }

    public function testFileOutsideAutoloadDirsIsStillReported(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['My_Plugin\\' => 'src/']],
        ]);
        $orphan = $this->write('inc/orphan.php', '<?php // not under src/');

        $names = array_column($this->analyzer->analyze([$orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testSimilarlyNamedDirIsNotExemptByPrefixCollision(): void
    {
        // "src/" is autoloaded, "src-legacy/" is not — the trailing slash in the exemption
        // check must stop a naive prefix match from treating these as the same directory.
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['My_Plugin\\' => 'src/']],
        ]);
        $legacy = $this->write('src-legacy/Old.php', '<?php // not actually under src/');

        $names = array_column($this->analyzer->analyze([$legacy], $this->tmp), 'name');
        self::assertContains('Old.php', $names);
    }

    public function testGlobLoopBulkIncludeExemptsSubdirectory(): void
    {
        $bootstrap = $this->write('functions.php', '<?php
foreach (glob(__DIR__ . "/inc/*.php") as $f) {
    require $f;
}
');
        $module = $this->write('inc/module.php', '<?php // loaded by the glob loop above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $module], $this->tmp), 'name');
        self::assertNotContains('module.php', $names);
    }

    public function testGlobLoopBulkIncludeExemptsOwnSiblingDirectory(): void
    {
        // The bootstrap file lives inside inc/ itself and globs its own directory ("*.php",
        // no subdirectory component) — the "one bootstrap file globbing its own sibling
        // directory" pattern.
        $bootstrap = $this->write('inc/loader.php', '<?php
foreach (glob(__DIR__ . "/*.php") as $f) {
    if ($f !== __FILE__) {
        require $f;
    }
}
');
        $sibling = $this->write('inc/module.php', '<?php // sibling loaded by the loop above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $sibling], $this->tmp), 'name');
        self::assertNotContains('module.php', $names);
    }

    public function testGlobWithoutIncludeStatementDoesNotExemptDirectory(): void
    {
        // glob() used for something unrelated to code-loading (e.g. listing images) must not
        // exempt the directory — no include/require keyword anywhere in the file.
        $gallery = $this->write('inc/gallery.php', '<?php
$images = glob(__DIR__ . "/assets/*.jpg");
');
        $unrelated = $this->write('assets/orphan.php', '<?php // not actually reachable');

        $names = array_column($this->analyzer->analyze([$gallery, $unrelated], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testGlobbedDirectoryDoesNotLeakToSimilarlyNamedDirectory(): void
    {
        $bootstrap = $this->write('functions.php', '<?php
foreach (glob(__DIR__ . "/inc/*.php") as $f) {
    require $f;
}
');
        $unrelated = $this->write('inc-legacy/orphan.php', '<?php // different directory entirely');

        $names = array_column($this->analyzer->analyze([$bootstrap, $unrelated], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testScandirLoopBulkIncludeExemptsDirectlyLiteralDirectory(): void
    {
        // scandir()'s argument is already a directory, not a glob() pattern — no dirname()
        // stripping should happen to it.
        $bootstrap = $this->write('functions.php', '<?php
foreach (scandir(__DIR__ . "/inc/configs") as $f) {
    require __DIR__ . "/inc/configs/" . $f;
}
');
        $config = $this->write('inc/configs/header-builder.php', '<?php // loaded by the scandir loop above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $config], $this->tmp), 'name');
        self::assertNotContains('header-builder.php', $names);
    }

    public function testScandirViaDefinedConstantExemptsDirectory(): void
    {
        // Real-world finding (Astra theme): `define('X_CONFIGS_DIR', X_THEME_DIR .
        // 'inc/customizer/.../configs/'); foreach (scandir(X_CONFIGS_DIR) as $f) { require
        // X_CONFIGS_DIR . $f; }` — scandir()'s own call has no literal at all, only a bare
        // constant reference; the meaningful literal is several statements earlier, in the
        // define() call that named it. X_THEME_DIR is left unresolved (it's a constant, not a
        // literal) — the recognized literal is a full project-root-relative path by convention,
        // not relative to whichever file happens to call scandir($constant).
        $bootstrap = $this->write('inc/customizer/class-builder.php', '<?php
define( "ASTRA_HEADER_BUILDER_CONFIGS_DIR", ASTRA_THEME_DIR . "inc/customizer/configs/header/" );
foreach ( scandir( ASTRA_HEADER_BUILDER_CONFIGS_DIR ) as $config_file ) {
    $path = ASTRA_HEADER_BUILDER_CONFIGS_DIR . $config_file;
    require_once $path;
}
');
        $config = $this->write('inc/customizer/configs/header/logo.php', '<?php // loaded via the constant above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $config], $this->tmp), 'name');
        self::assertNotContains('logo.php', $names);
    }

    public function testUndefinedConstantArgumentDoesNotMatchAnything(): void
    {
        // scandir(SOME_CONST) where SOME_CONST was never define()'d anywhere in the scanned
        // project (e.g. it's a WP core / another plugin's constant) must not exempt anything —
        // there's no literal to resolve it to.
        $bootstrap = $this->write('functions.php', '<?php
foreach (scandir(WP_CONTENT_DIR) as $f) {
    require WP_CONTENT_DIR . "/" . $f;
}
');
        $orphan = $this->write('inc/orphan.php', '<?php // NOT reachable — WP_CONTENT_DIR is never define()\'d here');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testDynamicMiddleSegmentRequireExemptsThemeRootRelativeDirectory(): void
    {
        // Real-world finding (Kadence theme): `require_once get_template_directory() .
        // '/inc/customizer/options/' . $key . '-options.php';` — findTrailingStringLiteral()
        // alone would surface only '-options.php' (the *last* literal, after the dynamic
        // segment), which is useless; the meaningful directory prefix comes *before* $key.
        // get_template_directory() makes this project-root-relative, not calling-file-relative —
        // 81 files under Kadence's own inc/customizer/options/ were unreachable by any other
        // mechanism before this fix.
        $bootstrap = $this->write('inc/customizer/class-theme-customizer.php', '<?php
foreach ( self::$settings_sections as $key ) {
    require_once get_template_directory() . "/inc/customizer/options/" . $key . "-options.php";
}
');
        $option = $this->write('inc/customizer/options/header-cart-options.php', '<?php // loaded by the loop above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $option], $this->tmp), 'name');
        self::assertNotContains('header-cart-options.php', $names);
    }

    public function testDynamicMiddleSegmentRequireExemptsCallingFileRelativeDirectory(): void
    {
        // Same dynamic-middle-segment shape, but via __DIR__ instead of a theme-root accessor —
        // must resolve relative to the calling file's own directory, not the project root.
        $bootstrap = $this->write('inc/loader.php', '<?php
foreach ( $keys as $key ) {
    require __DIR__ . "/options/" . $key . "-options.php";
}
');
        $option = $this->write('inc/options/header-cart-options.php', '<?php // loaded by the loop above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $option], $this->tmp), 'name');
        self::assertNotContains('header-cart-options.php', $names);
    }

    public function testDynamicMiddleSegmentRequireWithoutLiteralPrefixDoesNotMatch(): void
    {
        // No literal anywhere before the variable (just `$file . '.php'`) — nothing to treat as
        // a directory prefix, so this must not exempt anything.
        $bootstrap = $this->write('inc/loader.php', '<?php
foreach ( $files as $file ) {
    require $file . ".php";
}
');
        $orphan = $this->write('inc/orphan.php', '<?php // NOT reachable — no directory-prefix literal exists');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testGetTemplatePartWithHelperFunctionArgumentResolvesLiteralReturns(): void
    {
        // Real-world finding (OceanWP theme): `get_template_part( ocean_single_post_header_
        // template() )` — the argument is a bare call to a project helper whose body assigns a
        // different literal path to $template_path across several conditional branches, then
        // returns it via apply_filters()'s own default-value argument. The helper is defined in
        // a different file than the call site.
        $caller = $this->write('inc/helpers.php', '<?php
function ocean_render_header() {
    get_template_part( ocean_single_post_header_template() );
}
');
        $helper = $this->write('inc/template-helpers.php', '<?php
function ocean_single_post_header_template() {
    $template_path = "";
    if ( $a ) {
        $template_path = "partials/single/headers/header-2";
    } elseif ( $b ) {
        $template_path = "partials/single/headers/header-3";
    }
    return apply_filters( "oceanwp_single_post_header_template", $template_path );
}
');
        $header2 = $this->write('partials/single/headers/header-2.php', '<?php // reachable via the helper');
        $header3 = $this->write('partials/single/headers/header-3.php', '<?php // reachable via the helper');

        $names = array_column($this->analyzer->analyze([$caller, $helper, $header2, $header3], $this->tmp), 'name');
        self::assertNotContains('header-2.php', $names);
        self::assertNotContains('header-3.php', $names);
    }

    public function testGetTemplatePartWithVariableAssignedFromHelperFunctionResolves(): void
    {
        // One more level of indirection than the direct call-as-argument case above: `$var =
        // helper_fn(); get_template_part( $var );` — real-world example, OceanWP's own
        // ocean_single_post_header_meta_template_part().
        $caller = $this->write('inc/helpers.php', '<?php
function ocean_render_meta() {
    $template_part = ocean_single_post_header_meta_template();
    get_template_part( $template_part );
}
');
        $helper = $this->write('inc/template-helpers.php', '<?php
function ocean_single_post_header_meta_template() {
    $template_path = "";
    if ( $a ) {
        $template_path = "partials/single/metas/meta-2";
    }
    return apply_filters( "oceanwp_single_post_header_meta_template", $template_path );
}
');
        $meta2 = $this->write('partials/single/metas/meta-2.php', '<?php // reachable via the helper + variable');

        $names = array_column($this->analyzer->analyze([$caller, $helper, $meta2], $this->tmp), 'name');
        self::assertNotContains('meta-2.php', $names);
    }

    public function testArrayOfLiteralsForeachLoopExemptsListedFiles(): void
    {
        // Real-world finding (Astra theme): a plain array of relative path fragments (no glob(),
        // no scandir() — just a literal list), require_once'd one at a time in a foreach loop.
        // 64 files in Astra's own inc/abilities/ were unreachable by any other mechanism.
        $bootstrap = $this->write('inc/abilities/init.php', '<?php
$abilities_dir = ASTRA_THEME_DIR . "inc/abilities/";
$ability_files = array(
    // Performance abilities.
    "admin/settings/performance/class-astra-get-performance",
    "admin/settings/performance/class-astra-update-performance",
);
foreach ( $ability_files as $file ) {
    require_once $abilities_dir . $file . ".php";
}
');
        $listed = $this->write(
            'inc/abilities/admin/settings/performance/class-astra-get-performance.php',
            '<?php // loaded by the foreach loop above',
        );

        $names = array_column($this->analyzer->analyze([$bootstrap, $listed], $this->tmp), 'name');
        self::assertNotContains('class-astra-get-performance.php', $names);
    }

    public function testArrayOfLiteralsDoesNotMatchWhenAnyElementIsNotAStringLiteral(): void
    {
        // A single non-literal element (a variable, a concatenation, a function call, ...)
        // invalidates the whole array — bail rather than guess at a partially-dynamic list.
        // Deliberately no literal directory prefix before $file in the require target (just
        // `$file . '.php'`) — a prefixed shape like `__DIR__ . '/' . $file . '.php'` would
        // independently exempt this whole directory via the *separate*
        // findIncludeDirPrefixBeforeVariable mechanism regardless of the array's own validity,
        // which would no longer be isolating what this test checks.
        $bootstrap = $this->write('inc/loader.php', '<?php
$dynamic = "runtime";
$files = array("a/one", $dynamic);
foreach ( $files as $file ) {
    require $file . ".php";
}
');
        $orphan = $this->write('inc/a/one.php', '<?php // NOT reachable — the array failed to resolve');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');
        self::assertContains('one.php', $names);
    }

    public function testArrayOfLiteralsDoesNotMatchKeyedArray(): void
    {
        // 'key' => 'value' pairs aren't the plain sequential shape this supports — bail rather
        // than guess whether the key or the value is the meaningful path. Same no-directory-
        // prefix reasoning as the test above for why the require target is a bare `$file`, not
        // `__DIR__ . '/' . $file`.
        $bootstrap = $this->write('inc/loader.php', '<?php
$files = array("perf" => "a/one", "typo" => "b/two");
foreach ( $files as $key => $file ) {
    require $file . ".php";
}
');
        $orphan = $this->write('inc/a/one.php', '<?php // NOT reachable — keyed array is unsupported');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');
        self::assertContains('one.php', $names);
    }

    public function testArrayOfLiteralsRequiresAtLeastOnePathLikeElement(): void
    {
        // A short-string array with no "/" in any element (e.g. feature-flag names, not path
        // fragments) must not be treated as a bulk-include list — too easy to coincidentally
        // suffix-match an unrelated file elsewhere in the project.
        $bootstrap = $this->write('inc/loader.php', '<?php
$flags = array("alpha", "beta");
foreach ( $flags as $flag ) {
    do_something( $flag );
}
require __DIR__ . "/unrelated.php";
');
        $orphan = $this->write('inc/alpha.php', '<?php // NOT reachable — "alpha" has no "/"');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');
        self::assertContains('alpha.php', $names);
    }

    public function testSplAutoloadRegisterExemptsCallingFilesOwnDirectoryTree(): void
    {
        // Real-world finding (Kadence theme): a hand-rolled spl_autoload_register() callback
        // computes its require target from the requested class name at runtime — no per-file
        // include()/require() reference exists for the index to find, the same gap Composer's
        // own autoloader already has a dedicated exemption for. Scoped to the calling file's own
        // directory tree (inc/), same as Kadence's real inc/class-theme.php -> inc/components/*.
        $bootstrap = $this->write('inc/class-theme.php', '<?php
class Theme {
    public function __construct() {
        spl_autoload_register( array( $this, "autoload" ) );
    }
    private function autoload( $class_name ) {
        require get_template_directory() . "/inc/components/" . strtolower( $class_name ) . ".php";
    }
}
');
        $component = $this->write('inc/components/foo.php', '<?php // loaded by the autoloader above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $component], $this->tmp), 'name');
        self::assertNotContains('foo.php', $names);
    }

    public function testSplAutoloadRegisterAtProjectRootExemptsWholeProject(): void
    {
        // Real-world finding (Hello Biz / Hello Elementor themes): the bootstrap file that calls
        // spl_autoload_register() lives at the theme root itself, not in a subdirectory — so the
        // only honest scope this analyzer can offer (trusting the bootstrap file's own location,
        // same as the directory-tree case above) is the whole project.
        $bootstrap = $this->write('theme.php', '<?php
class Theme {
    public function __construct() {
        spl_autoload_register( array( $this, "autoload" ) );
    }
    private function autoload( $class_name ) {
        require HELLO_BIZ_PATH . strtolower( $class_name ) . ".php";
    }
}
');
        $module = $this->write('modules/foo/component.php', '<?php // loaded by the autoloader above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $module], $this->tmp), 'name');
        self::assertNotContains('component.php', $names);
    }

    public function testSplAutoloadRegisterDoesNotLeakToUnrelatedTargetDirectory(): void
    {
        // A composer.json-declared project scanned alongside its own separate composer/plugins
        // target shouldn't have this leak across — same "different target's tree" guard already
        // covered elsewhere, exercised here specifically against the new autoloader exemption:
        // a completely unrelated file in a sibling directory outside the autoloader file's own
        // tree must still be flagged normally.
        $bootstrap = $this->write('inc/class-theme.php', '<?php
spl_autoload_register( "my_autoload" );
function my_autoload( $class_name ) {
    require __DIR__ . "/components/" . strtolower( $class_name ) . ".php";
}
');
        $unrelated = $this->write('admin/orphan.php', '<?php // unrelated to the autoloader entirely');

        $names = array_column($this->analyzer->analyze([$bootstrap, $unrelated], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testNoComposerJsonDoesNotBreakAnalysis(): void
    {
        $orphan = $this->write('inc/orphan.php', '<?php // no composer.json in this fixture');

        $names = array_column($this->analyzer->analyze([$orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testMalformedComposerJsonIsIgnoredGracefully(): void
    {
        $this->write('composer.json', '{not valid json');
        $orphan = $this->write('inc/orphan.php', '<?php // still reported, malformed composer.json ignored');

        $names = array_column($this->analyzer->analyze([$orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    /** @param array<string,mixed> $data */
    private function writeComposerJson(array $data): string
    {
        return $this->write('composer.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function write(string $relativePath, string $code): string
    {
        $path = $this->tmp . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $code);
        return $path;
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
