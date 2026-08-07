<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Composer;

use PHPUnit\Framework\TestCase;
use WpSpecter\Composer\ComposerProjectDetector;
use WpSpecter\Detector\WpModeDetector;

final class ComposerProjectDetectorTest extends TestCase
{
    private string $tmp;
    private ComposerProjectDetector $detector;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-composer-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->detector = new ComposerProjectDetector(new WpModeDetector());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testFindProjectRootWalksUpward(): void
    {
        $this->writeJson('composer.json', ['extra' => ['installer-paths' => []]]);
        $nested = $this->tmp . '/app/themes/some-theme';
        mkdir($nested, 0755, true);

        self::assertSame($this->tmp, $this->detector->findProjectRoot($nested));
    }

    public function testFindProjectRootReturnsNullWithoutComposerJson(): void
    {
        self::assertNull($this->detector->findProjectRoot($this->tmp));
    }

    public function testDiscoversCustomThemeAndPlugin(): void
    {
        $this->writeInstallerPathsComposerJson();
        $this->writeTheme('app/themes/my-theme');
        $this->writePlugin('app/plugins/my-plugin');

        $targets = $this->detector->discoverCustomTargets($this->tmp);

        $names = array_column($targets, 'name');
        self::assertContains('my-theme', $names);
        self::assertContains('my-plugin', $names);
    }

    public function testExcludesVendorInstalledPackage(): void
    {
        $this->writeInstallerPathsComposerJson();
        $this->writeTheme('app/themes/my-theme');
        $this->writeTheme('app/themes/vendor-theme'); // present on disk but composer-tracked

        mkdir($this->tmp . '/vendor/composer', 0755, true);
        $this->writeJson('vendor/composer/installed.json', [
            'packages' => [[
                'name' => 'wpackagist-theme/vendor-theme',
                'type' => 'wordpress-theme',
                'install-path' => '../../app/themes/vendor-theme',
            ]],
        ]);

        $targets = $this->detector->discoverCustomTargets($this->tmp);
        $names = array_column($targets, 'name');

        self::assertContains('my-theme', $names);
        self::assertNotContains('vendor-theme', $names);
    }

    public function testLooseMuPluginFileBecomesOwnPseudoTarget(): void
    {
        $this->writeJson('composer.json', [
            'extra' => ['installer-paths' => [
                'app/mu-plugins/{$name}/' => ['type:wordpress-muplugin'],
            ]],
        ]);
        mkdir($this->tmp . '/app/mu-plugins', 0755, true);
        file_put_contents($this->tmp . '/app/mu-plugins/loader.php', '<?php // loose file');

        $targets = $this->detector->discoverCustomTargets($this->tmp);

        self::assertCount(1, $targets);
        self::assertSame('mu-plugins', $targets[0]->name);
        self::assertNull($targets[0]->mode);
        self::assertSame([$this->tmp . '/app/mu-plugins/loader.php'], $targets[0]->files);
    }

    public function testReturnsEmptyWithoutInstallerPaths(): void
    {
        $this->writeJson('composer.json', ['require' => ['php' => '>=8.1']]);

        self::assertEmpty($this->detector->discoverCustomTargets($this->tmp));
    }

    private function writeInstallerPathsComposerJson(): void
    {
        $this->writeJson('composer.json', [
            'extra' => ['installer-paths' => [
                'app/plugins/{$name}/' => ['type:wordpress-plugin'],
                'app/themes/{$name}/' => ['type:wordpress-theme'],
            ]],
        ]);
    }

    private function writeTheme(string $relative): void
    {
        $dir = $this->tmp . '/' . $relative;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/style.css', "/*\nTheme Name: Test\n*/");
    }

    private function writePlugin(string $relative): void
    {
        $dir = $this->tmp . '/' . $relative;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/plugin.php', "<?php\n/*\nPlugin Name: Test\n*/");
    }

    /** @param array<mixed> $data */
    private function writeJson(string $relative, array $data): void
    {
        $path = $this->tmp . '/' . $relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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
