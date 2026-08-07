<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\ParseResult;
use WpSpecter\Parser\PhpTokenParser;

final class FunctionAnalyzer
{
    private const WP_PREFIXES = ['wp_', 'get_', 'the_', 'is_', 'has_', 'do_', 'apply_'];
    private const MAGIC_PREFIXES = ['__'];

    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @return list<Finding>
     */
    public function analyze(array $files): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        // Pass 1: collect all definitions (skip class methods and WP-prefixed names)
        $definitions = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if ($def->isMethod || $this->isExcluded($def->name)) {
                    continue;
                }
                $definitions[$def->name] = $def;
            }
        }

        // Pass 2: collect all calls (direct + string callbacks)
        $called = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionCalls as $call) {
                $called[$call->name] = true;
            }
        }

        // Report defined-but-never-called
        $findings = [];
        foreach ($definitions as $name => $def) {
            if (!isset($called[$name])) {
                $findings[] = new Finding(
                    type: FindingType::UnusedFunction,
                    name: $name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Error,
                );
            }
        }

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }

    private function isExcluded(string $name): bool
    {
        if (str_starts_with($name, '__')) {
            return true;
        }
        foreach (self::WP_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
