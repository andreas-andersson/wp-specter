<?php

declare(strict_types=1);

namespace WpSpecter\Reporter;

use WpSpecter\Enum\WpMode;
use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Scan\ScanTarget;

final class TerminalReporter
{
    private bool $progressActive = false;
    // Last whole percentage actually drawn, or null at the start of a fresh phase (reset by
    // finishProgress()) — throttles redraws to at most one per percentage point instead of one
    // per file. A multi-thousand-file scan calls this once per file; redrawing the terminal that
    // often buys nothing visually (a human can't perceive it) and costs a real, avoidable syscall
    // per file.
    private ?int $lastRenderedPercent = null;

    /**
     * @param ?bool $forceTty Overrides the real stream_isatty(STDOUT) auto-detection used to
     *   decide whether printProgress() renders anything — null (default) means "detect for
     *   real." Exists only so a test can exercise the actual rendering/throttling logic: real
     *   stdout is never a TTY while PHPUnit captures output, so there'd otherwise be no way to
     *   observe anything printProgress() does.
     */
    public function __construct(private readonly bool $noColor = false, private readonly ?bool $forceTty = null) {}

    /**
     * Renders/updates a single in-place progress bar via `\r` — meant to be passed straight as
     * an analyzer's $onProgress callback (see PhpTokenParser::parseAll()). A no-op whenever
     * stdout isn't a real terminal (piped output, a redirected log file, CI, the test suite's own
     * captured output) — `\r`-driven redraws only make sense in an interactive terminal, and
     * would otherwise just litter a log file with carriage returns.
     */
    public function printProgress(string $label, int $current, int $total): void
    {
        if (!$this->supportsProgress()) {
            return;
        }
        $ratio = $total > 0 ? $current / $total : 1.0;
        $pct = (int) floor($ratio * 100);
        // Always draw the very first call of a phase (so the bar appears immediately rather than
        // waiting for the percentage to first tick over) and the very last (so it visibly
        // reaches 100% instead of freezing one tick early) — otherwise skip a redraw that
        // wouldn't change what's on screen.
        if ($current !== 1 && $current !== $total && $pct === $this->lastRenderedPercent) {
            return;
        }
        $this->lastRenderedPercent = $pct;

        $width = 24;
        $filled = (int) floor($width * $ratio);
        $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);
        // \033[K clears the rest of the line first, so a shorter render (e.g. a smaller total on
        // the next analyzer stage) never leaves stray characters from a longer previous one.
        echo "\r\033[K" . sprintf('  %-11s [%s] %3d%% (%d/%d)', $label, $bar, $pct, $current, $total);
        $this->progressActive = true;
    }

    /** Moves past an in-place progress bar so subsequent normal output starts on its own line. */
    public function finishProgress(): void
    {
        if ($this->progressActive) {
            echo PHP_EOL;
            $this->progressActive = false;
        }
        $this->lastRenderedPercent = null;
    }

    private function supportsProgress(): bool
    {
        return $this->forceTty ?? (\defined('STDOUT') && @stream_isatty(STDOUT));
    }

    public function printHeader(string $path, ?WpMode $mode, int $fileCount): void
    {
        $this->line('');
        $this->line($this->bold('WP-Specter'));
        $this->line($this->dim('  Path:   ' . $path));
        $this->line($this->dim('  Mode:   ' . ($mode?->label() ?? 'unknown')));
        $this->line($this->dim('  Files:  ' . $fileCount . ' PHP files scanned'));
        $this->line('');
    }

    /** @param list<ScanTarget> $targets */
    public function printProjectHeader(string $projectRoot, array $targets, int $fileCount, string $sourceLabel, string $targetsNote): void
    {
        $this->line('');
        $this->line($this->bold('WP-Specter'));
        $this->line($this->dim('  Project: ' . $projectRoot . '  (' . $sourceLabel . ')'));
        $this->line($this->dim('  Targets: ' . count($targets) . ' ' . $targetsNote));
        foreach ($targets as $target) {
            $this->line($this->dim('    - ' . $target->name . '  (' . ($target->mode?->label() ?? 'unknown') . ')'));
        }
        $this->line($this->dim('  Files:   ' . $fileCount . ' PHP files scanned'));
        $this->line('');
    }

    /**
     * @param list<Finding> $findings
     * @param bool $verbose Whether to print verbose output (currently unused)
     * @param list<ScanTarget> $targets The list of scan targets
     * */
    public function printFindings(array $findings, bool $verbose = false, array $targets = []): void
    {
        if (empty($findings)) {
            return;
        }

        $byType = [
            FindingType::UnusedFunction->value => [],
            FindingType::UnmatchedHook->value  => [],
            FindingType::UnusedTemplate->value => [],
            FindingType::UnusedFile->value     => [],
            FindingType::UnusedClass->value    => [],
            FindingType::UnusedMethod->value   => [],
        ];

        foreach ($findings as $finding) {
            $byType[$finding->type->value][] = $finding;
        }

        $sections = [
            FindingType::UnusedFunction->value => 'Unused Functions',
            FindingType::UnmatchedHook->value  => 'Unmatched Hooks',
            FindingType::UnusedTemplate->value => 'Unused Templates',
            FindingType::UnusedFile->value     => 'Unused Files',
            FindingType::UnusedClass->value    => 'Unused Classes',
            FindingType::UnusedMethod->value   => 'Unused Methods',
        ];

        foreach ($sections as $typeVal => $heading) {
            if (empty($byType[$typeVal])) {
                continue;
            }

            $this->line($this->bold($this->underline($heading)));
            $this->line('');

            foreach ($byType[$typeVal] as $finding) {
                $icon = $finding->certainty === FindingCertainty::Error
                    ? $this->red('✗')
                    : $this->yellow('⚠');

                $location = $finding->file . ':' . $finding->line;
                $name     = $this->bold($finding->name);
                $note     = $finding->note ? $this->dim('  // ' . $finding->note) : '';

                $relativePath = $this->dim($location);
                foreach ($targets as $target) {
                    $parts = explode(DIRECTORY_SEPARATOR, $target->path);
                    $pathName = array_pop($parts);
                    if (str_starts_with($finding->file, $target->path)) {
                        $relativePath = $this->dim($pathName . str_replace($target->path, '', $finding->file) . ':' . $finding->line);
                        break;
                    }
                }

                $this->line("  {$icon}  {$name}{$note}");
                $this->line("     {$relativePath}");
                $this->line('');
            }
        }
    }

    /** @param list<Finding> $findings */
    public function printSummary(array $findings, int $suppressedCount = 0): void
    {
        $counts = [
            FindingType::UnusedFunction->value => 0,
            FindingType::UnmatchedHook->value  => 0,
            FindingType::UnusedTemplate->value => 0,
            FindingType::UnusedFile->value     => 0,
            FindingType::UnusedClass->value    => 0,
            FindingType::UnusedMethod->value   => 0,
        ];

        foreach ($findings as $f) {
            $counts[$f->type->value]++;
        }

        $parts = [];
        if ($counts[FindingType::UnusedFunction->value] > 0) {
            $parts[] = $counts[FindingType::UnusedFunction->value] . ' unused function(s)';
        }
        if ($counts[FindingType::UnmatchedHook->value] > 0) {
            $parts[] = $counts[FindingType::UnmatchedHook->value] . ' unmatched hook(s)';
        }
        if ($counts[FindingType::UnusedTemplate->value] > 0) {
            $parts[] = $counts[FindingType::UnusedTemplate->value] . ' unused template(s)';
        }
        if ($counts[FindingType::UnusedFile->value] > 0) {
            $parts[] = $counts[FindingType::UnusedFile->value] . ' unused file(s)';
        }
        if ($counts[FindingType::UnusedClass->value] > 0) {
            $parts[] = $counts[FindingType::UnusedClass->value] . ' unused class(es)';
        }
        if ($counts[FindingType::UnusedMethod->value] > 0) {
            $parts[] = $counts[FindingType::UnusedMethod->value] . ' unused method(s)';
        }

        if (empty($parts)) {
            $this->line($this->green('✓ All clear.'));
        } else {
            $this->line($this->red('Found: ') . implode(', ', $parts));
        }
        if ($suppressedCount > 0) {
            $this->line($this->dim("{$suppressedCount} finding(s) suppressed by baseline"));
        }
        $this->line('');
    }

    private function line(string $text): void
    {
        echo $text . PHP_EOL;
    }

    private function bold(string $text): string
    {
        return $this->ansi("\033[1m", $text, "\033[0m");
    }

    private function underline(string $text): string
    {
        return $this->ansi("\033[4m", $text, "\033[0m");
    }

    private function dim(string $text): string
    {
        return $this->ansi("\033[2m", $text, "\033[0m");
    }

    private function red(string $text): string
    {
        return $this->ansi("\033[31m", $text, "\033[0m");
    }

    private function yellow(string $text): string
    {
        return $this->ansi("\033[33m", $text, "\033[0m");
    }

    private function green(string $text): string
    {
        return $this->ansi("\033[32m", $text, "\033[0m");
    }

    private function ansi(string $open, string $text, string $close): string
    {
        if ($this->noColor) {
            return $text;
        }
        return $open . $text . $close;
    }
}
