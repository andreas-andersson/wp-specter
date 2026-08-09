<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\ProjectConfig;

use PHPUnit\Framework\TestCase;
use WpSpecter\ProjectConfig\ProjectConfigLoader;

final class ProjectConfigLoaderTest extends TestCase
{
    private string $tmp;
    private ProjectConfigLoader $loader;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-projectconfig-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->loader = new ProjectConfigLoader();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testReturnsNullWithoutConfigFile(): void
    {
        self::assertNull($this->loader->load($this->tmp));
    }

    public function testLoadsTargetsAndResolvesThemRelativeToConfigDir(): void
    {
        mkdir($this->tmp . '/theme', 0755, true);
        $this->writeConfig(['targets' => ['theme']]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertSame([$this->tmp . '/theme'], $config->targets);
    }

    public function testExpandsWildcardTargetsAndSortsThem(): void
    {
        mkdir($this->tmp . '/plugins/custom-seo', 0755, true);
        mkdir($this->tmp . '/plugins/custom-analytics', 0755, true);
        mkdir($this->tmp . '/plugins/vendor-thing', 0755, true);
        $this->writeConfig(['targets' => ['plugins/custom-*']]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertSame(
            [$this->tmp . '/plugins/custom-analytics', $this->tmp . '/plugins/custom-seo'],
            $config->targets,
        );
    }

    public function testWildcardTargetMatchingNothingContributesNoEntries(): void
    {
        mkdir($this->tmp . '/plugins', 0755, true);
        $this->writeConfig(['targets' => ['plugins/custom-*']]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertSame([], $config->targets);
    }

    public function testWildcardAndExactTargetsCanBeCombined(): void
    {
        mkdir($this->tmp . '/theme', 0755, true);
        mkdir($this->tmp . '/plugins/custom-seo', 0755, true);
        $this->writeConfig(['targets' => ['theme', 'plugins/custom-*']]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertSame(
            [$this->tmp . '/theme', $this->tmp . '/plugins/custom-seo'],
            $config->targets,
        );
    }

    public function testWalksUpwardFromNestedPath(): void
    {
        $this->writeConfig(['targets' => []]);
        $nested = $this->tmp . '/a/b/c';
        mkdir($nested, 0755, true);

        $config = $this->loader->load($nested);

        self::assertNotNull($config);
        self::assertSame($this->tmp, $config->configDir);
    }

    public function testLoadsStubsFromList(): void
    {
        mkdir($this->tmp . '/vendor-plugins', 0755, true);
        $this->writeConfig(['stubsFrom' => ['vendor-plugins']]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertSame([$this->tmp . '/vendor-plugins'], $config->stubsFrom);
    }

    public function testStubsPathOverridesDefaultConvention(): void
    {
        $this->writeConfig(['stubs' => 'custom-stubs.json']);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertSame($this->tmp . '/custom-stubs.json', $config->stubsPath);
    }

    public function testStubsPathIsNullWhenNotDeclared(): void
    {
        $this->writeConfig(['targets' => []]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertNull($config->stubsPath);
    }

    public function testLoadsBaselineEntries(): void
    {
        $this->writeConfig([
            'baseline' => [
                ['type' => 'unused_function', 'name' => 'legacy_helper', 'file' => 'inc/legacy.php'],
            ],
        ]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertCount(1, $config->baseline);
        self::assertSame('unused_function', $config->baseline[0]->type);
        self::assertSame('legacy_helper', $config->baseline[0]->name);
        self::assertSame('inc/legacy.php', $config->baseline[0]->file);
    }

    public function testBaselineIsEmptyWhenNotDeclared(): void
    {
        $this->writeConfig(['targets' => []]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertSame([], $config->baseline);
    }

    public function testMalformedBaselineEntriesAreSkipped(): void
    {
        $this->writeConfig([
            'baseline' => [
                ['type' => 'unused_function', 'name' => 'ok', 'file' => 'ok.php'],
                ['type' => 'unused_function', 'name' => 'missing_file'],
                'not an object',
            ],
        ]);

        $config = $this->loader->load($this->tmp);

        self::assertNotNull($config);
        self::assertCount(1, $config->baseline);
        self::assertSame('ok', $config->baseline[0]->name);
    }

    public function testInvalidJsonThrows(): void
    {
        file_put_contents($this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME, 'not json');

        $this->expectException(\RuntimeException::class);
        $this->loader->load($this->tmp);
    }

    public function testFindDefaultStubsFileWalksUpward(): void
    {
        file_put_contents($this->tmp . '/' . ProjectConfigLoader::STUBS_FILENAME, '{"hooks":[]}');
        $nested = $this->tmp . '/a/b';
        mkdir($nested, 0755, true);

        self::assertSame(
            $this->tmp . '/' . ProjectConfigLoader::STUBS_FILENAME,
            $this->loader->findDefaultStubsFile($nested),
        );
    }

    public function testFindDefaultStubsFileReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->loader->findDefaultStubsFile($this->tmp));
    }

    /** @param array<mixed> $data */
    private function writeConfig(array $data): void
    {
        file_put_contents(
            $this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME,
            json_encode($data, JSON_PRETTY_PRINT),
        );
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
