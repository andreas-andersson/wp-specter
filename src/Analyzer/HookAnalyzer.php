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

        // Collect all literal tags fired within the project
        $firedTags = [];
        $skippedDynamic = [];
        foreach ($parseResults as $result) {
            foreach ($result->hookInvocations as $inv) {
                if ($inv->isDynamic) {
                    $skippedDynamic[] = $inv;
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
                if (!isset($firedTags[$reg->tag])) {
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
        }

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }
}
