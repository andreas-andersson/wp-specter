<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\ProjectConfig;

use PHPUnit\Framework\TestCase;
use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\ProjectConfig\BaselineEntry;

final class BaselineEntryTest extends TestCase
{
    public function testMatchesFindingWithSameTypeNameAndRelativeFile(): void
    {
        $entry = new BaselineEntry('unused_function', 'legacy_helper', 'inc/legacy.php');
        $finding = new Finding(
            type: FindingType::UnusedFunction,
            name: 'legacy_helper',
            file: '/project/inc/legacy.php',
            line: 12,
            certainty: FindingCertainty::Warning,
        );

        self::assertTrue($entry->matches($finding, '/project'));
    }

    public function testMatchIgnoresLineNumberDrift(): void
    {
        $entry = new BaselineEntry('unused_function', 'legacy_helper', 'inc/legacy.php');

        // Same finding but reported at a different line — an unrelated edit above it in the
        // file shouldn't break the baseline match.
        $finding = new Finding(
            type: FindingType::UnusedFunction,
            name: 'legacy_helper',
            file: '/project/inc/legacy.php',
            line: 99,
            certainty: FindingCertainty::Warning,
        );

        self::assertTrue($entry->matches($finding, '/project'));
    }

    public function testDoesNotMatchDifferentType(): void
    {
        $entry = new BaselineEntry('unused_function', 'thing', 'inc/thing.php');
        $finding = new Finding(
            type: FindingType::UnusedClass,
            name: 'thing',
            file: '/project/inc/thing.php',
            line: 1,
            certainty: FindingCertainty::Error,
        );

        self::assertFalse($entry->matches($finding, '/project'));
    }

    public function testDoesNotMatchDifferentName(): void
    {
        $entry = new BaselineEntry('unused_function', 'thing', 'inc/thing.php');
        $finding = new Finding(
            type: FindingType::UnusedFunction,
            name: 'other_thing',
            file: '/project/inc/thing.php',
            line: 1,
            certainty: FindingCertainty::Warning,
        );

        self::assertFalse($entry->matches($finding, '/project'));
    }

    public function testDoesNotMatchDifferentFile(): void
    {
        $entry = new BaselineEntry('unused_function', 'thing', 'inc/thing.php');
        $finding = new Finding(
            type: FindingType::UnusedFunction,
            name: 'thing',
            file: '/project/inc/other.php',
            line: 1,
            certainty: FindingCertainty::Warning,
        );

        self::assertFalse($entry->matches($finding, '/project'));
    }

    public function testRelativizeStripsConfigDirPrefix(): void
    {
        self::assertSame('inc/legacy.php', BaselineEntry::relativize('/project/inc/legacy.php', '/project'));
    }

    public function testRelativizeKeepsPathOutsideConfigDirAbsolute(): void
    {
        self::assertSame('/elsewhere/legacy.php', BaselineEntry::relativize('/elsewhere/legacy.php', '/project'));
    }

    public function testRelativizeHandlesConfigDirWithTrailingSlash(): void
    {
        self::assertSame('inc/legacy.php', BaselineEntry::relativize('/project/inc/legacy.php', '/project/'));
    }
}
