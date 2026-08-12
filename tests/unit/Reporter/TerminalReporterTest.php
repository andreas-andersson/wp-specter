<?php

declare(strict_types=1);

namespace WpSpecter\Tests\unit\Reporter;

use PHPUnit\Framework\TestCase;
use WpSpecter\Enum\WpMode;
use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Reporter\TerminalReporter;

final class TerminalReporterTest extends TestCase
{
    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean() ?: '';
    }

    public function testPrintsHeaderWithProjectInfo(): void
    {
        $reporter = new TerminalReporter(noColor: true);
        $output = $this->capture(
            fn() => $reporter->printHeader('/path/to/project', WpMode::Classic, 42),
        );

        self::assertStringContainsString('/path/to/project', $output);
        self::assertStringContainsString('Classic theme', $output);
        self::assertStringContainsString('42', $output);
    }

    public function testPrintsUnusedFunctionFinding(): void
    {
        $reporter = new TerminalReporter(noColor: true);
        $finding = new Finding(
            type: FindingType::UnusedFunction,
            name: 'my_unused_func',
            file: '/theme/functions.php',
            line: 42,
            certainty: FindingCertainty::Error,
        );

        $output = $this->capture(fn() => $reporter->printFindings([$finding]));

        self::assertStringContainsString('my_unused_func', $output);
        self::assertStringContainsString('functions.php:42', $output);
        self::assertStringContainsString('✗', $output);
    }

    public function testPrintsWarningIconForUnmatchedHook(): void
    {
        $reporter = new TerminalReporter(noColor: true);
        $finding = new Finding(
            type: FindingType::UnmatchedHook,
            name: 'my_custom_action',
            file: '/theme/functions.php',
            line: 10,
            certainty: FindingCertainty::Warning,
            note: 'not fired within project',
        );

        $output = $this->capture(fn() => $reporter->printFindings([$finding]));

        self::assertStringContainsString('⚠', $output);
        self::assertStringContainsString('my_custom_action', $output);
        self::assertStringContainsString('not fired within project', $output);
    }

    public function testNoColorStripsAnsiCodes(): void
    {
        $reporterColor = new TerminalReporter(noColor: false);
        $reporterPlain = new TerminalReporter(noColor: true);

        $finding = new Finding(
            type: FindingType::UnusedFunction,
            name: 'foo',
            file: '/f.php',
            line: 1,
            certainty: FindingCertainty::Error,
        );

        $colored = $this->capture(fn() => $reporterColor->printFindings([$finding]));
        $plain   = $this->capture(fn() => $reporterPlain->printFindings([$finding]));

        self::assertStringContainsString("\033[", $colored);
        self::assertStringNotContainsString("\033[", $plain);
    }

    public function testEmptyFindingsPrintsNothing(): void
    {
        $reporter = new TerminalReporter(noColor: true);
        $output = $this->capture(fn() => $reporter->printFindings([]));
        self::assertSame('', $output);
    }

    public function testSummaryShowsCounts(): void
    {
        $reporter = new TerminalReporter(noColor: true);
        $findings = [
            new Finding(FindingType::UnusedFunction, 'a', '/f.php', 1, FindingCertainty::Error),
            new Finding(FindingType::UnusedFunction, 'b', '/f.php', 2, FindingCertainty::Error),
            new Finding(FindingType::UnmatchedHook, 'h', '/f.php', 3, FindingCertainty::Warning),
        ];

        $output = $this->capture(fn() => $reporter->printSummary($findings));

        self::assertStringContainsString('2 unused function(s)', $output);
        self::assertStringContainsString('1 unmatched hook(s)', $output);
    }
}
