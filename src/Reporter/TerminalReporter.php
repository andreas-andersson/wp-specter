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
    public function __construct(private readonly bool $noColor = false) {}

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
            $this->line($this->green('  ✓ No issues found.'));
            $this->line('');
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
    public function printSummary(array $findings): void
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
