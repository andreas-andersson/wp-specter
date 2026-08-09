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
        self::assertSame('inc/orphan', $findings[0]->name);
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
        self::assertNotContains('acf-blocks/hero', $names);
    }

    public function testDynamicGetTemplatePartSuffixMatchesSiblingFiles(): void
    {
        $caller = $this->write('inc/shortcodes/card-list.php', '<?php
$variant = "lg";
get_template_part("inc/shortcodes/variants/$variant", null);
');
        $variant = $this->write('inc/shortcodes/variants/lg.php', '<?php // variant');

        $names = array_column($this->analyzer->analyze([$caller, $variant], $this->tmp), 'name');
        self::assertNotContains('inc/shortcodes/variants/lg', $names);
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
        self::assertContains('inc/orphan', $names);
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
        self::assertContains('src-legacy/Old', $names);
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
        self::assertNotContains('inc/module', $names);
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
        self::assertNotContains('inc/module', $names);
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
        self::assertContains('assets/orphan', $names);
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
        self::assertContains('inc-legacy/orphan', $names);
    }

    public function testNoComposerJsonDoesNotBreakAnalysis(): void
    {
        $orphan = $this->write('inc/orphan.php', '<?php // no composer.json in this fixture');

        $names = array_column($this->analyzer->analyze([$orphan], $this->tmp), 'name');
        self::assertContains('inc/orphan', $names);
    }

    public function testMalformedComposerJsonIsIgnoredGracefully(): void
    {
        $this->write('composer.json', '{not valid json');
        $orphan = $this->write('inc/orphan.php', '<?php // still reported, malformed composer.json ignored');

        $names = array_column($this->analyzer->analyze([$orphan], $this->tmp), 'name');
        self::assertContains('inc/orphan', $names);
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
