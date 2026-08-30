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

    public function testPrintProgressIsANoOpWhenNotATty(): void
    {
        // Forced explicitly rather than relying on the ambient real stdout — a test run from an
        // actual interactive terminal (as opposed to piped/CI) would otherwise make this
        // environment-dependent instead of a real assertion about the no-op behavior itself; see
        // WP_SPECTER_NO_PROGRESS in phpunit.xml for why the *rest* of the suite doesn't need this
        // per-test (any test driving Application::run() goes through that env var instead).
        $reporter = new TerminalReporter(noColor: true, forceTty: false);
        $output = $this->capture(fn() => $reporter->printProgress('Functions', 1, 10));
        self::assertSame('', $output);
    }

    public function testPrintProgressRendersBarWhenTtyIsForced(): void
    {
        $reporter = new TerminalReporter(noColor: true, forceTty: true);
        $output = $this->capture(fn() => $reporter->printProgress('Functions', 5, 10));

        self::assertStringContainsString('Functions', $output);
        self::assertStringContainsString('50%', $output);
        self::assertStringContainsString('(5/10)', $output);
        self::assertStringContainsString("\r", $output);
    }

    public function testPrintProgressThrottlesRedrawsToOncePerPercentagePoint(): void
    {
        $reporter = new TerminalReporter(noColor: true, forceTty: true);
        $output = $this->capture(function () use ($reporter) {
            // 1000 calls all landing on the same whole percentage (0%) after the first — only
            // the very first call should actually redraw.
            for ($i = 1; $i <= 1000; $i++) {
                $reporter->printProgress('Functions', $i, 1_000_000);
            }
        });

        self::assertSame(1, substr_count($output, 'Functions'));
    }

    public function testPrintProgressAlwaysRendersTheFinalCallEvenAtTheSamePercentage(): void
    {
        $reporter = new TerminalReporter(noColor: true, forceTty: true);
        $output = $this->capture(function () use ($reporter) {
            $reporter->printProgress('Functions', 1, 2);
            $reporter->printProgress('Functions', 2, 2); // same 50%->100% jump, but is the last call
        });

        self::assertSame(2, substr_count($output, 'Functions'));
        self::assertStringContainsString('100%', $output);
    }

    public function testFinishProgressResetsThrottleStateForANewPhase(): void
    {
        $reporter = new TerminalReporter(noColor: true, forceTty: true);
        $output = $this->capture(function () use ($reporter) {
            $reporter->printProgress('Functions', 100, 100); // ends at 100%
            $reporter->finishProgress();
            $reporter->printProgress('Hooks', 0, 100); // a fresh phase, starting at 0%
        });

        self::assertSame(1, substr_count($output, 'Functions'));
        self::assertSame(1, substr_count($output, 'Hooks'));
    }

    public function testFinishProgressPrintsNewlineOnlyWhenProgressWasActuallyDrawn(): void
    {
        $reporter = new TerminalReporter(noColor: true);
        // Never a TTY here — printProgress() was always a no-op, so there's nothing to finish.
        $output = $this->capture(fn() => $reporter->finishProgress());
        self::assertSame('', $output);
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
