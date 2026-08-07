<?php

declare(strict_types=1);

namespace WpSpecter\Reporter;

use WpSpecter\Scan\ScanTarget;
use WpSpecter\Enum\WpMode;
use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;

final class TerminalReporter
{
    public function __construct(private readonly bool $noColor = false) {}

    public function printHeader(string $path, ?WpMode $mode, int $fileCount): void
    {
        $this->line($this->bold('wp-specter') . ' — WordPress unused code scanner');
        $this->line('');
        $this->line('  Path:   ' . $path);
        $this->line('  Mode:   ' . ($mode?->label() ?? 'unknown'));
        $this->line('  Files:  ' . $fileCount . ' PHP files scanned');
        $this->line('');
    }

    /** @param list<ScanTarget> $targets */
    public function printProjectHeader(string $projectRoot, array $targets, int $fileCount, string $sourceLabel, string $targetsNote): void
    {
        $this->line($this->bold('wp-specter') . ' — WordPress unused code scanner');
        $this->line('');
        $this->line('  Project: ' . $projectRoot . $this->dim('  (' . $sourceLabel . ')'));
        $this->line('  Targets: ' . count($targets) . ' ' . $targetsNote);
        foreach ($targets as $target) {
            $this->line('    - ' . $target->name . $this->dim('  (' . ($target->mode?->label() ?? 'unknown') . ')'));
        }
        $this->line('  Files:   ' . $fileCount . ' PHP files scanned');
        $this->line('');
    }

    /** @param list<Finding> $findings */
    public function printFindings(array $findings, bool $verbose = false): void
    {
        if (empty($findings)) {
            $this->line($this->green('  ✓ No issues found.'));
            $this->line('');
            return;
        }

        $byType = [
            FindingType::UnusedFunction->value => [],
            FindingType::UnmatchedHook->value  => [],
            FindingType::UnusedTemplate->value => [],
            FindingType::UnusedFile->value     => [],
        ];

        foreach ($findings as $finding) {
            $byType[$finding->type->value][] = $finding;
        }

        $sections = [
            FindingType::UnusedFunction->value => 'Unused Functions',
            FindingType::UnmatchedHook->value  => 'Unmatched Hooks',
            FindingType::UnusedTemplate->value => 'Unused Templates',
            FindingType::UnusedFile->value     => 'Unused Files',
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

                $location = $this->dim($finding->file . ':' . $finding->line);
                $name     = $this->bold($finding->name);
                $note     = $finding->note ? $this->dim('  // ' . $finding->note) : '';

                $this->line("  {$icon}  {$name}{$note}");
                $this->line("     {$location}");
                $this->line('');
            }
        }
    }

    /** @param list<Finding> $findings */
    public function printSummary(array $findings): void
    {
        $counts = [
            FindingType::UnusedFunction->value => 0,
            FindingType::UnmatchedHook->value  => 0,
            FindingType::UnusedTemplate->value => 0,
            FindingType::UnusedFile->value     => 0,
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

        if (empty($parts)) {
            $this->line($this->green('✓ All clear.'));
        } else {
            $this->line($this->red('Found: ') . implode(', ', $parts));
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
