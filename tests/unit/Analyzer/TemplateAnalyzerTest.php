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

    private function touch(string $relative): string
    {
        $path = $this->tmp . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, '<?php');
        return $path;
    }

    private function writeCode(string $code): string
    {
        // Keep non-template PHP files in a subdirectory that won't be scanned as templates
        $dir = $this->tmp . '/src';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/test_' . uniqid() . '.php';
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
