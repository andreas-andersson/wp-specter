<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\PhpTokenParser;
use WpSpecter\Stubs\StubRegistry;

final class HookAnalyzer
{
    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @return list<Finding>
     */
    public function analyze(array $files): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        // Collect all literal tags fired within the project, plus the literal prefix of any
        // dynamic invocation that has one — e.g. apply_filters("acf/settings/{$name}", $value)
        // can't tell us the exact tag, but "acf/settings/" still matches any registration for a
        // hook in that family. This is what lets a single dynamic dispatcher (one call site
        // fanning out to N hook names, a common WP/plugin pattern) be recognized at all instead
        // of making every hook it fires look permanently unmatched.
        $firedTags = [];
        $firedPrefixes = [];
        foreach ($parseResults as $result) {
            foreach ($result->hookInvocations as $inv) {
                if ($inv->isDynamic) {
                    if ($inv->tagPrefix !== '') {
                        $firedPrefixes[$inv->tagPrefix] = true;
                    }
                    continue;
                }
                $firedTags[$inv->tag] = true;
            }
        }

        // Report registrations whose tag is not fired within project
        $findings = [];
        foreach ($parseResults as $result) {
            foreach ($result->hookRegistrations as $reg) {
                if ($reg->isDynamic || StubRegistry::contains($reg->tag)) {
                    continue;
                }
                if (isset($firedTags[$reg->tag]) || $this->matchesAnyPrefix($reg->tag, $firedPrefixes)) {
                    continue;
                }
                $findings[] = new Finding(
                    type: FindingType::UnmatchedHook,
                    name: $reg->tag,
                    file: $reg->file,
                    line: $reg->line,
                    certainty: FindingCertainty::Warning,
                    note: 'not fired within scanned directory',
                );
            }
        }

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }

    /** @param array<string,true> $prefixes */
    private function matchesAnyPrefix(string $tag, array $prefixes): bool
    {
        foreach ($prefixes as $prefix => $_) {
            if (str_starts_with($tag, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
