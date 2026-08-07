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
