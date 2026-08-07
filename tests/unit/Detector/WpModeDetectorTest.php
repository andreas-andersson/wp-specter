<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Detector;

use PHPUnit\Framework\TestCase;
use WpSpecter\Detector\WpModeDetector;
use WpSpecter\Enum\WpMode;

final class WpModeDetectorTest extends TestCase
{
    private string $tmp;
    private WpModeDetector $detector;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-mode-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->detector = new WpModeDetector();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testDetectsBlockThemeViaThemeJson(): void
    {
        file_put_contents($this->tmp . '/theme.json', '{}');

        self::assertSame(WpMode::Block, $this->detector->detect($this->tmp));
    }

    public function testDetectsBlockThemeViaBlockJson(): void
    {
        mkdir($this->tmp . '/blocks/my-block', 0755, true);
        file_put_contents($this->tmp . '/blocks/my-block/block.json', '{}');

        self::assertSame(WpMode::Block, $this->detector->detect($this->tmp));
    }

    public function testDetectsClassicTheme(): void
    {
        file_put_contents($this->tmp . '/style.css', "/*\nTheme Name: My Theme\n*/");

        self::assertSame(WpMode::Classic, $this->detector->detect($this->tmp));
    }

    public function testDetectsPlugin(): void
    {
        file_put_contents($this->tmp . '/my-plugin.php', "<?php\n// Plugin Name: My Plugin\n");

        self::assertSame(WpMode::Plugin, $this->detector->detect($this->tmp));
    }

    public function testDetectsHybridWhenThemeJsonAndFunctionsPhpCoexist(): void
    {
        file_put_contents($this->tmp . '/theme.json', '{}');
        file_put_contents($this->tmp . '/functions.php', '<?php');

        self::assertSame(WpMode::Hybrid, $this->detector->detect($this->tmp));
    }

    public function testPluginTakesPrecedenceOverTheme(): void
    {
        file_put_contents($this->tmp . '/style.css', "/*\nTheme Name: My Theme\n*/");
        file_put_contents($this->tmp . '/my-plugin.php', "<?php\n// Plugin Name: My Plugin\n");

        self::assertSame(WpMode::Plugin, $this->detector->detect($this->tmp));
    }

    public function testReturnsNullForUnknownLayout(): void
    {
        file_put_contents($this->tmp . '/random.php', '<?php');

        self::assertNull($this->detector->detect($this->tmp));
    }

    // ── isHierarchyTemplate ────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('exactHierarchyTemplates')]
    public function testExactHierarchyTemplatesRecognised(string $basename): void
    {
        self::assertTrue($this->detector->isHierarchyTemplate($basename));
    }

    /** @return array<string, array{string}> */
    public static function exactHierarchyTemplates(): array
    {
        return [
            'index'          => ['index'],
            'single'         => ['single'],
            'archive'        => ['archive'],
            'page'           => ['page'],
            'category'       => ['category'],
            'tag'            => ['tag'],
            'author'         => ['author'],
            'search'         => ['search'],
            'searchform'     => ['searchform'],
            '404'            => ['404'],
            'front-page'     => ['front-page'],
            'home'           => ['home'],
            'header'         => ['header'],
            'footer'         => ['footer'],
            'sidebar'        => ['sidebar'],
            'functions'      => ['functions'],
            'comments'       => ['comments'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prefixHierarchyTemplates')]
    public function testPrefixHierarchyTemplatesRecognised(string $basename): void
    {
        self::assertTrue($this->detector->isHierarchyTemplate($basename));
    }

    /** @return array<string, array{string}> */
    public static function prefixHierarchyTemplates(): array
    {
        return [
            'single-post'       => ['single-post'],
            'single-event'      => ['single-event'],
            'archive-event'     => ['archive-event'],
            'page-about'        => ['page-about'],
            'page-slug'         => ['page-slug'],
            'taxonomy-location' => ['taxonomy-location'],
            'category-news'     => ['category-news'],
            'tag-featured'      => ['tag-featured'],
            'author-admin'      => ['author-admin'],
            'header-shop'       => ['header-shop'],
            'header-kiosk'      => ['header-kiosk'],
            'footer-checkout'   => ['footer-checkout'],
            'sidebar-woo'       => ['sidebar-woo'],
        ];
    }

    public function testCustomTemplateNotRecognisedAsHierarchy(): void
    {
        self::assertFalse($this->detector->isHierarchyTemplate('my-custom-template'));
        self::assertFalse($this->detector->isHierarchyTemplate('hero-banner'));
        self::assertFalse($this->detector->isHierarchyTemplate('card'));
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
