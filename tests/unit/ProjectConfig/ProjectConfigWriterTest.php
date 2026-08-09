<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\ProjectConfig;

use PHPUnit\Framework\TestCase;
use WpSpecter\ProjectConfig\BaselineEntry;
use WpSpecter\ProjectConfig\ProjectConfigLoader;
use WpSpecter\ProjectConfig\ProjectConfigWriter;

final class ProjectConfigWriterTest extends TestCase
{
    private string $tmp;
    private ProjectConfigWriter $writer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wp-specter-configwriter-' . uniqid();
        mkdir($this->tmp, 0755, true);
        $this->writer = new ProjectConfigWriter();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testExistsReflectsFilePresence(): void
    {
        self::assertFalse($this->writer->exists($this->tmp));

        $this->writer->writeTargets($this->tmp, [$this->tmp . '/theme']);

        self::assertTrue($this->writer->exists($this->tmp));
    }

    public function testWriteTargetsRelativizesPathsUnderConfigDir(): void
    {
        $this->writer->writeTargets($this->tmp, [$this->tmp . '/wp-content/themes/mytheme']);

        $data = $this->readConfig();
        self::assertSame(['wp-content/themes/mytheme'], $data['targets']);
    }

    public function testWriteBaselineAddsEntriesAsPlainArrays(): void
    {
        $this->writer->writeBaseline($this->tmp, [
            new BaselineEntry('unused_function', 'legacy_helper', 'inc/legacy.php'),
        ]);

        $data = $this->readConfig();
        self::assertSame(
            [['type' => 'unused_function', 'name' => 'legacy_helper', 'file' => 'inc/legacy.php']],
            $data['baseline'],
        );
    }

    public function testWriteBaselinePreservesExistingTargetsAndStubsFrom(): void
    {
        file_put_contents(
            $this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME,
            json_encode(['targets' => ['theme'], 'stubsFrom' => ['vendor-plugins']]),
        );

        $this->writer->writeBaseline($this->tmp, [new BaselineEntry('unused_function', 'f', 'f.php')]);

        $data = $this->readConfig();
        self::assertSame(['theme'], $data['targets']);
        self::assertSame(['vendor-plugins'], $data['stubsFrom']);
        self::assertArrayHasKey('baseline', $data);
    }

    public function testWriteTargetsPreservesExistingBaseline(): void
    {
        file_put_contents(
            $this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME,
            json_encode(['targets' => ['theme'], 'baseline' => [['type' => 'unused_function', 'name' => 'f', 'file' => 'f.php']]]),
        );

        $this->writer->writeTargets($this->tmp, [$this->tmp . '/theme', $this->tmp . '/plugin']);

        $data = $this->readConfig();
        self::assertSame(['theme', 'plugin'], $data['targets']);
        self::assertArrayHasKey('baseline', $data);
    }

    public function testWriteBaselineReplacesRatherThanAppendsExistingBaseline(): void
    {
        $this->writer->writeBaseline($this->tmp, [new BaselineEntry('unused_function', 'old', 'old.php')]);
        $this->writer->writeBaseline($this->tmp, [new BaselineEntry('unused_function', 'new', 'new.php')]);

        $data = $this->readConfig();
        self::assertCount(1, $data['baseline']);
        self::assertSame('new', $data['baseline'][0]['name']);
    }

    /** @return array<string,mixed> */
    private function readConfig(): array
    {
        $raw = file_get_contents($this->tmp . '/' . ProjectConfigLoader::CONFIG_FILENAME);
        self::assertIsString($raw);
        $data = json_decode($raw, true);
        self::assertIsArray($data);
        return $data;
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
