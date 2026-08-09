<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Support;

use PHPUnit\Framework\TestCase;
use WpSpecter\Support\GlobExpander;

final class GlobExpanderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-globexpander-' . uniqid();
        mkdir($this->tmp . '/plugins/custom-analytics', 0755, true);
        mkdir($this->tmp . '/plugins/custom-seo', 0755, true);
        mkdir($this->tmp . '/plugins/vendor-thing', 0755, true);
        file_put_contents($this->tmp . '/plugins/not-a-dir.txt', 'x');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testContainsWildcardDetectsAsterisk(): void
    {
        self::assertTrue(GlobExpander::containsWildcard('plugins/custom-*'));
    }

    public function testContainsWildcardDetectsQuestionMarkAndBrackets(): void
    {
        self::assertTrue(GlobExpander::containsWildcard('plugins/custom-?'));
        self::assertTrue(GlobExpander::containsWildcard('plugins/custom-[ab]'));
    }

    public function testContainsWildcardFalseForPlainPath(): void
    {
        self::assertFalse(GlobExpander::containsWildcard('plugins/custom-analytics'));
    }

    public function testExpandDirsMatchesOnlyDirectoriesUnderPrefix(): void
    {
        $matches = GlobExpander::expandDirs($this->tmp . '/plugins/custom-*');

        self::assertSame(
            [$this->tmp . '/plugins/custom-analytics', $this->tmp . '/plugins/custom-seo'],
            $matches,
        );
    }

    public function testExpandDirsExcludesFiles(): void
    {
        $matches = GlobExpander::expandDirs($this->tmp . '/plugins/not-a-*');

        self::assertSame([], $matches);
    }

    public function testExpandDirsReturnsEmptyForNoMatches(): void
    {
        self::assertSame([], GlobExpander::expandDirs($this->tmp . '/plugins/nope-*'));
    }

    public function testExpandDirsDoesNotCrossDirectorySeparators(): void
    {
        // "*" must stay within one path segment — plugins/* should not reach into
        // plugins/custom-analytics's own children.
        mkdir($this->tmp . '/plugins/custom-analytics/inc', 0755, true);

        $matches = GlobExpander::expandDirs($this->tmp . '/plugins/*/inc');

        self::assertSame([$this->tmp . '/plugins/custom-analytics/inc'], $matches);
    }

    public function testBaseDirReturnsLongestLiteralPrefix(): void
    {
        self::assertSame('/project/plugins', GlobExpander::baseDir('/project/plugins/custom-*'));
    }

    public function testBaseDirHandlesWildcardInMiddleSegment(): void
    {
        self::assertSame('/project/plugins', GlobExpander::baseDir('/project/plugins/*/inc'));
    }

    public function testBaseDirOfPatternWithNoLiteralPrefixIsRoot(): void
    {
        self::assertSame('/', GlobExpander::baseDir('/*'));
    }

    public function testBaseDirOfPlainPathIsTheWholePath(): void
    {
        self::assertSame('/project/plugins/custom-analytics', GlobExpander::baseDir('/project/plugins/custom-analytics'));
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
