<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Analyzer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Analyzer\FileAnalyzer;
use WpSpecter\Analyzer\LiteralPathPropagationResolver;
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

    public function testThirdPartyPluginTemplateOverrideDirectoryIsExempt(): void
    {
        // Real-world case (Kadence): bbPress's own bbp_locate_template() scans the active
        // theme's bbpress/ directory by filename convention, never through any call the theme
        // itself makes — all 13 of Kadence's own bbpress/ files were 100% of its UnusedFile
        // findings. WooCommerce's woocommerce/ override directory is the identical convention.
        $bbpress = $this->write('bbpress/content-archive-forum.php', '<?php // loaded by bbp_locate_template()');
        $woocommerce = $this->write('woocommerce/single-product.php', '<?php // loaded by wc_locate_template()');

        self::assertEmpty($this->analyzer->analyze([$bbpress, $woocommerce], $this->tmp));
    }

    public function testScriptTranslationL10nPhpFileIsExempt(): void
    {
        // WP 6.5+ script-translation files: WP core's own load_script_translations() finds these
        // by filename convention next to a registered script handle, never through a visible
        // include()/require() in project code. Real-world case (Advanced Custom Fields): 52 of
        // these under lang/, each a plain `return [...]` array a build tool generated.
        $l10n = $this->write('lang/acf-es_MX.l10n.php', "<?php return ['domain'=>NULL,'messages'=>[]];\n");

        self::assertEmpty($this->analyzer->analyze([$l10n], $this->tmp));
    }

    public function testWebpackAssetPhpBuildManifestIsExempt(): void
    {
        // webpack/@wordpress/scripts build-dependency manifests: loaded via a dynamic path built
        // from each compiled entry's own name, never a literal include()/require(). Real-world
        // scale: WooCommerce alone ships 320 of these under assets/client/, Jetpack 466.
        $asset = $this->write('assets/client/blocks/cart.asset.php', "<?php return array('dependencies' => array('wp-element'), 'version' => 'abc123');\n");

        self::assertEmpty($this->analyzer->analyze([$asset], $this->tmp));
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
        // Root-level file: not itself a candidate (WP template hierarchy), but still parsed for
        // class references — real-usage-proof needs *something* to reference "Service".
        $caller = $this->write('plugin.php', '<?php new Service();');

        self::assertEmpty($this->analyzer->analyze([$class, $caller], $this->tmp));
    }

    public function testPsr4WithMultipleDirsPerPrefixExemptsAll(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['My_Plugin\\' => ['src/', 'lib/']]],
        ]);
        $a = $this->write('src/A.php', '<?php // a');
        $b = $this->write('lib/B.php', '<?php // b');
        $caller = $this->write('plugin.php', '<?php new A(); new B();');

        self::assertEmpty($this->analyzer->analyze([$a, $b, $caller], $this->tmp));
    }

    public function testAutoloadDevPsr4IsExempt(): void
    {
        $this->writeComposerJson([
            'autoload-dev' => ['psr-4' => ['My_Plugin\\Tests\\' => 'tests/']],
        ]);
        $test = $this->write('tests/ServiceTest.php', '<?php // test');
        $caller = $this->write('plugin.php', '<?php new ServiceTest();');

        self::assertEmpty($this->analyzer->analyze([$test, $caller], $this->tmp));
    }

    public function testClassmapDirIsExempt(): void
    {
        $this->writeComposerJson([
            'autoload' => ['classmap' => ['legacy/']],
        ]);
        $legacy = $this->write('legacy/Old_Class.php', '<?php // legacy');
        $caller = $this->write('plugin.php', '<?php new Old_Class();');

        self::assertEmpty($this->analyzer->analyze([$legacy, $caller], $this->tmp));
    }

    public function testClassmapSingleFileIsExempt(): void
    {
        $this->writeComposerJson([
            'autoload' => ['classmap' => ['inc/bootstrap.php']],
        ]);
        $bootstrap = $this->write('inc/bootstrap.php', '<?php // bootstrap');
        $caller = $this->write('plugin.php', '<?php new bootstrap();');

        self::assertEmpty($this->analyzer->analyze([$bootstrap, $caller], $this->tmp));
    }

    public function testClassBuiltFromSnakeToPascalCaseTransformOfAConfigArrayKeyIsExempt(): void
    {
        // Real-world shape (WPForms): SmartTags::get_smart_tag_class_name() turns each key of
        // smart_tags_list()'s own returned array into a class name via the canonical
        // snake_case-to-PascalCase idiom, then instantiates it — AdminEmail is never spelled
        // out as a literal anywhere, only PSR-4-autoloadable. Kept in step with
        // ClassAnalyzer's own version of this fallback.
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['WPForms\\' => 'src/']],
        ]);
        $dispatcher = $this->write('src/SmartTags/SmartTags.php', '<?php
namespace WPForms\SmartTags;
class SmartTags {
    protected function smart_tags_list() {
        return [
            "admin_email" => "Site Administrator Email",
            "field_id"    => "Field ID",
        ];
    }
    protected function get_smart_tag_class_name( $smart_tag_name ) {
        $class_name = str_replace( " ", "", ucwords( str_replace( "_", " ", $smart_tag_name ) ) );
        $full_class_name = "\\\\WPForms\\\\SmartTags\\\\SmartTag\\\\" . $class_name;
        return $full_class_name;
    }
}
');
        $target = $this->write('src/SmartTags/SmartTag/AdminEmail.php', '<?php
namespace WPForms\SmartTags\SmartTag;
class AdminEmail {
    public function process() {}
}
');

        $names = array_column($this->analyzer->analyze([$dispatcher, $target], $this->tmp), 'name');
        self::assertNotContains('AdminEmail.php', $names);
    }

    public function testClassBuiltFromAnInlineTransformWithANonNamespacePrefixIsExempt(): void
    {
        // Real-world shape (Jetpack tiled-gallery), same as ClassAnalyzer's identical test —
        // the fixed prefix ('Jetpack_Tiled_Gallery_Layout_') isn't a namespace (no trailing
        // `\`), so it must be prepended back to the transformed value to reconstruct the real
        // short class name ('Jetpack_Tiled_Gallery_Layout_Columns'), not matched bare
        // ('Columns'). Kept in step with ClassAnalyzer's own version of this cross-product.
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['' => 'src/']],
        ]);
        $dispatcher = $this->write('src/Jetpack_Tiled_Gallery.php', '<?php
class Jetpack_Tiled_Gallery {
    private static $talaveras = array( "rectangular", "columns" );
    public function render( $type ) {
        $gallery_class = "Jetpack_Tiled_Gallery_Layout_" . ucfirst( $type );
        return new $gallery_class();
    }
}
');
        $target = $this->write('src/Jetpack_Tiled_Gallery_Layout_Columns.php', '<?php
class Jetpack_Tiled_Gallery_Layout_Columns {
    public function build() {}
}
');

        $names = array_column($this->analyzer->analyze([$dispatcher, $target], $this->tmp), 'name');
        self::assertNotContains('Jetpack_Tiled_Gallery_Layout_Columns.php', $names);
    }

    public function testProjectAutoloadedFileWithNoReferenceIsStillReported(): void
    {
        // The whole point of real-usage-proof: being PSR-4-autoloadable doesn't automatically
        // mean the class is used — nothing anywhere references "Orphan_Service".
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['My_Plugin\\' => 'src/']],
        ]);
        $class = $this->write('src/Orphan_Service.php', '<?php namespace My_Plugin; class Orphan_Service {}');

        $names = array_column($this->analyzer->analyze([$class], $this->tmp), 'name');
        self::assertContains('Orphan_Service.php', $names);
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

    public function testGeneratedComposerPsr4MapExemptsFileWithNoComposerJson(): void
    {
        // Real-world shape (WooCommerce): composer.json is dev-only tooling stripped from the
        // shipped plugin — vendor/ ships anyway, and vendor/composer/autoload_psr4.php is the
        // generated, already-resolved source of truth for what Composer actually autoloads,
        // merged across the whole dependency tree. No composer.json present in this fixture at
        // all.
        $this->writeGeneratedAutoload('autoload_psr4.php', <<<'PHP'
<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Automattic\\WooCommerce\\' => array($baseDir . '/src'),
);
PHP);
        $class = $this->write('src/Service.php', '<?php namespace Automattic\WooCommerce; class Service {}');
        $caller = $this->write('plugin.php', '<?php new Service();');

        self::assertEmpty($this->analyzer->analyze([$class, $caller], $this->tmp));
    }

    public function testGeneratedComposerClassmapExemptsMappedFile(): void
    {
        $this->writeGeneratedAutoload('autoload_classmap.php', <<<'PHP'
<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Legacy_Report' => $baseDir . '/includes/reports/class-legacy-report.php',
);
PHP);
        $legacy = $this->write('includes/reports/class-legacy-report.php', '<?php // legacy report');
        $caller = $this->write('plugin.php', '<?php new Legacy_Report();');

        self::assertEmpty($this->analyzer->analyze([$legacy, $caller], $this->tmp));
    }

    public function testGeneratedComposerMappedFileWithNoReferenceIsStillReported(): void
    {
        $this->writeGeneratedAutoload('autoload_psr4.php', <<<'PHP'
<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Automattic\\WooCommerce\\' => array($baseDir . '/src'),
);
PHP);
        $class = $this->write('src/Orphan_Campaign.php', '<?php namespace Automattic\WooCommerce; class Orphan_Campaign {}');

        $names = array_column($this->analyzer->analyze([$class], $this->tmp), 'name');
        self::assertContains('Orphan_Campaign.php', $names);
    }

    public function testGeneratedComposerAutoloadFilesEntryIsExempt(): void
    {
        $this->writeGeneratedAutoload('autoload_files.php', <<<'PHP'
<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'abc123' => $baseDir . '/src/functions.php',
);
PHP);
        $functions = $this->write('src/functions.php', '<?php // global helper functions');

        self::assertEmpty($this->analyzer->analyze([$functions], $this->tmp));
    }

    public function testGeneratedComposerAutoloadNestedUnderProjectSubdirectoryIsFound(): void
    {
        // Real-world shape (colibri-wp): the theme bundles its OWN, separately Composer-managed
        // dependency tree under `inc/vendor/` instead of the scan root — `inc/vendor/composer/
        // autoload_psr4.php` maps `ColibriWP\Theme\` to `inc/src`, the theme's own genuinely
        // PSR-4-autoloaded class tree, not a dependency. Every one of its 106 classes was a false
        // positive `UnusedFile` before this fix: loadGeneratedComposerAutoload() only ever looked
        // at $rootDir . '/vendor/composer', never a nested vendor/ directory anywhere else.
        $this->write('inc/vendor/composer/autoload_psr4.php', '<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    "ColibriWP\\\\Theme\\\\" => array($baseDir . "/src"),
);
');
        $class = $this->write('inc/src/View.php', '<?php namespace ColibriWP\Theme; class View {}');
        $caller = $this->write('functions.php', '<?php use ColibriWP\Theme\View; new View();');

        self::assertEmpty($this->analyzer->analyze([$class, $caller], $this->tmp));
    }

    public function testGeneratedComposerAutoloadNestedUnderProjectSubdirectoryStillReportsOutsideFiles(): void
    {
        $this->write('inc/vendor/composer/autoload_psr4.php', '<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    "ColibriWP\\\\Theme\\\\" => array($baseDir . "/src"),
);
');
        $orphan = $this->write('inc/orphan.php', '<?php // not under inc/src');

        $names = array_column($this->analyzer->analyze([$orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    public function testFileOutsideGeneratedComposerMapIsStillReported(): void
    {
        $this->writeGeneratedAutoload('autoload_psr4.php', <<<'PHP'
<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Automattic\\WooCommerce\\' => array($baseDir . '/src'),
);
PHP);
        $orphan = $this->write('inc/orphan.php', '<?php // not under the generated psr-4 map');

        $names = array_column($this->analyzer->analyze([$orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
    }

    private function writeGeneratedAutoload(string $filename, string $code): string
    {
        return $this->write('vendor/composer/' . $filename, $code);
    }

    public function testCollectEachLoopResolvesEveryTemplateFile(): void
    {
        // Real-world shape (Sage theme's own functions.php, verbatim): Roots/Acorn's fluent
        // Collection ->each() iterating a plain literal array of theme-file basenames, each one
        // located via WP core's own locate_template() — the loop variable is reassigned as a
        // side effect of the call argument itself (`locate_template($file = "app/{$file}.php",
        // ...)`). Both files are genuinely loaded; neither had any per-file reference before this
        // (the tool had no code recognizing Collection/->each() at all).
        $bootstrap = $this->write('functions.php', '<?php
collect(["setup", "filters"])
    ->each(function ($file) {
        if (! locate_template($file = "app/{$file}.php", true, true)) {
            wp_die($file);
        }
    });
');
        $setup = $this->write('app/setup.php', '<?php // loaded by the collect()->each() loop above');
        $filters = $this->write('app/filters.php', '<?php // loaded by the collect()->each() loop above');
        $orphan = $this->write('app/orphan.php', '<?php // not part of the enumerated domain');

        $names = array_column($this->analyzer->analyze([$bootstrap, $setup, $filters, $orphan], $this->tmp), 'name');
        self::assertNotContains('setup.php', $names);
        self::assertNotContains('filters.php', $names);
        self::assertContains('orphan.php', $names);
    }

    public function testLocateTemplateWithUnresolvableDynamicArgumentDoesNotExemptWholeDirectory(): void
    {
        // Real-world regression surfaced while gap-hunting Sage theme's own functions.php:
        // locate_template() has no "slug-name" variant convention the way get_template_part()
        // does, so a genuinely dynamic argument (here $file is never assigned/bounded anywhere)
        // must not treat its literal prefix as "anything under this directory is reachable" —
        // that would silently hide a genuinely orphaned sibling file under the same prefix.
        $bootstrap = $this->write('functions.php', '<?php
function load_something($file) {
    locate_template("app/{$file}.php", true, true);
}
');
        $orphan = $this->write('app/orphan.php', '<?php // must still be reported unused');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
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

    public function testPathJoinLoopBulkIncludeExemptsProjectRootRelativeDirectory(): void
    {
        // Real-world shape (Contact Form 7's wpcf7_swv_load_rules()): a fixed directory built
        // from a plugin-root constant + literal segment via path_join(), joined per-iteration
        // with a dynamic filename (sprintf('%s.php', $rule) over array_keys() of a configured
        // rule array) — no per-file include()/require() reference for the index to find, the
        // same "directory enumerated into per-file includes" shape glob()/scandir() already
        // cover, just a WP-core path-joining helper instead of a filesystem listing. The
        // constant makes this project-ROOT-relative, not relative to the file that mentions it
        // (WPCF7_PLUGIN_DIR names the plugin's own root, not includes/swv/'s own directory).
        $bootstrap = $this->write('includes/swv/swv.php', '<?php
function wpcf7_swv_available_rules() {
    return array("required" => "RequiredRule", "email" => "EmailRule");
}
function wpcf7_swv_load_rules() {
    foreach (array_keys(wpcf7_swv_available_rules()) as $rule) {
        $file = sprintf("%s.php", $rule);
        $path = path_join(WPCF7_PLUGIN_DIR . "/includes/swv/php/rules", $file);
        if (file_exists($path)) {
            include_once $path;
        }
    }
}
');
        $rule = $this->write('includes/swv/php/rules/required.php', '<?php // loaded by the path_join loop above');
        $unrelated = $this->write('includes/swv/orphan.php', '<?php // outside the exempted directory');

        $names = array_column($this->analyzer->analyze([$bootstrap, $rule, $unrelated], $this->tmp), 'name');
        self::assertNotContains('required.php', $names);
        self::assertContains('orphan.php', $names);
    }

    public function testPathJoinWithLiteralSecondArgumentIsNotTreatedAsABulkLoader(): void
    {
        // path_join() with a plain literal second argument is an ordinary single reference, not
        // a per-iteration bulk loader — must not exempt the whole directory.
        $bootstrap = $this->write('includes/functions.php', '<?php
$path = path_join(WPCF7_PLUGIN_DIR . "/includes/swv/php/rules", "required.php");
if (file_exists($path)) {
    include_once $path;
}
');
        $orphan = $this->write('includes/swv/php/rules/orphan.php', '<?php // not proven reachable');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');
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

    public function testInterpolatedStringWithBoundedLoopVarExemptsEnumeratedFiles(): void
    {
        // Real-world finding (Astra theme): "{$icons_dir}/icons-v6-{$i}.php" inside a bounded
        // for loop, where $icons_dir is derived from a WP-core call this analyzer can't resolve.
        // Only the basename ("icons-v6-N.php") needs to match — see PhpTokenParser::
        // resolveInterpolatedLoopSuffixPath()'s own docblock for why the unresolved directory
        // prefix is deliberately discarded rather than guessed at.
        $bootstrap = $this->write('inc/core/common-functions.php', '<?php
function astra_get_logo_svg_icons_array() {
    $icons_dir = ASTRA_THEME_DIR . "assets/svg/logo-svg-icons";
    for ( $i = 0; $i < 4; $i++ ) {
        $file = "{$icons_dir}/icons-v6-{$i}.php";
        if ( file_exists( $file ) ) {
            include_once $file;
        }
    }
}
');
        $icon0 = $this->write('assets/svg/logo-svg-icons/icons-v6-0.php', '<?php // loaded by the loop above');
        $icon3 = $this->write('assets/svg/logo-svg-icons/icons-v6-3.php', '<?php // loaded by the loop above');
        $icon4 = $this->write('assets/svg/logo-svg-icons/icons-v6-4.php', '<?php // loop never reaches 4 — genuinely unused');

        $names = array_column($this->analyzer->analyze([$bootstrap, $icon0, $icon3, $icon4], $this->tmp), 'name');
        self::assertNotContains('icons-v6-0.php', $names);
        self::assertNotContains('icons-v6-3.php', $names);
        self::assertContains('icons-v6-4.php', $names);
    }

    public function testFileContainingOnlyAFullyExemptBaseClassIsNotFlaggedUnused(): void
    {
        // Real-world finding (Sage theme, a Roots Acorn theme): app/View/Composers/*.php each
        // declare a single `class X extends Composer` (Roots\Acorn\View\Composer — already
        // whole-class-exempted by ClassAnalyzer::isFullyExemptClass, discovered by Acorn's own
        // filesystem convention, never referenced by name anywhere in the theme). --type=classes
        // already correctly showed these as used, but --type=files had no equivalent exemption
        // at all and still flagged the file itself as unused — the class-level exemption and the
        // file-level check had zero cross-talk.
        $file = $this->write('inc/app.php', '<?php
use Roots\Acorn\View\Composer;

class App extends Composer {
    public function siteName() {}
}
');
        $names = array_column($this->analyzer->analyze([$file], $this->tmp), 'name');
        self::assertNotContains('app.php', $names);
    }

    public function testFileMixingAFullyExemptClassWithAnUnrelatedClassIsNotExempted(): void
    {
        // A file isn't safe to exempt wholesale just because ONE of its classes is fully
        // exempt — if it also declares something else, that something else might genuinely need
        // a real usage reference, so the file itself still needs to be checked normally.
        $file = $this->write('inc/app.php', '<?php
use Roots\Acorn\View\Composer;

class App extends Composer {
    public function siteName() {}
}

class Not_Actually_Exempt {
    public function init() {}
}
');
        $names = array_column($this->analyzer->analyze([$file], $this->tmp), 'name');
        self::assertContains('app.php', $names);
    }

    public function testDynamicMiddleSegmentRequireWithFilenamePrefixTrimsToRealDirectory(): void
    {
        // Real-world regression (Sydney theme): `require get_template_directory() .
        // '/inc/dashboard/html-' . $tab_id . '.php';` — unlike Kadence's own
        // '/inc/customizer/options/' . $key . '-options.php' (a clean directory boundary, the
        // literal already ends in '/'), this literal mashes the real directory ("inc/dashboard/")
        // together with a filename *prefix* ("html-") that isn't a subdirectory at all. Trusting
        // "inc/dashboard/html-" itself as a directory can never match a real file, silently
        // defeating the exemption entirely and false-flagging every html-*.php tab partial.
        $bootstrap = $this->write('inc/dashboard/class-dashboard.php', '<?php
foreach ( $tabs as $tab_id ) {
    require get_template_directory() . "/inc/dashboard/html-" . $tab_id . ".php";
}
');
        $tab = $this->write('inc/dashboard/html-general.php', '<?php // loaded by the loop above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $tab], $this->tmp), 'name');
        self::assertNotContains('html-general.php', $names);
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

    public function testInlineCommentRightAfterCallOpenParenDoesNotHideTheLiteralArgument(): void
    {
        // Real-world regression (WPForms): `echo wpforms_render( // phpcs:ignore Standard.Rule
        //     'education/admin/did-you-know', [], true
        // );` — an inline phpcs-ignore comment directly after the opening `(`, before the first
        // argument, left a stray comment token in front of the literal. The call-argument
        // extractor feeding the literal-path-propagation graph only stripped whitespace, not
        // comments, so the exact-one-token match for "this argument is a plain string literal"
        // silently failed and the whole wrapper chain (wpforms_render() -> Templates::get_html()
        // -> Templates::include_html() -> require) never resolved. Needs the real multi-hop
        // shape — including each hop's own `apply_filters()`-wrapped assignment — to reproduce;
        // a single-hop wrapper resolves regardless of this bug via a different, simpler path.
        $wrapper = $this->write('inc/render.php', '<?php
class Templates {
    public static function locate( $template_name ) {
        $located = "";
        foreach ( [ dirname( __FILE__ ) . "/templates/" ] as $template_path ) {
            if ( file_exists( $template_path . $template_name ) ) {
                $located = $template_path . $template_name;
                break;
            }
        }
        return apply_filters( "templates_locate", $located, $template_name );
    }
    public static function include_html( $template_name ) {
        $template_name .= ".php";
        $located = apply_filters( "templates_include_html_located", self::locate( $template_name ), $template_name );
        if ( empty( $located ) ) {
            return;
        }
        require $located;
    }
    public static function get_html( $template_name ) {
        ob_start();
        self::include_html( $template_name );
        return ob_get_clean();
    }
}
function wpforms_render( $template_name ) {
    return Templates::get_html( $template_name );
}
');
        $caller = $this->write('inc/education.php', '<?php
echo wpforms_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    "admin/did-you-know"
);
');
        $template = $this->write('inc/templates/admin/did-you-know.php', '<?php // reachable via wpforms_render()');

        $names = array_column($this->analyzer->analyze([$wrapper, $caller, $template], $this->tmp), 'name');
        self::assertNotContains('did-you-know.php', $names);
    }

    public function testScopedCallWithTernaryTrackedVariableArgumentResolvesParamSuffixTemplate(): void
    {
        // Real-world finding (wp-nested-pages): `$row_view = ( $cond ) ? 'partials/row' :
        // 'partials/row-link'; include( Helpers::view( $row_view ) );` where Helpers::view()
        // (defined in a different file) builds its return path from an unresolvable prefix
        // followed by its own parameter and a literal suffix.
        $caller = $this->write('app/Entities/Listing.php', '<?php
class Listing {
    public function render() {
        $row_view = ( $this->cond ) ? "partials/row" : "partials/row-link";
        include( Helpers::view( $row_view ) );
    }
}
');
        $helper = $this->write('app/Helpers.php', '<?php
class Helpers {
    public static function view( $file ) {
        return dirname( __FILE__ ) . "/Views/" . $file . ".php";
    }
}
');
        $row = $this->write('Views/partials/row.php', '<?php // reachable via Helpers::view()');
        $rowLink = $this->write('Views/partials/row-link.php', '<?php // reachable via Helpers::view()');

        $names = array_column($this->analyzer->analyze([$caller, $helper, $row, $rowLink], $this->tmp), 'name');
        self::assertNotContains('row.php', $names);
        self::assertNotContains('row-link.php', $names);
    }

    public function testScopedCallWithSiblingComparisonTrackedVariableConcatenatedIntoArgumentResolves(): void
    {
        // Real-world finding (wp-nested-pages): `if ( $tab == 'general' ) ...; if ( $tab ==
        // 'posttypes' ) ...; include( NestedPages\Helpers::view( 'settings/settings-' . $tab ) );`
        // — $tab's domain comes from being tested against literals in sibling `if` conditions.
        $caller = $this->write('app/Views/settings/settings.php', '<?php
if ( $tab == "general" ) { echo 1; }
if ( $tab == "posttypes" ) { echo 2; }
include( NestedPages\Helpers::view( "settings/settings-" . $tab ) );
');
        $helper = $this->write('app/Helpers.php', '<?php
namespace NestedPages;
class Helpers {
    public static function view( $file ) {
        return dirname( __FILE__ ) . "/Views/" . $file . ".php";
    }
}
');
        $general = $this->write('Views/settings/settings-general.php', '<?php // reachable via Helpers::view()');
        $posttypes = $this->write('Views/settings/settings-posttypes.php', '<?php // reachable via Helpers::view()');

        $names = array_column($this->analyzer->analyze([$caller, $helper, $general, $posttypes], $this->tmp), 'name');
        self::assertNotContains('settings-general.php', $names);
        self::assertNotContains('settings-posttypes.php', $names);
    }

    public function testMultiHopLiteralWrapperPathsReferencePositionalAndKeyedFiles(): void
    {
        // Blocksy's options loader receives a positional slug, while its dynamic-styles loader
        // receives the literal through `$args['name']`. Both build a fixed path locally and pass
        // it to a separate require helper, so neither direct include-ref collection nor directory
        // bulk-loader detection can identify the individual files.
        $bootstrap = $this->write('functions.php', '<?php
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
        $option = $this->write('inc/options/general/buttons.php', '<?php // loaded through positional wrapper');
        $style = $this->write('inc/styles/global-inline.php', '<?php // loaded through keyed wrapper');
        $orphan = $this->write('inc/orphan.php', '<?php // no fixed path reaches this');

        $names = array_column($this->analyzer->analyze([$bootstrap, $option, $style, $orphan], $this->tmp), 'name');

        self::assertNotContains('buttons.php', $names);
        self::assertNotContains('global-inline.php', $names);
        self::assertContains('orphan.php', $names);
    }

    public function testStaticFactoryConstructorPropertyRenderViewLoaderReferencesFile(): void
    {
        // Real-world shape (Wordfence's `wfView`): a static factory forwards its argument into
        // `new self(...)`, the constructor stores it as a property, and a *different* method
        // (`render()`, called later, no arguments of its own) builds the actual path and
        // includes it. Three separate hops, none of them a plain parameter-to-sink wrapper:
        // factory -> constructor (via `new self()`), constructor -> property (`$this->view =
        // $view`), property -> sink (`render()`'s own concatenation, reading `$this->view`
        // through a transparent `preg_replace()` wrap and appending a property with a scalar
        // literal default, `.php`). `$this->view_path`'s own opaque `WORDFENCE_PATH` root is
        // never resolved and must not block the rest of the chain.
        $bootstrap = $this->write('lib/wfView.php', '<?php
class wfView {
    protected $view_path;
    protected $view_file_extension = ".php";
    protected $view;
    protected $data;

    public static function create($view, $data = array()) {
        return new self($view, $data);
    }

    public function __construct($view, $data = array()) {
        $this->view_path = WORDFENCE_PATH . "views";
        $this->view = $view;
        $this->data = $data;
    }

    public function render() {
        $view = preg_replace("/\\.{2,}/", ".", $this->view);
        $view_path = $this->view_path . "/" . $view . $this->view_file_extension;
        if (!file_exists($view_path)) {
            throw new Exception("missing view");
        }
        include $view_path;
        return "";
    }
}
echo wfView::create("scanner/text/issue-base", array("textOutput" => "x"))->render();
');
        $view = $this->write('views/scanner/text/issue-base.php', '<?php // loaded through wfView::create()');
        $orphan = $this->write('views/scanner/text/orphan.php', '<?php // no fixed path reaches this');

        $names = array_column($this->analyzer->analyze([$bootstrap, $view, $orphan], $this->tmp), 'name');

        self::assertNotContains('issue-base.php', $names);
        self::assertContains('orphan.php', $names);

        // FileAnalyzer's own basename-and-stem index would still match 'issue-base' even without
        // the resolved path's ".php" suffix (see its own matching code), which would let this
        // test pass even if the property-scalar-literal-default resolution for
        // $view_file_extension were broken. Check the resolver's own raw output directly so that
        // piece is genuinely covered, not just accidentally subsumed by the coarser fallback.
        $resolved = LiteralPathPropagationResolver::resolve([(new PhpTokenParser())->parse($bootstrap)]);
        self::assertContains('/scanner/text/issue-base.php', $resolved);
    }

    public function testSelfReassigningBareWrapperCallWithCurlyInterpolatedArgumentResolvesLiteralPath(): void
    {
        // Real-world shape (Advanced Custom Fields): acf_get_view()'s own parameter is
        // conditionally reassigned to the result of a SEPARATE bare (non-scoped) wrapper function,
        // acf_get_path(), whose sole argument is the SAME parameter fed back through curly-brace
        // string interpolation ("...{$view_path}..."), not concatenation. acf_get_path() itself
        // strips a leading slash via ltrim() before prefixing an unresolvable path constant.
        // Neither the self-reassignment through a bare (unscoped) call, nor the curly-brace
        // interpolated argument, were previously recognized at all — the whole chain silently
        // failed to resolve for every call site (26/26 real ACF findings before this fix).
        $bootstrap = $this->write('includes/api/api-helpers.php', '<?php
function acf_get_view( $view_path = "", $view_args = array() ) {
    if ( substr( $view_path, -4 ) !== ".php" ) {
        $view_path = acf_get_path( "includes/admin/views/{$view_path}.php" );
    }
    if ( file_exists( $view_path ) ) {
        include $view_path;
    }
}
function acf_get_path( $filename = "" ) {
    return ACF_PATH . ltrim( $filename, "/" );
}
acf_get_view( "global/header" );
');
        $view = $this->write('includes/admin/views/global/header.php', '<?php // loaded through acf_get_view()');
        $orphan = $this->write('includes/admin/views/global/orphan.php', '<?php // no fixed path reaches this');

        $names = array_column($this->analyzer->analyze([$bootstrap, $view, $orphan], $this->tmp), 'name');

        self::assertNotContains('header.php', $names);
        self::assertContains('orphan.php', $names);
    }

    public function testRepeatedParameterOccurrenceInConcatenatedModuleLoaderResolvesLiteralPath(): void
    {
        // Real-world shape (Contact Form 7): load_modules() calls self::load_module('acceptance')
        // at many literal call sites; load_module($mod) hands wpcf7_include_module_file() a
        // concatenation where $mod appears TWICE ($mod . '/' . $mod . '.php'), building
        // "acceptance/acceptance.php". resolveLiteralPathExpressionTokens's own one-dynamic-term
        // rule previously bailed outright the moment it saw the same variable a second time,
        // leaving every module file a false-positive "unused file".
        $bootstrap = $this->write('load.php', '<?php
class WPCF7 {
    public static function load_modules() {
        self::load_module( "acceptance" );
    }
    public static function load_module( $mod ) {
        wpcf7_include_module_file( $mod . "/" . $mod . ".php" );
    }
}
function wpcf7_include_module_file( $path ) {
    include_once WPCF7_PLUGIN_MODULES_DIR . $path;
}
');
        $module = $this->write('modules/acceptance/acceptance.php', '<?php // loaded through load_module()');
        $orphan = $this->write('modules/acceptance/orphan.php', '<?php // no fixed path reaches this');

        $names = array_column($this->analyzer->analyze([$bootstrap, $module, $orphan], $this->tmp), 'name');

        self::assertNotContains('acceptance.php', $names);
        self::assertContains('orphan.php', $names);

        $resolved = LiteralPathPropagationResolver::resolve([(new PhpTokenParser())->parse($bootstrap)]);
        self::assertContains('acceptance/acceptance.php', $resolved);
    }

    public function testInArrayGuardedDynamicViewDispatchResolvesEveryDomainValue(): void
    {
        // Real-world shape (Wordfence): `views/diagnostics/text.php` does `if (!in_array(
        // $i['type'], $issueTypes)) { continue; }` (where `$issueTypes = wfIssues::
        // validIssueTypes()`, DEFINED IN A SEPARATE FILE — cross-file resolution, same as
        // functionArrayReturns' own established timing) right before `wfView::create('scanner/
        // text/issue-' . $i['type'], ...)`. `$i['type']` is a database-sourced array's own key
        // access, never itself a tracked node — but the in_array() guard bounds its domain to
        // wfIssues::validIssueTypes()'s own literal array return. Every value in that domain
        // must become its own resolved file reference. 12/12 real findings before this fix.
        $lib = $this->write('lib/wfIssues.php', '<?php
class wfIssues {
    public static function validIssueTypes() {
        return array( "checkGSB", "timelimit" );
    }
}
');
        $wfView = $this->write('lib/wfView.php', '<?php
class wfView {
    protected $view;
    public static function create($view, $data = array()) {
        return new self($view, $data);
    }
    public function __construct($view, $data = array()) {
        $this->view = $view;
    }
    public function render() {
        $view_path = "views/" . $this->view . ".php";
        include $view_path;
        return "";
    }
}
');
        $dispatch = $this->write('views/diagnostics/text.php', '<?php
$issueTypes = wfIssues::validIssueTypes();
foreach ( $issues["new"] as $i ) {
    if ( !in_array( $i["type"], $issueTypes ) ) {
        continue;
    }
    wfView::create( "scanner/text/issue-" . $i["type"], array() )->render();
}
');
        $checkGsb = $this->write('views/scanner/text/issue-checkGSB.php', '<?php // reached via checkGSB domain value');
        $timelimit = $this->write('views/scanner/text/issue-timelimit.php', '<?php // reached via timelimit domain value');
        $orphan = $this->write('views/scanner/text/issue-orphan.php', '<?php // not in validIssueTypes(), still dead');

        $names = array_column(
            $this->analyzer->analyze([$lib, $wfView, $dispatch, $checkGsb, $timelimit, $orphan], $this->tmp),
            'name',
        );

        self::assertNotContains('issue-checkGSB.php', $names);
        self::assertNotContains('issue-timelimit.php', $names);
        self::assertContains('issue-orphan.php', $names);
    }

    public function testBareParameterPassedToRequireDoesNotBecomeAFileReference(): void
    {
        // A literal argument alone is insufficient: without a fixed prefix/suffix construction,
        // this direct parameter-to-require wrapper must not hide a same-named orphan file.
        $bootstrap = $this->write('functions.php', '<?php
function include_as_is( $filename ) {
    require $filename;
}
include_as_is( "inc/orphan" );
');
        $orphan = $this->write('inc/orphan.php', '<?php // not proven reachable');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');

        self::assertContains('orphan.php', $names);
    }

    public function testUnknownPathBaseDoesNotMakeAFileReference(): void
    {
        // The fixed ".php" suffix cannot prove this project's inc/orphan.php is the target:
        // $base is runtime data and may name any external directory.
        $bootstrap = $this->write('functions.php', '<?php
function include_file( $name ) {
    $path = $base . $name . ".php";
    require $path;
}
include_file( "orphan" );
');
        $orphan = $this->write('inc/orphan.php', '<?php // not proven reachable');

        $names = array_column($this->analyzer->analyze([$bootstrap, $orphan], $this->tmp), 'name');

        self::assertContains('orphan.php', $names);
    }

    public function testStaticAndKnownInstanceWrapperCallsReferenceFiles(): void
    {
        // The path-forming method is reached through three already-resolved method forms:
        // late-static forwarding, a type-hinted object, and an object assigned from `new`.
        // Each receiver has an exact class, unlike an arbitrary `$object->load()` call.
        $bootstrap = $this->write('functions.php', '<?php
function require_file( $file ) {
    require $file;
}
class PathLoader {
    public static function load( $slug ) {
        $file = get_template_directory() . "/inc/" . $slug . ".php";
        require_file( $file );
    }
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
        $static = $this->write('inc/static.php', '<?php // loaded through static::');
        $typed = $this->write('inc/typed.php', '<?php // loaded through typed parameter');
        $new = $this->write('inc/new.php', '<?php // loaded through known new instance');
        $orphan = $this->write('inc/orphan.php', '<?php // not referenced');

        $names = array_column($this->analyzer->analyze([$bootstrap, $static, $typed, $new, $orphan], $this->tmp), 'name');

        self::assertNotContains('static.php', $names);
        self::assertNotContains('typed.php', $names);
        self::assertNotContains('new.php', $names);
        self::assertContains('orphan.php', $names);
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

    public function testCrossFileBulkDirectoryLoaderExemptsTargetDirectory(): void
    {
        // Real-world finding (Flynt theme): functions.php calls FileLoader::loadPhpFiles('inc'),
        // and loadPhpFiles() itself — declared in a completely different file — walks that
        // directory and require_once's every PHP file it finds from inside a nested closure.
        // Neither glob()/scandir() detection nor the dynamic-middle-segment require detection
        // can see this: there's no glob()/scandir() call anywhere, and the literal directory
        // name and the require that consumes it live in two separate files, connected only by
        // an ordinary method call.
        $bootstrap = $this->write('functions.php', '<?php
FileLoader::loadPhpFiles( "inc" );
');
        $fileLoader = $this->write('lib/Utils/FileLoader.php', '<?php
class FileLoader {
    public static function loadPhpFiles( $dir ) {
        static::iterateDir( $dir, function ( $file ) {
            require_once $file;
        } );
    }
}
');
        $included = $this->write('inc/setup.php', '<?php // loaded by FileLoader::loadPhpFiles above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $fileLoader, $included], $this->tmp), 'name');
        self::assertNotContains('setup.php', $names);
    }

    public function testBulkDirectoryLoaderCallDoesNotExemptWhenCalleeHasNoInclude(): void
    {
        // The callee method exists and is called the same way, but its own body never contains
        // a require/include anywhere — no real bulk-load signal, so the directory must still be
        // scanned normally.
        $bootstrap = $this->write('functions.php', '<?php
FileLoader::loadPhpFiles( "inc" );
');
        $fileLoader = $this->write('lib/Utils/FileLoader.php', '<?php
class FileLoader {
    public static function loadPhpFiles( $dir ) {
        static::logCall( $dir );
    }
}
');
        $orphan = $this->write('inc/orphan.php', '<?php // not actually bulk-loaded by anything');

        $names = array_column($this->analyzer->analyze([$bootstrap, $fileLoader, $orphan], $this->tmp), 'name');
        self::assertContains('orphan.php', $names);
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

    public function testSplAutoloadRegisterAscendsAncestorDirectoryComputedViaPluginDirPathDirname(): void
    {
        // Real-world finding (Broken Link Checker): the autoloader bootstrap lives in
        // core/utils/, but computes its base path via plugin_dir_path(dirname(__DIR__)) —
        // climbing 2 levels above its own directory (utils -> core -> plugin root) before
        // descending back into the class-name-derived subpath. The prior "caller's own
        // directory tree" default (app/core/utils/ here) would miss app/module/foo.php entirely.
        $bootstrap = $this->write('app/core/utils/autoloader.php', '<?php
function my_autoloader( $class_name ) {
    $plugin_path = plugin_dir_path( dirname( __DIR__ ) );
    require $plugin_path . strtolower( $class_name ) . ".php";
}
spl_autoload_register( "my_autoloader" );
');
        $component = $this->write('app/module/foo.php', '<?php // loaded by the ascended autoloader above');
        $unrelated = $this->write('admin/orphan.php', '<?php // outside the ascended scope entirely');

        $names = array_column($this->analyzer->analyze([$bootstrap, $component, $unrelated], $this->tmp), 'name');
        self::assertNotContains('foo.php', $names);
        self::assertContains('orphan.php', $names);
    }

    public function testSplAutoloadRegisterAscendsWithNestedDirnameCalls(): void
    {
        // Same 2-level climb as above, spelled as nested dirname(dirname(__DIR__)) instead of
        // plugin_dir_path() — confirms the recursive nesting itself is resolved, not just the
        // plugin_dir_path() single-wrap shape.
        $bootstrap = $this->write('app/core/utils/autoloader.php', '<?php
function my_autoloader( $class_name ) {
    $plugin_path = dirname( dirname( __DIR__ ) );
    require $plugin_path . "/" . strtolower( $class_name ) . ".php";
}
spl_autoload_register( "my_autoloader" );
');
        $component = $this->write('app/module/foo.php', '<?php // loaded by the ascended autoloader above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $component], $this->tmp), 'name');
        self::assertNotContains('foo.php', $names);
    }

    public function testSplAutoloadRegisterAscendsWithDirnameLevelsArgument(): void
    {
        // Same 2-level climb again, via dirname()'s own PHP 7.0+ 2-arg form (`dirname($path,
        // $levels)`) instead of nesting/plugin_dir_path().
        $bootstrap = $this->write('app/core/utils/autoloader.php', '<?php
function my_autoloader( $class_name ) {
    $plugin_path = dirname( __DIR__, 2 );
    require $plugin_path . "/" . strtolower( $class_name ) . ".php";
}
spl_autoload_register( "my_autoloader" );
');
        $component = $this->write('app/module/foo.php', '<?php // loaded by the ascended autoloader above');

        $names = array_column($this->analyzer->analyze([$bootstrap, $component], $this->tmp), 'name');
        self::assertNotContains('foo.php', $names);
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
