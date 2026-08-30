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
     * @param (callable(int, int): void)|null $onProgress See PhpTokenParser::parseAll().
     * @return list<Finding>
     */
    public function analyze(array $files, ?callable $onProgress = null): array
    {
        $parseResults = $this->parser->parseAll($files, $onProgress);

        // Collect all literal tags fired within the project, plus the literal prefix of any
        // dynamic invocation that has one — e.g. apply_filters("acf/settings/{$name}", $value)
        // can't tell us the exact tag, but "acf/settings/" still matches any registration for a
        // hook in that family. This is what lets a single dynamic dispatcher (one call site
        // fanning out to N hook names, a common WP/plugin pattern) be recognized at all instead
        // of making every hook it fires look permanently unmatched.
        // $firedSuffixes is the mirror of $firedPrefixes for the opposite shape — dynamic first,
        // literal last (e.g. "{$this->id_base}_widget_updated" → "_widget_updated") — rarer than
        // the prefix shape in practice (WP convention overwhelmingly puts the static/plugin-
        // specific part first), but real (per-widget-ID or per-post-type hook naming).
        $firedTags = [];
        $firedPrefixes = [];
        $firedSuffixes = [];
        foreach ($parseResults as $result) {
            foreach ($result->hookInvocations as $inv) {
                if ($inv->isDynamic) {
                    if ($inv->tagPrefix !== '') {
                        $firedPrefixes[$inv->tagPrefix] = true;
                    }
                    if ($inv->tagSuffix !== '') {
                        $firedSuffixes[$inv->tagSuffix] = true;
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
                if (
                    isset($firedTags[$reg->tag])
                    || $this->matchesAnyPrefix($reg->tag, $firedPrefixes)
                    || $this->matchesAnySuffix($reg->tag, $firedSuffixes)
                ) {
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

    /** @param array<string,true> $suffixes */
    private function matchesAnySuffix(string $tag, array $suffixes): bool
    {
        foreach ($suffixes as $suffix => $_) {
            if (str_ends_with($tag, $suffix)) {
                return true;
            }
        }
        return false;
    }
}
